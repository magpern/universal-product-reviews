<?php
/**
 * Durable per-item submission claim (before comment insert).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

defined( 'ABSPATH' ) || exit;

final class SubmitClaimService {

	public const TTL_MINUTES = 5;

	/**
	 * Acquire exclusive claim. Does not redeem invite tokens.
	 *
	 * @return array{token:string}|null
	 */
	public static function acquire( int $order_item_id ): ?array {
		global $wpdb;

		$row = InviteRepository::find( $order_item_id );
		if ( ! $row || ScheduleStates::is_terminal( (string) $row['schedule_state'] ) ) {
			return null;
		}
		if ( ! empty( $row['review_comment_id'] ) ) {
			return null;
		}

		// Active non-expired claim held by another request.
		if (
			ScheduleStates::SUBMITTING === $row['schedule_state']
			&& ! empty( $row['submit_claim_expires_at'] )
			&& strtotime( (string) $row['submit_claim_expires_at'] . ' UTC' ) > time()
		) {
			return null;
		}

		$token   = wp_generate_uuid4();
		$expires = gmdate( 'Y-m-d H:i:s', time() + ( self::TTL_MINUTES * MINUTE_IN_SECONDS ) );
		$prior   = (string) $row['schedule_state'];
		if ( ScheduleStates::SUBMITTING === $prior ) {
			$prior = (string) ( $row['submit_claim_prior_state'] ?? self::infer_prior_state( $row ) );
		}

		$table = InviteRepository::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET schedule_state = %s, submit_claim_token = %s, submit_claim_expires_at = %s, submit_claim_prior_state = %s, updated_at = %s
				WHERE order_item_id = %d
				AND review_comment_id IS NULL
				AND schedule_state NOT IN (%s, %s)
				AND (
					schedule_state <> %s
					OR submit_claim_expires_at IS NULL
					OR submit_claim_expires_at < %s
				)",
				ScheduleStates::SUBMITTING,
				$token,
				$expires,
				$prior,
				$now,
				$order_item_id,
				ScheduleStates::COMPLETED,
				ScheduleStates::SUPPRESSED,
				ScheduleStates::SUBMITTING,
				gmdate( 'Y-m-d H:i:s' )
			)
		);

		if ( 1 !== (int) $n ) {
			return null;
		}

		return array( 'token' => $token );
	}

	public static function release( int $order_item_id, string $claim_token ): void {
		$row = InviteRepository::find( $order_item_id );
		if ( ! $row || (string) ( $row['submit_claim_token'] ?? '' ) !== $claim_token ) {
			return;
		}
		$prior = (string) ( $row['submit_claim_prior_state'] ?? self::infer_prior_state( $row ) );
		InviteRepository::conditional_update(
			$order_item_id,
			array(
				'schedule_state'           => $prior,
				'submit_claim_token'       => null,
				'submit_claim_expires_at'  => null,
				'submit_claim_prior_state' => null,
			),
			array(
				'schedule_state'     => ScheduleStates::SUBMITTING,
				'submit_claim_token' => $claim_token,
			)
		);
	}

	public static function owns( int $order_item_id, string $claim_token ): bool {
		$row = InviteRepository::find( $order_item_id );
		if ( ! $row ) {
			return false;
		}
		return ScheduleStates::SUBMITTING === $row['schedule_state']
			&& (string) ( $row['submit_claim_token'] ?? '' ) === $claim_token
			&& ! empty( $row['submit_claim_expires_at'] )
			&& strtotime( (string) $row['submit_claim_expires_at'] . ' UTC' ) > time();
	}

	/**
	 * Recover expired submitting claims: repair if comment exists, else release.
	 *
	 * @return array{released:int,repaired:int}
	 */
	public static function recover_expired_claims(): array {
		global $wpdb;
		$released = 0;
		$repaired = 0;
		$table    = InviteRepository::table();
		$now      = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE schedule_state = %s AND (submit_claim_expires_at IS NULL OR submit_claim_expires_at < %s)",
				ScheduleStates::SUBMITTING,
				$now
			),
			ARRAY_A
		);

		foreach ( (array) $rows as $row ) {
			$item_id = (int) $row['order_item_id'];
			$comment = self::find_associated_comment( $item_id );
			if ( $comment ) {
				if ( CompletionService::finalize_from_comment( $item_id, $comment, (int) $row['order_id'] ) ) {
					++$repaired;
				} else {
					// Suppression (or other non-submitting terminal) won — discard orphan.
					$current = InviteRepository::find( $item_id );
					if ( $current && ScheduleStates::SUPPRESSED === $current['schedule_state'] ) {
						CompletionService::reject_comment( $comment );
						CompletionService::clear_claim_fields( $item_id, (string) ( $row['submit_claim_token'] ?? '' ) );
					}
				}
				continue;
			}
			$token = (string) ( $row['submit_claim_token'] ?? '' );
			if ( '' !== $token ) {
				self::release( $item_id, $token );
			} else {
				$prior = (string) ( $row['submit_claim_prior_state'] ?? self::infer_prior_state( $row ) );
				InviteRepository::conditional_update(
					$item_id,
					array(
						'schedule_state'           => $prior,
						'submit_claim_token'       => null,
						'submit_claim_expires_at'  => null,
						'submit_claim_prior_state' => null,
					),
					array( 'schedule_state' => ScheduleStates::SUBMITTING )
				);
			}
			++$released;
		}

		return array(
			'released' => $released,
			'repaired' => $repaired,
		);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function infer_prior_state( array $row ): string {
		if ( ! empty( $row['reminder_sent_at'] ) ) {
			return ScheduleStates::REMINDER_SENT;
		}
		if ( ! empty( $row['initial_sent_at'] ) ) {
			return ScheduleStates::INITIAL_SENT;
		}
		if ( ! empty( $row['delay_until'] ) ) {
			return ScheduleStates::DELAYED;
		}
		return ScheduleStates::SCHEDULED;
	}

	private static function find_associated_comment( int $order_item_id ): int {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT comment_id FROM {$wpdb->commentmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY comment_id ASC LIMIT 1",
				'_upr_order_item_id',
				(string) $order_item_id
			)
		);
		return (int) $id;
	}
}
