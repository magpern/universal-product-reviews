<?php
/**
 * Finalize invite completion after a review comment exists.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Scheduling\Jobs;
use UniversalProductReviews\Tokens\SessionCookie;
use UniversalProductReviews\Tokens\TokenRepository;
use UniversalProductReviews\Tokens\TokenService;

defined( 'ABSPATH' ) || exit;

final class CompletionService {

	/**
	 * Atomically mark item completed, redeem/revoke tokens, clear claim, unschedule, audit.
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
		if ( ! empty( $row['review_comment_id'] ) && (int) $row['review_comment_id'] !== $comment_id ) {
			return false;
		}

		$table = InviteRepository::table();
		$now   = current_time( 'mysql', true );

		$wpdb->query( 'START TRANSACTION' );
		try {
			if ( null !== $claim_token && '' !== $claim_token ) {
				$n = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET schedule_state = %s, review_completed_at = %s, review_comment_id = %d,
						submit_claim_token = NULL, submit_claim_expires_at = NULL, submit_claim_prior_state = NULL, updated_at = %s
						WHERE order_item_id = %d AND review_comment_id IS NULL AND submit_claim_token = %s",
						ScheduleStates::COMPLETED,
						$now,
						$comment_id,
						$now,
						$order_item_id,
						$claim_token
					)
				);
			} else {
				$n = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table} SET schedule_state = %s, review_completed_at = %s, review_comment_id = %d,
						submit_claim_token = NULL, submit_claim_expires_at = NULL, submit_claim_prior_state = NULL, updated_at = %s
						WHERE order_item_id = %d AND review_comment_id IS NULL AND schedule_state <> %s",
						ScheduleStates::COMPLETED,
						$now,
						$comment_id,
						$now,
						$order_item_id,
						ScheduleStates::COMPLETED
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

	public static function finalize_from_comment( int $order_item_id, int $comment_id, int $order_id ): bool {
		$active          = TokenRepository::find_active_invite( $order_item_id );
		$invite_token_id = $active ? (int) $active['id'] : null;
		return self::finalize( $order_item_id, $comment_id, $order_id, $invite_token_id, null );
	}
}
