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

	public const AI_RECOMMENDATIONS_DISPLAY = 'upr_ai_recommendations_display';

	/** M12 auto_spam_held_technical — all default off / fail-closed. */
	public const AI_AUTO_SPAM_ENABLED           = 'upr_ai_auto_spam_enabled';
	public const AI_AUTO_SPAM_POLICY_ENABLED    = 'upr_ai_auto_spam_policy_enabled';
	public const AI_AUTO_SPAM_SIMULATION_GUARD  = 'upr_ai_auto_spam_simulation_guard';
	public const AI_AUTO_SPAM_DRY_RUN           = 'upr_ai_auto_spam_dry_run';
	public const AI_AUTO_SPAM_KILL_SWITCH       = 'upr_ai_auto_spam_kill_switch';
	public const AI_AUTO_ACTION_BOUNDARY_AT     = 'upr_ai_auto_action_boundary_at';
	public const ASSESSMENTS_LAST_PURGE_AT      = 'upr_assessments_last_purge_at';


	public const AI_EXTERNAL_ENABLED           = 'upr_ai_external_enabled';
	public const AI_PROVIDER                   = 'upr_ai_provider';
	public const OPENAI_MODEL                  = 'upr_openai_model';
	public const OPENAI_MODEL_MANUAL           = 'upr_openai_model_manual';
	public const OPENAI_MAX_OUTPUT_TOKENS      = 'upr_openai_max_output_tokens';
	public const AI_OPERATOR_GUIDANCE          = 'upr_ai_operator_guidance';
	public const AI_ALLOWED_PHRASES            = 'upr_ai_allowed_phrases';
	public const AI_DISALLOWED_PHRASES         = 'upr_ai_disallowed_phrases';
	public const OPENAI_DAILY_REQUEST_CAP      = 'upr_openai_daily_request_cap';
	public const OPENAI_MONTHLY_REQUEST_CAP    = 'upr_openai_monthly_request_cap';

	public const OPENAI_MODEL_DEFAULT          = 'gpt-4o-mini';
	public const OPENAI_MAX_OUTPUT_TOKENS_DEFAULT = 256;
	public const OPENAI_MAX_OUTPUT_TOKENS_MIN  = 64;
	public const OPENAI_MAX_OUTPUT_TOKENS_MAX  = 512;
	public const OPENAI_DAILY_CAP_DEFAULT      = 100;
	public const OPENAI_MONTHLY_CAP_DEFAULT    = 2000;
	public const REVIEW_TEXT_MAX_CHARS         = 4096;
	public const GUIDANCE_MAX_CHARS            = 2048;
	public const PHRASE_MAX_COUNT              = 20;
	public const PHRASE_MAX_CHARS              = 64;

	/** @var list<string> */
	public const OPENAI_SUGGESTED_MODELS = array(
		'gpt-4o-mini',
		'gpt-4.1-mini',
		'gpt-4.1-nano',
		'gpt-5.6',
	);

	/**
	 * Local AI shadow assessment. Absent / unset = disabled (fail-closed).
	 */
	public static function local_ai_shadow_enabled(): bool {
		return self::option_is_truthy( get_option( self::LOCAL_AI_SHADOW_ENABLED, 'no' ) );
	}

	/** M12 master — absent = off. */
	public static function ai_auto_spam_enabled(): bool {
		return self::option_is_truthy( get_option( self::AI_AUTO_SPAM_ENABLED, 'no' ) );
	}

	/** M12 policy master — absent = off. */
	public static function ai_auto_spam_policy_enabled(): bool {
		return self::option_is_truthy( get_option( self::AI_AUTO_SPAM_POLICY_ENABLED, 'no' ) );
	}

	/** Simulation-only non-production guard — absent = off. */
	public static function ai_auto_spam_simulation_guard_enabled(): bool {
		return self::option_is_truthy( get_option( self::AI_AUTO_SPAM_SIMULATION_GUARD, 'no' ) );
	}

	public static function ai_auto_spam_dry_run(): bool {
		return self::option_is_truthy( get_option( self::AI_AUTO_SPAM_DRY_RUN, 'no' ) );
	}

	/** Kill switch — when on, force abstain. Absent = off. */
	public static function ai_auto_spam_kill_switch(): bool {
		return self::option_is_truthy( get_option( self::AI_AUTO_SPAM_KILL_SWITCH, 'no' ) );
	}

	public static function ai_auto_action_boundary_unix(): int {
		return max( 0, (int) get_option( self::AI_AUTO_ACTION_BOUNDARY_AT, 0 ) );
	}

	public static function refresh_auto_action_boundary( ?int $unix = null ): void {
		update_option( self::AI_AUTO_ACTION_BOUNDARY_AT, (string) ( $unix ?? time() ), false );
	}

	/**
	 * Live action only if assessment completed_at is strictly greater than boundary.
	 * Equality abstains; missing/0 boundary fails closed.
	 */
	public static function is_assessment_strictly_after_auto_action_boundary( int $completed_at_unix ): bool {
		$boundary = self::ai_auto_action_boundary_unix();
		if ( $boundary <= 0 || $completed_at_unix <= 0 ) {
			return false;
		}
		return $completed_at_unix > $boundary;
	}

	public static function assessments_last_purge_unix(): int {
		return max( 0, (int) get_option( self::ASSESSMENTS_LAST_PURGE_AT, 0 ) );
	}


	/**
	 * Show actionable AI recommendations in Comments. Absent / unset = enabled.
	 * Independent of local/external shadow masters.
	 */
	public static function ai_recommendations_display_enabled(): bool {
		return self::option_is_truthy( get_option( self::AI_RECOMMENDATIONS_DISPLAY, 'yes' ) );
	}

	public static function ai_external_enabled(): bool {
		return self::option_is_truthy( get_option( self::AI_EXTERNAL_ENABLED, 'no' ) );
	}

	/**
	 * @return 'local'|'openai'
	 */
	public static function ai_provider(): string {
		return self::sanitize_provider( get_option( self::AI_PROVIDER, 'local' ) );
	}

	public static function openai_model(): string {
		$manual = self::sanitize_model_manual( get_option( self::OPENAI_MODEL_MANUAL, '' ) );
		if ( '' !== $manual ) {
			return $manual;
		}
		$dropdown = (string) get_option( self::OPENAI_MODEL, self::OPENAI_MODEL_DEFAULT );
		if ( in_array( $dropdown, self::OPENAI_SUGGESTED_MODELS, true ) ) {
			return $dropdown;
		}
		return self::OPENAI_MODEL_DEFAULT;
	}

	public static function openai_max_output_tokens(): int {
		$n = (int) get_option( self::OPENAI_MAX_OUTPUT_TOKENS, self::OPENAI_MAX_OUTPUT_TOKENS_DEFAULT );
		return max( self::OPENAI_MAX_OUTPUT_TOKENS_MIN, min( self::OPENAI_MAX_OUTPUT_TOKENS_MAX, $n ) );
	}

	public static function openai_daily_request_cap(): int {
		$n = (int) get_option( self::OPENAI_DAILY_REQUEST_CAP, self::OPENAI_DAILY_CAP_DEFAULT );
		return max( 1, min( 10000, $n ) );
	}

	public static function openai_monthly_request_cap(): int {
		$n = (int) get_option( self::OPENAI_MONTHLY_REQUEST_CAP, self::OPENAI_MONTHLY_CAP_DEFAULT );
		return max( 1, min( 100000, $n ) );
	}

	public static function ai_operator_guidance(): string {
		$raw = (string) get_option( self::AI_OPERATOR_GUIDANCE, '' );
		$raw = wp_strip_all_tags( $raw );
		if ( strlen( $raw ) > self::GUIDANCE_MAX_CHARS ) {
			$raw = substr( $raw, 0, self::GUIDANCE_MAX_CHARS );
		}
		return $raw;
	}

	/**
	 * @return list<string>
	 */
	public static function ai_allowed_phrases(): array {
		return self::sanitize_phrase_list( get_option( self::AI_ALLOWED_PHRASES, array() ) );
	}

	/**
	 * @return list<string>
	 */
	public static function ai_disallowed_phrases(): array {
		return self::sanitize_phrase_list( get_option( self::AI_DISALLOWED_PHRASES, array() ) );
	}

	/**
	 * @param mixed $value Raw provider.
	 * @return 'local'|'openai'
	 */
	public static function sanitize_provider( $value ): string {
		$v = strtolower( trim( (string) $value ) );
		return 'openai' === $v ? 'openai' : 'local';
	}

	/**
	 * @param mixed $value Manual model id.
	 */
	public static function sanitize_model_manual( $value ): string {
		$v = trim( (string) $value );
		if ( '' === $v ) {
			return '';
		}
		if ( 1 !== preg_match( '/^[a-zA-Z0-9._:-]{1,64}$/', $v ) ) {
			return '';
		}
		return $v;
	}

	/**
	 * @param mixed $raw Option value.
	 * @return list<string>
	 */
	public static function sanitize_phrase_list( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : preg_split( '/\r\n|\r|\n/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $item ) {
			if ( ! is_string( $item ) && ! is_numeric( $item ) ) {
				continue;
			}
			$p = trim( wp_strip_all_tags( (string) $item ) );
			if ( '' === $p ) {
				continue;
			}
			if ( strlen( $p ) > self::PHRASE_MAX_CHARS ) {
				$p = substr( $p, 0, self::PHRASE_MAX_CHARS );
			}
			$out[] = $p;
			if ( count( $out ) >= self::PHRASE_MAX_COUNT ) {
				break;
			}
		}
		return $out;
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
