<?php
/**
 * Privacy-safe M12 calibration evidence evaluator (offline).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Calibration;

/**
 * Evaluates a labelled evidence document against frozen M12 calibration gates.
 *
 * Never fabricates labels. Missing/insufficient corpus → fail closed (NO-GO).
 */
final class EvidenceEvaluator {

	public const SCHEMA_VERSION = 'm12-cal-v1';

	public const MIN_LEGIT_NEGATIVE = 400;

	public const MIN_TECHNICAL_SPAM = 200;

	public const MIN_HOLDOUT_FRACTION = 0.20;

	public const MIN_DOUBLE_LABEL_FRACTION = 0.20;

	public const MAX_FALSE_SPAM_WILSON_UCB = 0.01;

	public const MIN_TECHNICAL_SPAM_PRECISION = 0.95;

	/**
	 * Required calibrated tuple keys (immutable approval surface).
	 *
	 * @var list<string>
	 */
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
	 * @param array<string,mixed> $doc Evidence document.
	 * @return array<string,mixed> Result with verdict Calibration GO | NO-GO.
	 */
	public static function evaluate( array $doc ): array {
		$errors   = array();
		$metrics  = array();
		$warnings = array();

		if ( ( $doc['schema_version'] ?? '' ) !== self::SCHEMA_VERSION ) {
			$errors[] = 'schema_version must be ' . self::SCHEMA_VERSION;
		}

		$tuple = $doc['tuple'] ?? null;
		if ( ! is_array( $tuple ) ) {
			$errors[] = 'tuple missing';
		} else {
			foreach ( self::TUPLE_KEYS as $key ) {
				if ( ! isset( $tuple[ $key ] ) || ! is_string( $tuple[ $key ] ) || '' === $tuple[ $key ] ) {
					$errors[] = "tuple.{$key} missing or empty";
				}
			}
		}

		$strata = $doc['strata'] ?? null;
		if ( ! is_array( $strata ) ) {
			$errors[] = 'strata missing';
			return self::nogo( $errors, $metrics, $warnings, $tuple );
		}

		$legit = self::normalise_stratum( $strata['legitimate_negative'] ?? null, 'legitimate_negative', $errors );
		$spam  = self::normalise_stratum( $strata['technical_spam'] ?? null, 'technical_spam', $errors );

		if ( count( $errors ) > 0 ) {
			return self::nogo( $errors, $metrics, $warnings, $tuple );
		}

		$legit_n = count( $legit['rows'] );
		$spam_n  = count( $spam['rows'] );
		$metrics['legitimate_negative_n'] = $legit_n;
		$metrics['technical_spam_n']      = $spam_n;

		if ( $legit_n < self::MIN_LEGIT_NEGATIVE ) {
			$errors[] = sprintf(
				'legitimate_negative corpus size %d < required %d',
				$legit_n,
				self::MIN_LEGIT_NEGATIVE
			);
		}
		if ( $spam_n < self::MIN_TECHNICAL_SPAM ) {
			$errors[] = sprintf(
				'technical_spam corpus size %d < required %d',
				$spam_n,
				self::MIN_TECHNICAL_SPAM
			);
		}

		$legit_holdout = self::filter_split( $legit['rows'], 'holdout' );
		$spam_holdout  = self::filter_split( $spam['rows'], 'holdout' );
		$metrics['legitimate_negative_holdout_n'] = count( $legit_holdout );
		$metrics['technical_spam_holdout_n']      = count( $spam_holdout );

		if ( $legit_n > 0 && ( count( $legit_holdout ) / $legit_n ) < self::MIN_HOLDOUT_FRACTION ) {
			$errors[] = 'legitimate_negative holdout fraction < 20%';
		}
		if ( $spam_n > 0 && ( count( $spam_holdout ) / $spam_n ) < self::MIN_HOLDOUT_FRACTION ) {
			$errors[] = 'technical_spam holdout fraction < 20%';
		}

		$all_rows = array_merge( $legit['rows'], $spam['rows'] );
		$double_n = 0;
		foreach ( $all_rows as $row ) {
			if ( ! empty( $row['double_labelled'] ) ) {
				++$double_n;
			}
		}
		$metrics['double_labelled_n'] = $double_n;
		$total_n = count( $all_rows );
		if ( $total_n > 0 && ( $double_n / $total_n ) < self::MIN_DOUBLE_LABEL_FRACTION ) {
			$errors[] = 'blind double-label overlap < 20% of combined corpus';
		}

		if ( empty( $doc['holdout_locked_before_tuning'] ) ) {
			$errors[] = 'holdout_locked_before_tuning must be true';
		}

		// Holdout metrics only.
		$false_spam = 0;
		foreach ( $legit_holdout as $row ) {
			if ( WouldActEvaluator::would_act( $row['assessment'] ) ) {
				++$false_spam;
			}
		}
		$metrics['false_spam_holdout_count'] = $false_spam;
		$legit_h_n = count( $legit_holdout );
		if ( $legit_h_n > 0 ) {
			$ucb = WilsonInterval::upper_bound_95( $false_spam, $legit_h_n );
			$metrics['false_spam_wilson_ucb_95'] = $ucb;
			if ( $ucb > self::MAX_FALSE_SPAM_WILSON_UCB ) {
				$errors[] = sprintf(
					'false-spam Wilson 95%% UCB %.6f > %.4f on legitimate-negative holdout',
					$ucb,
					self::MAX_FALSE_SPAM_WILSON_UCB
				);
			}
		} else {
			$errors[] = 'legitimate_negative holdout empty; cannot compute Wilson gate';
		}

		$would_act_holdout = 0;
		$would_act_true_spam = 0;
		$mandatory_would_act = 0;
		$combined_holdout = array_merge( $legit_holdout, $spam_holdout );
		foreach ( $combined_holdout as $row ) {
			$mh = WouldActEvaluator::mandatory_human_codes_present( $row['assessment'] );
			$act = WouldActEvaluator::would_act( $row['assessment'] );
			if ( $act && count( $mh ) > 0 ) {
				++$mandatory_would_act;
			}
			if ( $act ) {
				++$would_act_holdout;
				if ( ( $row['human_label'] ?? '' ) === 'technical_spam' ) {
					++$would_act_true_spam;
				}
			}
		}
		$metrics['would_act_holdout_n']           = $would_act_holdout;
		$metrics['would_act_true_spam_holdout_n'] = $would_act_true_spam;
		$metrics['mandatory_human_would_act_n']  = $mandatory_would_act;

		if ( $mandatory_would_act > 0 ) {
			$errors[] = 'would-act rows with mandatory-human codes must be zero';
		}

		if ( $would_act_holdout > 0 ) {
			$precision = $would_act_true_spam / $would_act_holdout;
			$metrics['technical_spam_precision_holdout'] = $precision;
			if ( $precision < self::MIN_TECHNICAL_SPAM_PRECISION ) {
				$errors[] = sprintf(
					'technical-spam precision %.4f < required %.2f on holdout would-act set',
					$precision,
					self::MIN_TECHNICAL_SPAM_PRECISION
				);
			}
		} else {
			// No would-act on holdout: precision undefined; fail closed for Calibration GO
			// (cannot demonstrate technical-spam precision floor).
			$metrics['technical_spam_precision_holdout'] = null;
			$errors[] = 'no would-act rows on holdout; technical-spam precision floor not demonstrable';
		}

		if ( count( $errors ) > 0 ) {
			return self::nogo( $errors, $metrics, $warnings, $tuple );
		}

		return array(
			'verdict'  => 'Calibration GO',
			'contract' => WouldActEvaluator::CONTRACT_ID,
			'tuple'    => $tuple,
			'metrics'  => $metrics,
			'errors'   => array(),
			'warnings' => $warnings,
		);
	}

