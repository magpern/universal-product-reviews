<?php
/**
 * Privacy-safe M12 calibration / simulation evidence document parser.
 *
 * Offline only. Distinguishes Simulation GO vs Calibration GO eligibility.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Calibration;

/**
 * Parses and structurally validates an m12-cal-v1 document before metric gates.
 */
final class EvidenceDocumentParser {

	public const SCHEMA_VERSION = 'm12-cal-v1';

	/** Status values that can never produce Simulation GO or Calibration GO. */
	public const NON_GO_STATUSES = array(
		'template',
		'example',
		'synthetic',
		'incomplete',
		'draft',
	);

	/** Real-world human-labelled evidence — Calibration GO only. */
	public const CALIBRATION_ELIGIBLE_STATUS = 'authorised_labelled';

	/** AI/synthetic privacy-safe fixtures — Simulation GO only (never Calibration GO). */
	public const SIMULATION_ELIGIBLE_STATUS = 'synthetic_simulation';

	/** @deprecated Use CALIBRATION_ELIGIBLE_STATUS. */
	public const GO_ELIGIBLE_STATUS = self::CALIBRATION_ELIGIBLE_STATUS;

	/** @var list<string> */
	public const HUMAN_LABELS = array(
		'not_spam',
		'technical_spam',
		'mandatory_human',
		'excluded',
	);

	/** @var list<string> */
	public const PRIMARY_STRATA = array(
		'legitimate_negative',
		'technical_spam',
	);

	/** @var list<string> */
	public const ALL_STRATA = array(
		'legitimate_negative',
		'technical_spam',
		'mandatory_human',
		'excluded',
	);

	/** @var list<string> */
	public const FORBIDDEN_CONTENT_KEYS = array(
		'review_body',
		'body',
		'comment_content',
		'content',
		'email',
		'customer_name',
		'order_id',
		'ip',
		'ip_address',
		'token',
		'url',
		'api_key',
		'credential',
	);

	/** @var list<string> */
	public const PROVENANCE_REQUIRED = array(
		'dataset_id',
		'authorising_party',
		'consent_or_authorisation_ref',
		'source_class',
		'labelled_at',
		'reviewer_count',
	);

	/** @var list<string> */
	public const TUPLE_KEYS = array(
		'provider_kind',
		'assessor_version',
		'heuristic_or_model_fingerprint',
		'validator_version',
		'assessment_policy_version',
		'recommendation_policy_version',
		'action_policy_version',
	);

