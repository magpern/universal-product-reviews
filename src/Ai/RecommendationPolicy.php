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
	 * Compile held-only Comments list EXISTS predicate for an allowlisted suggested_action.
	 *
	 * Returns prepared SQL fragment + bound args only. Never interpolates request data.
	 * Uses latest assessment of any state (M11 advisory) — distinct from latest_actionable_*.
	 *
	 * @return array{fragment:string, args:list<mixed>}|null Null when action is not allowlisted.
	 */
	public static function compile_held_filter_sql( string $action ): ?array {
		if ( ! in_array( $action, Recommendation::ACTIONS, true ) ) {
			return null;
		}

		global $wpdb;
		$table = AssessmentRepository::table();

		$latest = "a.assessment_id = ( SELECT MAX(a2.assessment_id) FROM {$table} a2 WHERE a2.comment_id = a.comment_id )";

		$predicate = self::sql_predicate_for_action( $action );
		if ( null === $predicate ) {
			return null;
		}

		$fragment = "EXISTS (
			SELECT 1 FROM {$table} a
			WHERE a.comment_id = {$wpdb->comments}.comment_ID
			AND {$latest}
			AND ( {$predicate['sql']} )
		) AND {$wpdb->comments}.comment_approved = %s";

		$args   = $predicate['args'];
		$args[] = '0';

		return array(
			'fragment' => $fragment,
			'args'     => $args,
		);
	}

	/**
	 * Structured action predicates shared conceptually with suggest() (risk-direction).
	 *
	 * @return array{sql:string, args:list<mixed>}|null
	 */
	private static function sql_predicate_for_action( string $action ): ?array {
		$high_min = self::RISK_HIGH_MIN;
		$low_max  = self::RISK_LOW_MAX;

		$mandatory_like = self::json_codes_like_sql( self::MANDATORY_HUMAN_CODES );
		$spam_like      = self::json_codes_like_sql( self::SPAM_FAMILY_CODES );
		$abuse_like     = self::json_codes_like_sql( self::ABUSE_FAMILY_CODES );

		$base_invalid = "(
			a.state IN ('failed','skipped','indeterminate')
			OR a.confidence IS NULL OR a.confidence = '' OR a.confidence = 'low'
			OR a.publication_safety_score IS NULL
			OR CAST(a.publication_safety_score AS UNSIGNED) < 1
			OR CAST(a.publication_safety_score AS UNSIGNED) > 100
		)";

		$has_mandatory = '(' . $mandatory_like['sql'] . ')';
		$has_spam      = '(' . $spam_like['sql'] . ')';
		$has_abuse     = '(' . $abuse_like['sql'] . ')';

		switch ( $action ) {
			case Recommendation::ACTION_MANDATORY_HUMAN:
				return array(
					'sql'  => "NOT {$base_invalid} AND {$has_mandatory}",
					'args' => $mandatory_like['args'],
				);

			case Recommendation::ACTION_LIKELY_SPAM:
				return array(
					'sql'  => "NOT {$base_invalid}
						AND NOT {$has_mandatory}
						AND a.state = 'completed'
						AND a.confidence = 'high'
						AND CAST(a.publication_safety_score AS UNSIGNED) >= %d
						AND {$has_spam}",
					'args' => array_merge( $mandatory_like['args'], array( $high_min ), $spam_like['args'] ),
				);

			case Recommendation::ACTION_LIKELY_ABUSE:
				return array(
					'sql'  => "NOT {$base_invalid}
						AND NOT {$has_mandatory}
						AND a.state = 'completed'
						AND a.confidence = 'high'
						AND CAST(a.publication_safety_score AS UNSIGNED) >= %d
						AND NOT {$has_spam}
						AND {$has_abuse}",
					'args' => array_merge(
						$mandatory_like['args'],
						array( $high_min ),
						$spam_like['args'],
						$abuse_like['args']
					),
				);

			case Recommendation::ACTION_LIKELY_PUBLISHABLE:
				return array(
					'sql'  => "NOT {$base_invalid}
						AND NOT {$has_mandatory}
						AND a.state = 'completed'
						AND a.confidence IN ('medium','high')
						AND CAST(a.publication_safety_score AS UNSIGNED) <= %d
						AND NOT (
							a.confidence = 'high'
							AND CAST(a.publication_safety_score AS UNSIGNED) >= %d
							AND ( {$has_spam} OR {$has_abuse} )
						)",
					'args' => array_merge(
						$mandatory_like['args'],
						array( $low_max, $high_min ),
						$spam_like['args'],
						$abuse_like['args']
					),
				);

			case Recommendation::ACTION_NEEDS_HUMAN:
				// Residual of suggest(): not mandatory / spam / abuse / publishable.
				$spam_branch = "NOT {$base_invalid}
					AND NOT {$has_mandatory}
					AND a.state = 'completed'
					AND a.confidence = 'high'
					AND CAST(a.publication_safety_score AS UNSIGNED) >= %d
					AND {$has_spam}";
				$abuse_branch = "NOT {$base_invalid}
					AND NOT {$has_mandatory}
					AND a.state = 'completed'
					AND a.confidence = 'high'
					AND CAST(a.publication_safety_score AS UNSIGNED) >= %d
					AND NOT {$has_spam}
					AND {$has_abuse}";
				$pub_branch = "NOT {$base_invalid}
					AND NOT {$has_mandatory}
					AND a.state = 'completed'
					AND a.confidence IN ('medium','high')
					AND CAST(a.publication_safety_score AS UNSIGNED) <= %d
					AND NOT (
						a.confidence = 'high'
						AND CAST(a.publication_safety_score AS UNSIGNED) >= %d
						AND ( {$has_spam} OR {$has_abuse} )
					)";
				$mand_branch = "NOT {$base_invalid} AND {$has_mandatory}";
				return array(
					'sql'  => "NOT ( {$mand_branch} )
						AND NOT ( {$spam_branch} )
						AND NOT ( {$abuse_branch} )
						AND NOT ( {$pub_branch} )",
					'args' => array_merge(
						$mandatory_like['args'],
						$mandatory_like['args'],
						array( $high_min ),
						$spam_like['args'],
						$mandatory_like['args'],
						array( $high_min ),
						$spam_like['args'],
						$abuse_like['args'],
						$mandatory_like['args'],
						array( $low_max, $high_min ),
						$spam_like['args'],
						$abuse_like['args']
					),
				);

			default:
				return null;
		}
	}

	/**
	 * Allowlisted reason-code predicates against JSON reason_codes (no request input).
	 *
	 * Uses LOCATE(CONCAT(CHAR(34), code, CHAR(34)), …) so bound args are bare
	 * allowlisted codes (no `%` wildcards, no quote escaping issues in prepare).
	 *
	 * @param list<string> $codes Allowlisted codes.
	 * @return array{sql:string, args:list<string>}
	 */
	private static function json_codes_like_sql( array $codes ): array {
		$parts = array();
		$args  = array();
		foreach ( $codes as $code ) {
			if ( ! is_string( $code ) || ! PolicyAllowlist::is_reason_code( $code ) ) {
				continue;
			}
			$parts[] = 'LOCATE(CONCAT(CHAR(34), %s, CHAR(34)), IFNULL(a.reason_codes, \'\')) > 0';
			$args[]  = $code;
		}
		if ( array() === $parts ) {
			return array( 'sql' => '0=1', 'args' => array() );
		}
		return array(
			'sql'  => implode( ' OR ', $parts ),
			'args' => $args,
		);
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
