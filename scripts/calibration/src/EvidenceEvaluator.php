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
 * Never fabricates labels. Incomplete / synthetic / undocumented → fail closed (NO-GO).
 */
final class EvidenceEvaluator {

	public const SCHEMA_VERSION = EvidenceDocumentParser::SCHEMA_VERSION;

	public const MIN_LEGIT_NEGATIVE = 400;

	public const MIN_TECHNICAL_SPAM = 200;

	public const MIN_HOLDOUT_FRACTION = 0.20;

	public const MIN_DOUBLE_LABEL_FRACTION = 0.20;

	public const MAX_FALSE_SPAM_WILSON_UCB = 0.01;

	public const MIN_TECHNICAL_SPAM_PRECISION = 0.95;

	/** @var list<string> */
	public const TUPLE_KEYS = EvidenceDocumentParser::TUPLE_KEYS;

	/**
	 * @param array<string,mixed> $doc Evidence document.
	 * @return array<string,mixed> Result with verdict Calibration GO | NO-GO.
	 */
	public static function evaluate( array $doc ): array {
		$parsed   = EvidenceDocumentParser::parse( $doc );
		$errors   = $parsed['errors'];
		$warnings = $parsed['warnings'];
		$metrics  = array();
		$tuple    = $parsed['tuple'];

		$legit = $parsed['rows_by_stratum']['legitimate_negative'];
		$spam  = $parsed['rows_by_stratum']['technical_spam'];
		$mh    = $parsed['rows_by_stratum']['mandatory_human'];
		$excl  = $parsed['rows_by_stratum']['excluded'];

		$legit_n = count( $legit );
		$spam_n  = count( $spam );
		$metrics['legitimate_negative_n'] = $legit_n;
		$metrics['technical_spam_n']      = $spam_n;
		$metrics['mandatory_human_n']     = count( $mh );
		$metrics['excluded_n']            = count( $excl );

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

		$legit_holdout = self::filter_split( $legit, 'holdout' );
		$spam_holdout  = self::filter_split( $spam, 'holdout' );
		$metrics['legitimate_negative_holdout_n'] = count( $legit_holdout );
		$metrics['technical_spam_holdout_n']      = count( $spam_holdout );

		if ( $legit_n > 0 && ( count( $legit_holdout ) / $legit_n ) < self::MIN_HOLDOUT_FRACTION ) {
			$errors[] = 'legitimate_negative holdout fraction < 20%';
		}
		if ( $spam_n > 0 && ( count( $spam_holdout ) / $spam_n ) < self::MIN_HOLDOUT_FRACTION ) {
			$errors[] = 'technical_spam holdout fraction < 20%';
		}

		$primary_rows = array_merge( $legit, $spam );
		$double_n     = 0;
		foreach ( $primary_rows as $row ) {
			if ( ! empty( $row['double_labelled'] ) ) {
				++$double_n;
			}
		}
		$metrics['double_labelled_n'] = $double_n;
		$total_primary                = count( $primary_rows );
		if ( $total_primary > 0 && ( $double_n / $total_primary ) < self::MIN_DOUBLE_LABEL_FRACTION ) {
			$errors[] = 'blind double-label overlap < 20% of combined primary corpus';
		}
		if ( $total_primary > 0 && 0 === $double_n ) {
			$errors[] = 'missing double-label evidence on primary corpus';
		}

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

		$would_act_holdout   = 0;
		$would_act_true_spam = 0;
		$mandatory_would_act = 0;
		$combined_holdout    = array_merge( $legit_holdout, $spam_holdout );
		foreach ( $combined_holdout as $row ) {
			$code_mh = WouldActEvaluator::mandatory_human_codes_present( $row['assessment'] );
			$act     = WouldActEvaluator::would_act( $row['assessment'] );
			if ( $act && count( $code_mh ) > 0 ) {
				++$mandatory_would_act;
			}
			if ( $act ) {
				++$would_act_holdout;
				if ( ( $row['human_label'] ?? '' ) === 'technical_spam' ) {
					++$would_act_true_spam;
				}
			}
		}
		foreach ( $mh as $row ) {
			if ( WouldActEvaluator::would_act( $row['assessment'] ) ) {
				++$mandatory_would_act;
			}
		}

		$metrics['would_act_holdout_n']           = $would_act_holdout;
		$metrics['would_act_true_spam_holdout_n'] = $would_act_true_spam;
		$metrics['mandatory_human_would_act_n']  = $mandatory_would_act;

		if ( $mandatory_would_act > 0 ) {
			$errors[] = 'would-act rows with mandatory-human labels/codes must be zero';
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
			$metrics['technical_spam_precision_holdout'] = null;
			$errors[] = 'no would-act rows on holdout; technical-spam precision floor not demonstrable';
		}

		if ( ! $parsed['go_eligible_status'] ) {
			$errors[] = 'evidence_status is not authorised_labelled; Calibration GO forbidden';
		}

		$errors = array_values( array_unique( $errors ) );

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
