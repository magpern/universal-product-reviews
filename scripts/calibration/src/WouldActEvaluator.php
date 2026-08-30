<?php
/**
 * Deterministic would-act evaluator for frozen auto_spam_held_technical conjunction.
 *
 * Offline harness only — mirrors M11 RecommendationPolicy likely_spam path.
 * Does not mutate WordPress, call providers, or load credentials.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Calibration;

use UniversalProductReviews\Ai\Recommendation;
use UniversalProductReviews\Ai\RecommendationPolicy;

/**
 * Maps privacy-safe assessment field maps to would-act boolean.
 */
final class WouldActEvaluator {

	public const CONTRACT_ID = 'auto_spam_held_technical';

	/**
	 * True only when RecommendationPolicy suggests exactly likely_spam
	 * (completed ∧ high ∧ risk≥80 ∧ spam-family ∧ ¬mandatory-human).
	 *
	 * @param array<string,mixed> $assessment Privacy-safe assessment fields.
	 */
	public static function would_act( array $assessment ): bool {
		$rec = RecommendationPolicy::suggest( $assessment );
		return Recommendation::ACTION_LIKELY_SPAM === $rec->action;
	}

	/**
	 * @param array<string,mixed> $assessment Assessment fields.
	 * @return list<string>
	 */
	public static function mandatory_human_codes_present( array $assessment ): array {
		$codes = self::parse_codes( $assessment['reason_codes'] ?? null );
		return array_values( array_intersect( $codes, RecommendationPolicy::MANDATORY_HUMAN_CODES ) );
	}

	/**
	 * @param mixed $raw Codes.
	 * @return list<string>
	 */
	private static function parse_codes( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $c ) {
			if ( is_string( $c ) && '' !== $c ) {
				$out[] = $c;
			}
		}
		return $out;
	}
}
