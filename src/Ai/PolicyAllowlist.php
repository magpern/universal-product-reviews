<?php
/**
 * M8/M9 publication-safety policy allowlists.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

final class PolicyAllowlist {

	public const POLICY_VERSION = '2026-08-ps-v1';

	public const SCHEMA_VERSION = 'upr-moderation-assessment/v1';

	public const PROVIDER_STABLE_ID = 'upr.builtin.local';

	public const OPENAI_PROVIDER_STABLE_ID = 'upr.openai.responses';

	public const CONFIG_REVISION = '1';

	public const MAX_REASON_CODES = 8;

	/** @var list<string> */
	public const REASON_CODES = array(
		'spam_pattern',
		'link_abuse',
		'pii_suspected',
		'contact_info_suspected',
		'abuse_harassment',
		'threat_suspected',
		'hate_suspected',
		'regulatory_claim_suspected',
		'medical_claim_suspected',
		'safety_claim_suspected',
		'impersonation_suspected',
		'fraud_suspected',
		'off_topic',
		'unsupported_language',
		'insufficient_signal',
	);

	/** @var list<string> */
	public const FAILURE_CODES = array(
		'deadline_exceeded',
		'malformed',
		'rate_limited',
		'provider_unavailable',
		'validation_rejected',
		'privacy_blocked',
		'unsupported_language',
		'ineligible_comment',
		'circuit_open',
		'budget_exceeded',
		'credential_missing',
		'model_invalid',
		'input_too_large',
		'provider_incomplete',
	);

	/** @var list<string> */
	public const CONFIDENCE = array( 'high', 'medium', 'low' );

	/** @var list<string> */
	public const TERMINAL_STATES = array( 'completed', 'indeterminate', 'failed', 'skipped' );

	/** @var list<string> */
	private const FORBIDDEN_REASON_FRAGMENTS = array(
		'sentiment',
		'rating',
		'negative',
		'positive',
		'critical',
		'praise',
		'helpful',
		'quality',
	);

	public static function is_reason_code( string $code ): bool {
		return in_array( $code, self::REASON_CODES, true );
	}

	public static function is_failure_code( string $code ): bool {
		return in_array( $code, self::FAILURE_CODES, true );
	}

	public static function reason_code_has_forbidden_label( string $code ): bool {
		$lower = strtolower( $code );
		foreach ( self::FORBIDDEN_REASON_FRAGMENTS as $frag ) {
			if ( false !== strpos( $lower, $frag ) ) {
				return true;
			}
		}
		return false;
	}
}
