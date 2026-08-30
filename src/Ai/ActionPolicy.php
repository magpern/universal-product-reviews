<?php
/**
 * M12 ActionPolicy for auto_spam_held_technical (Simulation GO tuple).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

final class ActionPolicy {

	public const ACTION_POLICY_VERSION = '2026-08-act-v1';

	public const CONTRACT_ID = 'auto_spam_held_technical';

	/**
	 * Frozen Simulation GO calibrated tuple (matches m12-sim-v1 fixture).
	 *
	 * @var array<string, string>
	 */
	public const SIMULATION_TUPLE = array(
		'provider_kind'                  => 'local',
		'assessor_version'               => 'builtin-local-2026-08',
		'heuristic_or_model_fingerprint' => 'upr-local-heuristic-v1',
		'validator_version'              => 'assessment-validator-v1',
		'assessment_policy_version'      => '2026-08-ps-v1',
		'recommendation_policy_version'  => '2026-08-rec-v1',
		'action_policy_version'          => '2026-08-act-v1',
	);

	/**
	 * @param array<string, mixed> $assessment Terminal assessment row.
	 * @param mixed                $comment    Optional comment object; loaded from assessment when null.
	 * @param bool                 $for_dry_run When true, auto-spam master may be off (observe-only path).
	 * @return array{ok:bool, reason:string}
	 */
	public static function eligible( array $assessment, $comment = null, bool $for_dry_run = false ): array {
		if ( Options::ai_auto_spam_kill_switch() ) {
			return array( 'ok' => false, 'reason' => 'kill_switch' );
		}
		if ( ! $for_dry_run && ! Options::ai_auto_spam_enabled() ) {
			return array( 'ok' => false, 'reason' => 'master_off' );
		}
		if ( ! Options::ai_auto_spam_policy_enabled() ) {
			return array( 'ok' => false, 'reason' => 'policy_off' );
		}
		if ( ! Options::ai_auto_spam_simulation_guard_enabled() ) {
			return array( 'ok' => false, 'reason' => 'simulation_guard_off' );
		}

		if ( null === $comment ) {
			$cid     = isset( $assessment['comment_id'] ) ? (int) $assessment['comment_id'] : 0;
			$comment = $cid > 0 ? get_comment( $cid ) : null;
		}
		if ( ! $comment || ! Eligibility::is_ai_assessable( $comment ) ) {
			return array( 'ok' => false, 'reason' => 'ineligible_comment' );
		}

		$state = (string) ( $assessment['state'] ?? '' );
		if ( 'completed' !== $state ) {
			return array( 'ok' => false, 'reason' => 'not_completed' );
		}

		$completed_raw  = (string) ( $assessment['completed_at'] ?? '' );
		$completed_unix = $completed_raw !== '' ? (int) strtotime( $completed_raw . ' UTC' ) : 0;
		if ( ! Options::is_assessment_strictly_after_auto_action_boundary( $completed_unix ) ) {
			return array( 'ok' => false, 'reason' => 'boundary' );
		}

		if ( ! self::tuple_matches( $assessment ) ) {
			return array( 'ok' => false, 'reason' => 'tuple_mismatch' );
		}

		$rec = RecommendationPolicy::suggest( $assessment );
		if ( Recommendation::ACTION_LIKELY_SPAM !== $rec->action ) {
			if ( Recommendation::ACTION_MANDATORY_HUMAN === $rec->action ) {
				return array( 'ok' => false, 'reason' => 'mandatory_human' );
			}
			return array( 'ok' => false, 'reason' => 'not_likely_spam' );
		}

		return array( 'ok' => true, 'reason' => 'eligible' );
	}

	/**
	 * @param array<string, mixed> $assessment Assessment row.
	 */
	public static function tuple_matches( array $assessment ): bool {
		$tuple = self::SIMULATION_TUPLE;
		if ( (string) ( $assessment['provider_kind'] ?? '' ) !== $tuple['provider_kind'] ) {
			return false;
		}
		if ( (string) ( $assessment['policy_version'] ?? '' ) !== $tuple['assessment_policy_version'] ) {
			return false;
		}
		if ( RecommendationPolicy::RECOMMENDATION_POLICY_VERSION !== $tuple['recommendation_policy_version'] ) {
			return false;
		}
		if ( self::ACTION_POLICY_VERSION !== $tuple['action_policy_version'] ) {
			return false;
		}
		$expected_fp = ProviderFingerprint::for_builtin( $tuple['assessment_policy_version'] );
		if ( (string) ( $assessment['provider_fingerprint'] ?? '' ) !== $expected_fp ) {
			return false;
		}
		return true;
	}

	/**
	 * Fingerprint string for diagnostics (no secrets).
	 */
	public static function active_tuple_fingerprint(): string {
		return hash( 'sha256', wp_json_encode( self::SIMULATION_TUPLE ) ?: '' );
	}

	/**
	 * Masters conjunction for scheduling (dry-run may schedule with dry_run on).
	 */
	public static function masters_allow_work(): bool {
		if ( Options::ai_auto_spam_kill_switch() ) {
			return false;
		}
		if ( ! Options::ai_auto_spam_enabled() || ! Options::ai_auto_spam_policy_enabled() ) {
			return false;
		}
		if ( ! Options::ai_auto_spam_simulation_guard_enabled() ) {
			return false;
		}
		return true;
	}
}