	/**
	 * @param array<string,mixed> $doc Raw document.
	 * @return array{
	 *   ok: bool,
	 *   errors: list<string>,
	 *   warnings: list<string>,
	 *   calibration_eligible: bool,
	 *   simulation_eligible: bool,
	 *   go_eligible_status: bool,
	 *   evidence_status: string,
	 *   rows_by_stratum: array<string, list<array<string,mixed>>>,
	 *   tuple: array<string,mixed>|null,
	 *   provenance: array<string,mixed>|null,
	 *   holdout_lock: array<string,mixed>|null,
	 *   dataset_hashes: array<string,mixed>|null
	 * }
	 */
	public static function parse( array $doc ): array {
		$errors   = array();
		$warnings = array();

		if ( ( $doc['schema_version'] ?? '' ) !== self::SCHEMA_VERSION ) {
			$errors[] = 'schema_version must be ' . self::SCHEMA_VERSION;
		}

		$status = (string) ( $doc['evidence_status'] ?? '' );
		if ( '' === $status ) {
			$errors[] = 'evidence_status missing';
		} elseif ( in_array( $status, self::NON_GO_STATUSES, true ) ) {
			$errors[] = "evidence_status '{$status}' cannot produce Simulation GO or Calibration GO (use synthetic_simulation or authorised_labelled)";
		} elseif (
			self::CALIBRATION_ELIGIBLE_STATUS !== $status
			&& self::SIMULATION_ELIGIBLE_STATUS !== $status
		) {
			$errors[] = "evidence_status must be '" . self::CALIBRATION_ELIGIBLE_STATUS . "', '" . self::SIMULATION_ELIGIBLE_STATUS . "', or an explicitly non-GO status; got '{$status}'";
		}

		$calibration_eligible = self::CALIBRATION_ELIGIBLE_STATUS === $status;
		$simulation_eligible  = self::SIMULATION_ELIGIBLE_STATUS === $status;
		$go_eligible_status   = $calibration_eligible; // back-compat alias

		if ( empty( $doc['holdout_locked_before_tuning'] ) ) {
			$errors[] = 'holdout_locked_before_tuning must be true';
		}

		$tuple = $doc['tuple'] ?? null;
		if ( ! is_array( $tuple ) ) {
			$errors[] = 'tuple missing';
			$tuple    = null;
		} else {
			foreach ( self::TUPLE_KEYS as $key ) {
				if ( ! isset( $tuple[ $key ] ) || ! is_string( $tuple[ $key ] ) || '' === $tuple[ $key ] ) {
					$errors[] = "tuple.{$key} missing or empty";
				} elseif ( self::is_placeholder_value( (string) $tuple[ $key ] ) ) {
					$errors[] = "tuple.{$key} is a placeholder/example value; not eligible for Simulation GO or Calibration GO";
				}
			}
			if ( isset( $tuple['provider_kind'] ) && ! in_array( (string) $tuple['provider_kind'], array( 'local', 'openai' ), true ) ) {
				$errors[] = "tuple.provider_kind must be 'local' or 'openai'";
			}
		}

		$provenance = $doc['provenance'] ?? null;
		if ( ! is_array( $provenance ) ) {
			$errors[]   = 'provenance missing';
			$provenance = null;
		} else {
			foreach ( self::PROVENANCE_REQUIRED as $key ) {
				if ( ! isset( $provenance[ $key ] ) || ( is_string( $provenance[ $key ] ) && '' === $provenance[ $key ] ) ) {
					$errors[] = "provenance.{$key} missing or empty";
				}
			}
			if ( isset( $provenance['source_class'] )
				&& ! in_array( (string) $provenance['source_class'], array( 'synthetic_authorised', 'operator_authorised_historical', 'third_party_licensed' ), true )
			) {
				$errors[] = 'provenance.source_class unrecognised';
			}
			if ( $simulation_eligible
				&& isset( $provenance['source_class'] )
				&& 'synthetic_authorised' !== (string) $provenance['source_class']
			) {
				$errors[] = "synthetic_simulation requires provenance.source_class 'synthetic_authorised'";
			}
			if ( $calibration_eligible
				&& isset( $provenance['source_class'] )
				&& 'synthetic_authorised' === (string) $provenance['source_class']
			) {
				$errors[] = "authorised_labelled Calibration GO forbids provenance.source_class 'synthetic_authorised' (use operator_authorised_historical or third_party_licensed)";
			}
			if ( isset( $provenance['reviewer_count'] ) && ( ! is_int( $provenance['reviewer_count'] ) || $provenance['reviewer_count'] < 1 ) ) {
				$errors[] = 'provenance.reviewer_count must be a positive integer';
			}
			if ( $calibration_eligible
				&& isset( $provenance['reviewer_count'] )
				&& is_int( $provenance['reviewer_count'] )
				&& $provenance['reviewer_count'] < 2
			) {
				$errors[] = 'Calibration GO requires provenance.reviewer_count >= 2';
			}
		}

		$holdout_lock = $doc['holdout_lock'] ?? null;
		if ( ! is_array( $holdout_lock ) ) {
			$errors[]     = 'holdout_lock missing';
			$holdout_lock = null;
		} else {
			if ( empty( $holdout_lock['locked_before_labelling_complete'] ) && empty( $holdout_lock['locked_before_tuning'] ) ) {
				$errors[] = 'holdout_lock must assert locked_before_labelling_complete or locked_before_tuning';
			}
			if ( empty( $holdout_lock['assignment_sha256'] ) || ! is_string( $holdout_lock['assignment_sha256'] ) ) {
				$errors[] = 'holdout_lock.assignment_sha256 missing';
			} elseif ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $holdout_lock['assignment_sha256'] ) ) {
				$errors[] = 'holdout_lock.assignment_sha256 must be lowercase sha256 hex';
			} elseif ( preg_match( '/^0{64}$/', (string) $holdout_lock['assignment_sha256'] ) ) {
				$errors[] = 'holdout_lock.assignment_sha256 must not be the all-zero placeholder';
			}
		}

		$dataset_hashes = $doc['dataset_hashes'] ?? null;
		if ( ! is_array( $dataset_hashes ) ) {
			$errors[]       = 'dataset_hashes missing';
			$dataset_hashes = null;
		} else {
			foreach ( array( 'rows_sha256', 'labels_sha256', 'assessments_sha256' ) as $hk ) {
				if ( empty( $dataset_hashes[ $hk ] ) || ! is_string( $dataset_hashes[ $hk ] ) ) {
					$errors[] = "dataset_hashes.{$hk} missing";
				} elseif ( ! preg_match( '/^[a-f0-9]{64}$/', (string) $dataset_hashes[ $hk ] ) ) {
					$errors[] = "dataset_hashes.{$hk} must be lowercase sha256 hex";
				} elseif ( preg_match( '/^0{64}$/', (string) $dataset_hashes[ $hk ] ) ) {
					$errors[] = "dataset_hashes.{$hk} must not be the all-zero placeholder";
				}
			}
		}

		$privacy = $doc['privacy'] ?? null;
		if ( ! is_array( $privacy ) ) {
			$errors[] = 'privacy block missing';
		} else {
			if ( ! array_key_exists( 'review_bodies_committed', $privacy ) || true === $privacy['review_bodies_committed'] ) {
				$errors[] = 'privacy.review_bodies_committed must be false';
			}
			if ( ! array_key_exists( 'secrets_committed', $privacy ) || true === $privacy['secrets_committed'] ) {
				$errors[] = 'privacy.secrets_committed must be false';
			}
			if ( ! array_key_exists( 'customer_identifiers_committed', $privacy ) || true === $privacy['customer_identifiers_committed'] ) {
				$errors[] = 'privacy.customer_identifiers_committed must be false';
			}
		}

		$strata_raw = $doc['strata'] ?? null;
		$rows_by    = array(
			'legitimate_negative' => array(),
			'technical_spam'      => array(),
			'mandatory_human'     => array(),
			'excluded'            => array(),
		);

		if ( ! is_array( $strata_raw ) ) {
			$errors[] = 'strata missing';
		} else {
			foreach ( self::PRIMARY_STRATA as $required ) {
				if ( ! isset( $strata_raw[ $required ] ) || ! is_array( $strata_raw[ $required ] ) ) {
					$errors[] = "strata.{$required} missing";
				}
			}

			$seen_ids = array();
			foreach ( self::ALL_STRATA as $stratum ) {
				if ( ! isset( $strata_raw[ $stratum ] ) ) {
					continue;
				}
				$block = $strata_raw[ $stratum ];
				if ( ! is_array( $block ) || ! isset( $block['rows'] ) || ! is_array( $block['rows'] ) ) {
					$errors[] = "strata.{$stratum}.rows missing";
					continue;
				}
				foreach ( $block['rows'] as $i => $row ) {
					if ( ! is_array( $row ) ) {
						$errors[] = "strata.{$stratum}.rows[{$i}] not an object";
						continue;
					}
					foreach ( self::FORBIDDEN_CONTENT_KEYS as $bad ) {
						if ( array_key_exists( $bad, $row ) ) {
							$errors[] = "strata.{$stratum}.rows[{$i}] must not include forbidden field '{$bad}'";
						}
					}
					$id = isset( $row['id'] ) ? (string) $row['id'] : '';
					if ( '' === $id || ! preg_match( '/^[A-Za-z0-9_-]{8,128}$/', $id ) ) {
						$errors[] = "strata.{$stratum}.rows[{$i}] id must be opaque [A-Za-z0-9_-]{8,128}";
						continue;
					}
					if ( isset( $seen_ids[ $id ] ) ) {
						$errors[] = "duplicate sample id '{$id}' (also in {$seen_ids[ $id ]})";
						continue;
					}
					$seen_ids[ $id ] = $stratum;

					$label = (string) ( $row['human_label'] ?? '' );
					if ( ! in_array( $label, self::HUMAN_LABELS, true ) ) {
						$errors[] = "strata.{$stratum}.rows[{$i}] unrecognised human_label '{$label}'";
						continue;
					}
					$expected = self::expected_label_for_stratum( $stratum );
					if ( $label !== $expected ) {
						$errors[] = "strata.{$stratum}.rows[{$i}] human_label must be {$expected}";
						continue;
					}

					$split = (string) ( $row['split'] ?? '' );
					if ( ! in_array( $split, array( 'train', 'holdout' ), true ) ) {
						$errors[] = "strata.{$stratum}.rows[{$i}] split must be train|holdout";
						continue;
					}

					if ( ! isset( $row['assessment'] ) || ! is_array( $row['assessment'] ) ) {
						$errors[] = "strata.{$stratum}.rows[{$i}] assessment missing";
						continue;
					}
					foreach ( self::FORBIDDEN_CONTENT_KEYS as $bad ) {
						if ( array_key_exists( $bad, $row['assessment'] ) ) {
							$errors[] = "strata.{$stratum}.rows[{$i}].assessment must not include forbidden field '{$bad}'";
						}
					}

					if ( ! empty( $row['double_labelled'] ) ) {
						$dl = $row['double_label'] ?? null;
						if ( ! is_array( $dl ) ) {
							$errors[] = "strata.{$stratum}.rows[{$i}] double_labelled requires double_label object";
							continue;
						}
						foreach ( array( 'labeler_a', 'labeler_b', 'label_a', 'label_b' ) as $dk ) {
							if ( empty( $dl[ $dk ] ) || ! is_string( $dl[ $dk ] ) ) {
								$errors[] = "strata.{$stratum}.rows[{$i}].double_label.{$dk} missing";
							}
						}
						if ( isset( $dl['label_a'], $dl['label_b'] )
							&& (string) $dl['label_a'] !== (string) $dl['label_b']
						) {
							if ( empty( $dl['adjudicated_label'] ) || ! is_string( $dl['adjudicated_label'] ) ) {
								$errors[] = "strata.{$stratum}.rows[{$i}].double_label disagreement requires adjudicated_label";
							} elseif ( ! in_array( (string) $dl['adjudicated_label'], self::HUMAN_LABELS, true ) ) {
								$errors[] = "strata.{$stratum}.rows[{$i}].double_label.adjudicated_label unrecognised";
							}
							if ( empty( $dl['adjudicator'] ) || ! is_string( $dl['adjudicator'] ) ) {
								$errors[] = "strata.{$stratum}.rows[{$i}].double_label disagreement requires adjudicator";
							}
						}
					}

					$rows_by[ $stratum ][] = $row;
				}
			}

			// Holdout contamination: assignment must match locked map when provided.
			if ( is_array( $holdout_lock ) && isset( $holdout_lock['assignments'] ) && is_array( $holdout_lock['assignments'] ) ) {
				$assignments = $holdout_lock['assignments'];
				foreach ( $rows_by as $stratum => $rows ) {
					foreach ( $rows as $row ) {
						$id = (string) $row['id'];
						if ( ! isset( $assignments[ $id ] ) ) {
							$errors[] = "holdout contamination: sample '{$id}' missing from holdout_lock.assignments";
							continue;
						}
						$locked_split = (string) $assignments[ $id ];
						if ( $locked_split !== (string) $row['split'] ) {
							$errors[] = "holdout contamination: sample '{$id}' split '{$row['split']}' != locked '{$locked_split}'";
						}
					}
				}
				foreach ( $assignments as $aid => $asplit ) {
					$found = false;
					foreach ( $rows_by as $rows ) {
						foreach ( $rows as $row ) {
							if ( (string) $row['id'] === (string) $aid ) {
								$found = true;
								break 2;
							}
						}
					}
					if ( ! $found ) {
						$warnings[] = "holdout_lock.assignments contains id '{$aid}' with no row";
					}
					unset( $asplit );
				}
			}
		}

		return array(
			'ok'                   => 0 === count( $errors ),
			'errors'               => $errors,
			'warnings'             => $warnings,
			'calibration_eligible' => $calibration_eligible,
			'simulation_eligible'  => $simulation_eligible,
			'go_eligible_status'   => $go_eligible_status,
			'evidence_status'      => $status,
			'rows_by_stratum'      => $rows_by,
			'tuple'                => $tuple,
			'provenance'           => $provenance,
			'holdout_lock'         => $holdout_lock,
			'dataset_hashes'       => $dataset_hashes,
		);
	}

	private static function expected_label_for_stratum( string $stratum ): string {
		switch ( $stratum ) {
			case 'legitimate_negative':
				return 'not_spam';
			case 'technical_spam':
				return 'technical_spam';
			case 'mandatory_human':
				return 'mandatory_human';
			case 'excluded':
			default:
				return 'excluded';
		}
	}

	private static function is_placeholder_value( string $value ): bool {
		$v = strtolower( $value );
		return false !== strpos( $v, 'example' )
			|| false !== strpos( $v, 'unset' )
			|| false !== strpos( $v, 'placeholder' )
			|| false !== strpos( $v, 'todo' )
			|| false !== strpos( $v, 'replace_with' )
			|| 't' === $v;
	}
}