	/**
	 * @param mixed               $raw    Stratum.
	 * @param string              $name   Name.
	 * @param list<string>        $errors Errors (by ref).
	 * @return array{rows: list<array<string,mixed>>}
	 */
	private static function normalise_stratum( $raw, string $name, array &$errors ): array {
		if ( ! is_array( $raw ) || ! isset( $raw['rows'] ) || ! is_array( $raw['rows'] ) ) {
			$errors[] = "strata.{$name}.rows missing";
			return array( 'rows' => array() );
		}
		$rows = array();
		foreach ( $raw['rows'] as $i => $row ) {
			if ( ! is_array( $row ) ) {
				$errors[] = "strata.{$name}.rows[{$i}] not an object";
				continue;
			}
			if ( isset( $row['review_body'] ) || isset( $row['body'] ) || isset( $row['comment_content'] ) ) {
				$errors[] = "strata.{$name}.rows[{$i}] must not include review body / PII content fields";
				continue;
			}
			$label = (string) ( $row['human_label'] ?? '' );
			$expected = 'legitimate_negative' === $name ? 'not_spam' : 'technical_spam';
			if ( $label !== $expected ) {
				$errors[] = "strata.{$name}.rows[{$i}] human_label must be {$expected}";
				continue;
			}
			$split = (string) ( $row['split'] ?? '' );
			if ( ! in_array( $split, array( 'train', 'holdout' ), true ) ) {
				$errors[] = "strata.{$name}.rows[{$i}] split must be train|holdout";
				continue;
			}
			if ( ! isset( $row['assessment'] ) || ! is_array( $row['assessment'] ) ) {
				$errors[] = "strata.{$name}.rows[{$i}] assessment missing";
				continue;
			}
			$rows[] = $row;
		}
		return array( 'rows' => $rows );
	}

	/**
	 * @param list<array<string,mixed>> $rows Rows.
	 * @return list<array<string,mixed>>
	 */
	private static function filter_split( array $rows, string $split ): array {
		$out = array();
		foreach ( $rows as $row ) {
			if ( ( $row['split'] ?? '' ) === $split ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * @param list<string>             $errors   Errors.
	 * @param array<string,mixed>      $metrics  Metrics.
	 * @param list<string>             $warnings Warnings.
	 * @param array<string,mixed>|null $tuple    Tuple.
	 * @return array<string,mixed>
	 */
	private static function nogo( array $errors, array $metrics, array $warnings, $tuple ): array {
		return array(
			'verdict'  => 'NO-GO',
			'contract' => WouldActEvaluator::CONTRACT_ID,
			'tuple'    => is_array( $tuple ) ? $tuple : null,
			'metrics'  => $metrics,
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}
}
