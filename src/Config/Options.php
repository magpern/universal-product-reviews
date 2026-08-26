<?php
/**
 * Configuration option defaults.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Config;

defined( 'ABSPATH' ) || exit;

final class Options {

	public const DELAY_AFTER_DELIVERY     = 'upr_delay_days_after_delivery';
	public const DELAY_FALLBACK_COMPLETED = 'upr_delay_days_fallback_completed';
	public const REMINDER_AFTER_INITIAL   = 'upr_reminder_days_after_initial';
	public const TOKEN_TTL_DAYS           = 'upr_token_ttl_days';
	public const FORM_SESSION_TTL_MIN     = 'upr_form_session_ttl_minutes';
	public const SEND_CLAIM_STALE_MIN     = 'upr_send_claim_stale_minutes';

	public static function delay_days_after_delivery(): int {
		return max( 0, (int) get_option( self::DELAY_AFTER_DELIVERY, 10 ) );
	}

	public static function delay_days_fallback_completed(): int {
		return max( 0, (int) get_option( self::DELAY_FALLBACK_COMPLETED, 14 ) );
	}

	public static function reminder_days_after_initial(): int {
		return max( 1, (int) get_option( self::REMINDER_AFTER_INITIAL, 14 ) );
	}

	public static function token_ttl_days(): int {
		return max( 1, (int) get_option( self::TOKEN_TTL_DAYS, 30 ) );
	}

	public static function form_session_ttl_minutes(): int {
		return max( 1, (int) get_option( self::FORM_SESSION_TTL_MIN, 45 ) );
	}

	public static function send_claim_stale_minutes(): int {
		return max( 1, (int) get_option( self::SEND_CLAIM_STALE_MIN, 30 ) );
	}
}
