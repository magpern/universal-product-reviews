<?php
/**
 * Finalize invite completion after a review comment exists.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Moderation\SystemStatusOrigin;
use UniversalProductReviews\Scheduling\Jobs;
use UniversalProductReviews\Tokens\SessionCookie;
use UniversalProductReviews\Tokens\TokenRepository;
use UniversalProductReviews\Tokens\TokenService;

defined( 'ABSPATH' ) || exit;

final class CompletionService {

	/**
	 * Atomically mark item completed, redeem/revoke tokens, clear claim, unschedule, audit.
	 *
	 * With a claim token: requires schedule_state = submitting and matching claim.
	 * Without a claim token (orphan repair): requires schedule_state = submitting only —
	 * never completes suppressed or other terminal states.
	 */
	public static function finalize(
		int $order_item_id,
		int $comment_id,
		int $order_id,
		?int $invite_token_id = null,
		?string $claim_token = null
	): bool {
		global $wpdb;

		$row = InviteRepository::find( $order_item_id );
		if ( ! $row ) {
			return false;
		}
		if ( ScheduleStates::COMPLETED === $row['schedule_state'] && (int) ( $row['review_comment_id'] ?? 0 ) === $comment_id ) {
			return true;
		}
		if ( ScheduleStates::SUPPRESSED === $row['schedule_state'] ) {
			return false;
		}
		if ( ! empty( $row['review_comment_id'] ) && (int) $row['review_comment_id'] !== $comment_id ) {
			return false;
		}

		$table = InviteRepository::table();
		$now   = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( null !== $claim_token && '' !== $claim_token ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				$n = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET schedule_state = %s, review_completed_at = %s, review_comment_id = %d,
						submit_claim_token = NULL, submit_claim_expires_at = NULL, submit_claim_prior_state = NULL, updated_at = %s
						WHERE order_item_id = %d
						AND review_comment_id IS NULL
						AND submit_claim_token = %s
						AND schedule_state = %s",
						ScheduleStates::COMPLETED,
						$now,
						$comment_id,
						$now,
						$order_item_id,
						$claim_token,
						ScheduleStates::SUBMITTING
					)
				);
			} else {
				// Orphan repair: only while still submitting — never suppressed/completed.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$n = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET schedule_state = %s, review_completed_at = %s, review_comment_id = %d,
						submit_claim_token = NULL, submit_claim_expires_at = NULL, submit_claim_prior_state = NULL, updated_at = %s
						WHERE order_item_id = %d
						AND review_comment_id IS NULL
						AND schedule_state = %s",
						ScheduleStates::COMPLETED,
						$now,
						$comment_id,
						$now,
						$order_item_id,
						ScheduleStates::SUBMITTING
					)
				);
			}

			if ( 1 !== (int) $n ) {
				$again = InviteRepository::find( $order_item_id );
				if ( $again && ScheduleStates::COMPLETED === $again['schedule_state'] && (int) ( $again['review_comment_id'] ?? 0 ) === $comment_id ) {
					$wpdb->query( 'COMMIT' );
					return true;
				}
				$wpdb->query( 'ROLLBACK' );
				return false;
			}

			if ( $invite_token_id && $invite_token_id > 0 ) {
				TokenService::redeem_after_submit( $invite_token_id, $order_item_id );
			} else {
				TokenRepository::revoke_for_item( $order_item_id );
				SessionCookie::clear();
			}

			Jobs::unschedule_item( $order_item_id );
			AuditLogger::log( 'invite.completed', 'customer', $order_id, $order_item_id, array( 'comment_id' => $comment_id ) );
			$wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			AuditLogger::log(
				'invite.completion_failed',
				'system',
				$order_id,
				$order_item_id,
				array( 'comment_id' => $comment_id )
			);
			return false;
		}
	}

	/**
	 * Repair an orphaned associated comment while the item is still submitting.
	 * Never completes a suppressed (or otherwise non-submitting) item.
	 */
	public static function finalize_from_comment( int $order_item_id, int $comment_id, int $order_id ): bool {
		$row = InviteRepository::find( $order_item_id );
		if ( ! $row ) {
			return false;
		}
		if ( ScheduleStates::SUPPRESSED === $row['schedule_state'] ) {
			return false;
		}
		if ( ScheduleStates::COMPLETED === $row['schedule_state'] && (int) ( $row['review_comment_id'] ?? 0 ) === $comment_id ) {
			return true;
		}
		if ( ScheduleStates::SUBMITTING !== $row['schedule_state'] ) {
			return false;
		}

		$active          = TokenRepository::find_active_invite( $order_item_id );
		$invite_token_id = $active ? (int) $active['id'] : null;
		return self::finalize( $order_item_id, $comment_id, $order_id, $invite_token_id, null );
	}

	/**
	 * After comment insert, if finalize lost to suppression (or another terminal loser path),
	 * make the comment non-public and clear any leftover claim safely.
	 *
	 * @return bool True when the submission was discarded (caller should return non-success).
	 */
	public static function abandon_lost_submission( int $order_item_id, int $comment_id, ?string $claim_token = null ): bool {
		$row = InviteRepository::find( $order_item_id );
		if ( ! $row ) {
			self::reject_comment( $comment_id );
			return true;
		}

		if ( ScheduleStates::COMPLETED === $row['schedule_state'] ) {
			if ( (int) ( $row['review_comment_id'] ?? 0 ) === $comment_id ) {
				return false;
			}
			self::reject_comment( $comment_id );
			return true;
		}

		if ( ScheduleStates::SUPPRESSED === $row['schedule_state'] ) {
			self::reject_comment( $comment_id );
			self::clear_claim_fields( $order_item_id, $claim_token );
			AuditLogger::log(
				'invite.submission_discarded',
				'system',
				(int) ( $row['order_id'] ?? 0 ),
				$order_item_id,
				array(
					'comment_id' => $comment_id,
					'reason'     => 'suppressed',
				)
			);
			return true;
		}

		return false;
	}

	/**
	 * Mark a review comment non-public (spam) so it cannot surface as pending/approved.
	 */
	public static function reject_comment( int $comment_id ): void {
		if ( $comment_id <= 0 ) {
			return;
		}
		SystemStatusOrigin::set_comment_status( $comment_id, 'spam' );
	}

	/**
	 * Clear claim columns without changing schedule_state (used after suppress wins).
	 */
	public static function clear_claim_fields( int $order_item_id, ?string $claim_token = null ): void {
		global $wpdb;
		$table = InviteRepository::table();
		$now   = current_time( 'mysql', true );

		if ( null !== $claim_token && '' !== $claim_token ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET submit_claim_token = NULL, submit_claim_expires_at = NULL, submit_claim_prior_state = NULL, updated_at = %s
					WHERE order_item_id = %d AND submit_claim_token = %s",
					$now,
					$order_item_id,
					$claim_token
				)
			);
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET submit_claim_token = NULL, submit_claim_expires_at = NULL, submit_claim_prior_state = NULL, updated_at = %s
				WHERE order_item_id = %d AND schedule_state = %s",
				$now,
				$order_item_id,
				ScheduleStates::SUPPRESSED
			)
		);
	}
}
