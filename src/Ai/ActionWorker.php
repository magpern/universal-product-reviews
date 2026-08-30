<?php
/**
 * M12 auto_spam_held_technical worker (Simulation GO — masters default off).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Moderation\HoldToSpamCas;

defined( 'ABSPATH' ) || exit;

final class ActionWorker {

	/**
	 * Attempt dry-run observe or live CAS for one assessment.
	 */
	public static function handle( int $comment_id, int $assessment_id ): void {
		if ( ! ActionPolicy::masters_allow_work() && ! Options::ai_auto_spam_dry_run() ) {
			return;
		}
		// Dry-run may run with master off per freeze dry-run path, but still needs policy+simulation for Simulation GO.
		if ( Options::ai_auto_spam_dry_run() ) {
			if ( ! Options::ai_auto_spam_policy_enabled() || ! Options::ai_auto_spam_simulation_guard_enabled() ) {
				return;
			}
			if ( Options::ai_auto_spam_kill_switch() ) {
				return;
			}
		} elseif ( ! ActionPolicy::masters_allow_work() ) {
			return;
		}

		$assessment = AssessmentRepository::get_by_id( $assessment_id );
		if ( ! is_array( $assessment ) || (int) ( $assessment['comment_id'] ?? 0 ) !== $comment_id ) {
			return;
		}

		$resolved      = AssessmentRepository::resolve_actionable_assessment( $comment_id );
		$actionable_id = is_array( $resolved['assessment'] ?? null )
			? (int) ( $resolved['assessment']['assessment_id'] ?? 0 )
			: 0;
		if ( $actionable_id !== $assessment_id ) {
			$policy = ActionPolicy::ACTION_POLICY_VERSION;
			$token  = ActionLedgerRepository::acquire_processing( $comment_id, $assessment_id, $policy, 'action-worker' );
			if ( null === $token ) {
				return;
			}
			$reason = $actionable_id > 0 ? 'superseded' : (string) ( $resolved['reason'] ?? 'superseded' );
			ActionLedgerRepository::transition_token_matched(
				$comment_id,
				$assessment_id,
				$policy,
				$token,
				ActionLedgerRepository::STATE_PROCESSING,
				ActionLedgerRepository::STATE_ABSTAINED,
				$reason
			);
			return;
		}

		$policy = ActionPolicy::ACTION_POLICY_VERSION;
		$token  = ActionLedgerRepository::acquire_processing( $comment_id, $assessment_id, $policy, 'action-worker' );
		if ( null === $token ) {
			return;
		}

		$gate = ActionPolicy::eligible( $assessment, null, Options::ai_auto_spam_dry_run() );
		if ( ! $gate['ok'] ) {
			ActionLedgerRepository::transition_token_matched(
				$comment_id,
				$assessment_id,
				$policy,
				$token,
				ActionLedgerRepository::STATE_PROCESSING,
				ActionLedgerRepository::STATE_ABSTAINED,
				(string) $gate['reason']
			);
			return;
		}

		if ( Options::ai_auto_spam_dry_run() ) {
			ActionLedgerRepository::transition_token_matched(
				$comment_id,
				$assessment_id,
				$policy,
				$token,
				ActionLedgerRepository::STATE_PROCESSING,
				ActionLedgerRepository::STATE_OBSERVED,
				null,
				null,
				null,
				true
			);
			return;
		}

		self::live_cas_path( $comment_id, $assessment_id, $policy, $token, $assessment );
	}

	/**
	 * Silently drop an in-flight lease (disable / mid-flight master change) without terminal rows.
	 */
	private static function clear_claim_silently( int $comment_id, int $assessment_id, string $policy, string $token ): void {
		global $wpdb;
		$table = ActionLedgerRepository::table();
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state = NULL, lease_token = NULL, lease_expires_at = NULL, lease_owner = NULL, updated_at = %s
				WHERE comment_id = %d AND assessment_id = %d AND action_policy_version = %s AND lease_token = %s AND state = %s",
				$now,
				$comment_id,
				$assessment_id,
				$policy,
				$token,
				ActionLedgerRepository::STATE_PROCESSING
			)
		);
	}

	/**
	 * @param array<string, mixed> $assessment Assessment.
	 */
	private static function live_cas_path( int $comment_id, int $assessment_id, string $policy, string $token, array $assessment ): void {
		global $wpdb;

		$comment_old = get_comment( $comment_id );
		if ( ! $comment_old instanceof \WP_Comment || '0' !== (string) $comment_old->comment_approved ) {
			ActionLedgerRepository::transition_token_matched(
				$comment_id,
				$assessment_id,
				$policy,
				$token,
				ActionLedgerRepository::STATE_PROCESSING,
				ActionLedgerRepository::STATE_ABSTAINED,
				'not_hold'
			);
			return;
		}
		$comment_old = clone $comment_old;

		$ledger_table = ActionLedgerRepository::table();
		$wpdb->query( 'START TRANSACTION' );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$locked = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$ledger_table} WHERE comment_id = %d AND assessment_id = %d AND action_policy_version = %s FOR UPDATE",
					$comment_id,
					$assessment_id,
					$policy
				),
				ARRAY_A
			);
			if ( ! is_array( $locked )
				|| (string) ( $locked['lease_token'] ?? '' ) !== $token
				|| ActionLedgerRepository::STATE_PROCESSING !== (string) ( $locked['state'] ?? '' )
			) {
				$wpdb->query( 'ROLLBACK' );
				return;
			}

			// Disable / dry-run mid-flight: clear claim without inventing terminal action records.
			if ( ! ActionPolicy::masters_allow_work() || Options::ai_auto_spam_dry_run() ) {
				self::clear_claim_silently( $comment_id, $assessment_id, $policy, $token );
				$wpdb->query( 'COMMIT' );
				return;
			}

			$gate = ActionPolicy::eligible( $assessment );
			if ( ! $gate['ok'] ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$ledger_table} SET state = %s, abstain_reason = %s, lease_token = NULL, lease_expires_at = NULL, lease_owner = NULL, updated_at = %s
						WHERE comment_id = %d AND assessment_id = %d AND action_policy_version = %s AND lease_token = %s",
						ActionLedgerRepository::STATE_ABSTAINED,
						(string) $gate['reason'],
						current_time( 'mysql', true ),
						$comment_id,
						$assessment_id,
						$policy,
						$token
					)
				);
				$wpdb->query( 'COMMIT' );
				return;
			}

			$affected = HoldToSpamCas::cas_write( $comment_id );
			if ( 0 === $affected ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$ledger_table} SET state = %s, abstain_reason = %s, lease_token = NULL, lease_expires_at = NULL, lease_owner = NULL, updated_at = %s
						WHERE comment_id = %d AND assessment_id = %d AND action_policy_version = %s AND lease_token = %s",
						ActionLedgerRepository::STATE_ABSTAINED,
						'status_changed',
						current_time( 'mysql', true ),
						$comment_id,
						$assessment_id,
						$policy,
						$token
					)
				);
				$wpdb->query( 'COMMIT' );
				return;
			}

			$cas_at = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$ledger_table} SET state = %s, ai_cas_committed_at = %s, updated_at = %s
					WHERE comment_id = %d AND assessment_id = %d AND action_policy_version = %s AND lease_token = %s AND state = %s",
					ActionLedgerRepository::STATE_CAS_SUCCEEDED,
					$cas_at,
					$cas_at,
					$comment_id,
					$assessment_id,
					$policy,
					$token,
					ActionLedgerRepository::STATE_PROCESSING
				)
			);
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return;
		}

		HoldToSpamCas::deliver_hooks_after_successful_cas( $comment_id, $comment_old );

		ActionLedgerRepository::transition_token_matched(
			$comment_id,
			$assessment_id,
			$policy,
			$token,
			ActionLedgerRepository::STATE_CAS_SUCCEEDED,
			ActionLedgerRepository::STATE_ACTED
		);
	}

	/**
	 * Terminalise abandoned cas_succeeded rows without replaying hooks.
	 */
	public static function recover_unknown_after_crash(): void {
		foreach ( ActionLedgerRepository::list_cas_succeeded() as $row ) {
			$comment_id    = (int) ( $row['comment_id'] ?? 0 );
			$assessment_id = (int) ( $row['assessment_id'] ?? 0 );
			$policy        = (string) ( $row['action_policy_version'] ?? ActionPolicy::ACTION_POLICY_VERSION );
			$token         = (string) ( $row['lease_token'] ?? '' );
			// cas_succeeded may have cleared lease_token on some paths — update by state.
			global $wpdb;
			$table = ActionLedgerRepository::table();
			$now   = current_time( 'mysql', true );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET state = %s, crash_reason = %s, lease_token = NULL, lease_expires_at = NULL, lease_owner = NULL, updated_at = %s
					WHERE comment_id = %d AND assessment_id = %d AND action_policy_version = %s AND state = %s",
					ActionLedgerRepository::STATE_UNKNOWN_AFTER_CRASH,
					'cas_succeeded_incomplete_hooks',
					$now,
					$comment_id,
					$assessment_id,
					$policy,
					ActionLedgerRepository::STATE_CAS_SUCCEEDED
				)
			);
			unset( $token );
		}
	}

	/**
	 * Schedule after a completed assessment (no-op if masters/dry-run not configured).
	 */
	public static function maybe_schedule( int $comment_id, int $assessment_id ): void {
		if ( $comment_id <= 0 || $assessment_id <= 0 ) {
			return;
		}
		if ( ! Options::ai_auto_spam_dry_run() && ! ActionPolicy::masters_allow_work() ) {
			return;
		}
		if ( Options::ai_auto_spam_dry_run()
			&& ( ! Options::ai_auto_spam_policy_enabled() || ! Options::ai_auto_spam_simulation_guard_enabled() || Options::ai_auto_spam_kill_switch() )
		) {
			return;
		}
		\UniversalProductReviews\Scheduling\Jobs::schedule_auto_spam_action( $comment_id, $assessment_id );
	}
}
