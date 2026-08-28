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

	public const INVITATION_EMAILS_ENABLED       = 'upr_invitation_emails_enabled';
	public const INVITATION_EMERGENCY_PAUSE      = 'upr_invitation_emergency_pause';
	public const INVITATION_EMERGENCY_PAUSE_META = 'upr_invitation_emergency_pause_meta';
	public const INVITATION_CONTROLS_EPOCH       = 'upr_invitation_controls_epoch';
	public const INVITATION_SCHEDULING_BOUNDARY_AT = 'upr_invitation_scheduling_boundary_at';

	public const LOCAL_AI_SHADOW_ENABLED = 'upr_local_ai_shadow_enabled';

	/**
	 * Local AI shadow assessment. Absent / unset = disabled (fail-closed).
	 */
	public static function local_ai_shadow_enabled(): bool {
		return self::option_is_truthy( get_option( self::LOCAL_AI_SHADOW_ENABLED, 'no' ) );
	}

	/**
	 * Master invitation-email control. Absent / unset = disabled (fail-closed).
	 */
	public static function invitation_emails_enabled(): bool {
		return self::option_is_truthy( get_option( self::INVITATION_EMAILS_ENABLED, 'no' ) );
	}

	/**
	 * Emergency pause. Absent / unset = not paused.
	 */
	public static function invitation_emergency_pause(): bool {
		return self::option_is_truthy( get_option( self::INVITATION_EMERGENCY_PAUSE, 'no' ) );
	}

	public static function invitation_controls_epoch(): int {
		return max( 0, (int) get_option( self::INVITATION_CONTROLS_EPOCH, 0 ) );
	}

	public static function bump_controls_epoch(): void {
		update_option( self::INVITATION_CONTROLS_EPOCH, self::invitation_controls_epoch() + 1, false );
	}

	/**
	 * Unix timestamp: source events strictly before this must not create invitation email work.
	 * Absent / 0 = fail-closed (no historical source is schedulable).
	 */
	public static function invitation_scheduling_boundary_unix(): int {
		return max( 0, (int) get_option( self::INVITATION_SCHEDULING_BOUNDARY_AT, 0 ) );
	}

	/**
	 * Persist the no-retro-send boundary (typically "now" on enable / unpause).
	 */
	public static function refresh_scheduling_boundary( ?int $unix = null ): void {
		update_option( self::INVITATION_SCHEDULING_BOUNDARY_AT, (string) ( $unix ?? time() ), false );
	}

	/**
	 * True when the source event is at/after the scheduling boundary.
	 */
	public static function is_source_event_within_scheduling_boundary( int $source_event_unix ): bool {
		$boundary = self::invitation_scheduling_boundary_unix();
		if ( $boundary <= 0 || $source_event_unix <= 0 ) {
			return false;
		}
		return $source_event_unix >= $boundary;
	}

	/**
	 * @param mixed $value Raw option value.
	 */
	public static function option_is_truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 1 === $value;
		}
		$v = strtolower( trim( (string) $value ) );
		return in_array( $v, array( '1', 'yes', 'true', 'on' ), true );
	}

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
