<?php
/**
 * M11 deterministic recommendation policy (risk-direction).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Maps validated terminal assessment fields to allowlisted suggested_action.
 *
 * publication_safety_score is a RISK score: higher = greater publication risk.
 */
final class RecommendationPolicy {

	public const RECOMMENDATION_POLICY_VERSION = '2026-08-rec-v1';

	/** High-risk threshold (inclusive). */
	public const RISK_HIGH_MIN = 80;

	/** Low-risk threshold for likely_publishable (inclusive). */
	public const RISK_LOW_MAX = 40;

	/** @var list<string> */
	public const MANDATORY_HUMAN_CODES = array(
		'pii_suspected',
		'medical_claim_suspected',
		'regulatory_claim_suspected',
		'safety_claim_suspected',
	);

	/** @var list<string> */
	public const SPAM_FAMILY_CODES = array(
		'spam_pattern',
		'link_abuse',
		'fraud_suspected',
		'impersonation_suspected',
	);

	/** @var list<string> */
	public const ABUSE_FAMILY_CODES = array(
		'abuse_harassment',
		'threat_suspected',
		'hate_suspected',
	);

	/**
	 * Derive recommendation from a terminal assessment row (ARRAY_A) or field map.
	 *
	 * @param mixed $assessment Assessment row or null.
	 */
	public static function suggest( $assessment ): Recommendation {
		if ( ! is_array( $assessment ) ) {
			return self::needs_human( array() );
		}

		$state = isset( $assessment['state'] ) ? (string) $assessment['state'] : '';
		if ( in_array( $state, array( 'failed', 'skipped', 'indeterminate' ), true ) ) {
			return self::needs_human( array() );
		}

		$confidence = isset( $assessment['confidence'] ) ? (string) $assessment['confidence'] : '';
		if ( 'low' === $confidence || '' === $confidence ) {
			return self::needs_human( array() );
		}

		$score = self::parse_risk_score( $assessment['publication_safety_score'] ?? null );
		if ( null === $score ) {
			return self::needs_human( array() );
		}

		$codes = self::parse_reason_codes( $assessment['reason_codes'] ?? null );

		if ( self::intersects( $codes, self::MANDATORY_HUMAN_CODES ) ) {
			return new Recommendation(
				Recommendation::ACTION_MANDATORY_HUMAN,
				self::RECOMMENDATION_POLICY_VERSION,
				array_values( array_intersect( $codes, self::MANDATORY_HUMAN_CODES ) )
			);
		}

		if ( 'completed' === $state && 'high' === $confidence && $score >= self::RISK_HIGH_MIN ) {
			if ( self::intersects( $codes, self::SPAM_FAMILY_CODES ) ) {
				return new Recommendation(
					Recommendation::ACTION_LIKELY_SPAM,
					self::RECOMMENDATION_POLICY_VERSION,
					array_values( array_intersect( $codes, self::SPAM_FAMILY_CODES ) )
				);
			}
			if ( self::intersects( $codes, self::ABUSE_FAMILY_CODES ) ) {
				return new Recommendation(
					Recommendation::ACTION_LIKELY_ABUSE,
					self::RECOMMENDATION_POLICY_VERSION,
					array_values( array_intersect( $codes, self::ABUSE_FAMILY_CODES ) )
				);
			}
		}

		if ( 'completed' === $state
			&& in_array( $confidence, array( 'medium', 'high' ), true )
			&& $score <= self::RISK_LOW_MAX
		) {
			return new Recommendation(
				Recommendation::ACTION_LIKELY_PUBLISHABLE,
				self::RECOMMENDATION_POLICY_VERSION,
				array()
			);
		}

		return self::needs_human( $codes );
	}

	/**
	 * Human-readable label for an action (escaped by caller).
	 */
	public static function action_label( string $action ): string {
		switch ( $action ) {
			case Recommendation::ACTION_LIKELY_PUBLISHABLE:
				return __( 'Likely publishable (advisory — human must approve)', 'universal-product-reviews' );
			case Recommendation::ACTION_LIKELY_SPAM:
				return __( 'Likely spam', 'universal-product-reviews' );
			case Recommendation::ACTION_LIKELY_ABUSE:
				return __( 'Likely abuse', 'universal-product-reviews' );
			case Recommendation::ACTION_MANDATORY_HUMAN:
				return __( 'Mandatory human review', 'universal-product-reviews' );
			case Recommendation::ACTION_NEEDS_HUMAN:
			default:
				return __( 'Needs human review', 'universal-product-reviews' );
		}
	}

	/**
	 * @param list<string> $codes Codes.
	 */
	private static function needs_human( array $codes ): Recommendation {
		return new Recommendation(
			Recommendation::ACTION_NEEDS_HUMAN,
			self::RECOMMENDATION_POLICY_VERSION,
			$codes
		);
	}

	/**
	 * @param mixed $raw Score field.
	 */
	private static function parse_risk_score( $raw ): ?int {
		if ( null === $raw || '' === $raw || is_bool( $raw ) ) {
			return null;
		}
		if ( is_string( $raw ) && 1 === preg_match( '/[.\s]/', $raw ) ) {
			return null;
		}
		if ( is_float( $raw ) && floor( $raw ) !== $raw ) {
			return null;
		}
		if ( ! is_numeric( $raw ) ) {
			return null;
		}
		$n = (int) $raw;
		if ( $n < 1 || $n > 100 ) {
			return null;
		}
		return $n;
	}

	/**
	 * @param mixed $raw JSON string or list.
	 * @return list<string>
	 */
	private static function parse_reason_codes( $raw ): array {
		if ( is_string( $raw ) ) {
			if ( '' === $raw ) {
				return array();
			}
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $code ) {
			if ( ! is_string( $code ) || ! PolicyAllowlist::is_reason_code( $code ) ) {
				continue;
			}
			$out[] = $code;
			if ( count( $out ) >= PolicyAllowlist::MAX_REASON_CODES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param list<string> $haystack Codes.
	 * @param list<string> $needles  Family.
	 */
	private static function intersects( array $haystack, array $needles ): bool {
		foreach ( $haystack as $code ) {
			if ( in_array( $code, $needles, true ) ) {
				return true;
			}
		}
		return false;
	}
}
