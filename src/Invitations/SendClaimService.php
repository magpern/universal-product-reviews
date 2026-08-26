<?php
/**
 * Send claim state transitions.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

final class SendClaimService {

	public static function new_message_id(): string {
		return wp_generate_uuid4();
	}

	/**
	 * Claim items for an initial bundle.
	 *
	 * @param list<int> $order_item_ids
	 * @return array{bundle_id:string,message_id:string,claimed:list<int>}
	 */
	public static function claim_initial_bundle( array $order_item_ids ): array {
		$bundle_id   = self::new_message_id();
		$message_id  = self::new_message_id();
		$claimed     = array();
		$now         = current_time( 'mysql', true );

		foreach ( $order_item_ids as $order_item_id ) {
			$ok = InviteRepository::conditional_update(
				$order_item_id,
				array(
					'schedule_state'          => ScheduleStates::INITIAL_SENDING,
					'initial_send_started_at' => $now,
					'initial_message_id'      => $message_id,
					'bundle_id'               => $bundle_id,
					'initial_attempt_count'   => ( (int) ( InviteRepository::find( $order_item_id )['initial_attempt_count'] ?? 0 ) ) + 1,
					'initial_last_error'      => null,
				),
				array( 'schedule_state' => ScheduleStates::SCHEDULED )
			);
			if ( $ok ) {
				$claimed[] = $order_item_id;
			}
		}

		return array(
			'bundle_id'   => $bundle_id,
			'message_id'  => $message_id,
			'claimed'     => $claimed,
		);
	}

	public static function mark_initial_sent( int $order_item_id ): bool {
		return InviteRepository::conditional_update(
			$order_item_id,
			array(
				'schedule_state'     => ScheduleStates::INITIAL_SENT,
				'initial_sent_at'    => current_time( 'mysql', true ),
				'initial_last_error' => null,
			),
			array( 'schedule_state' => ScheduleStates::INITIAL_SENDING )
		);
	}

	public static function fail_initial( int $order_item_id, string $error ): void {
		InviteRepository::conditional_update(
			$order_item_id,
			array(
				'schedule_state'     => ScheduleStates::SCHEDULED,
				'initial_last_error' => substr( $error, 0, 191 ),
			),
			array( 'schedule_state' => ScheduleStates::INITIAL_SENDING )
		);
	}

	/**
	 * @return array{message_id:string}|null
	 */
	public static function claim_reminder( int $order_item_id ): ?array {
		$message_id = self::new_message_id();
		$row        = InviteRepository::find( $order_item_id );
		if ( ! $row || ScheduleStates::INITIAL_SENT !== $row['schedule_state'] ) {
			return null;
		}
		$ok = InviteRepository::conditional_update(
			$order_item_id,
			array(
				'schedule_state'           => ScheduleStates::REMINDER_SENDING,
				'reminder_send_started_at' => current_time( 'mysql', true ),
				'reminder_message_id'      => $message_id,
				'reminder_attempt_count'   => ( (int) $row['reminder_attempt_count'] ) + 1,
				'reminder_last_error'      => null,
			),
			array( 'schedule_state' => ScheduleStates::INITIAL_SENT )
		);
		return $ok ? array( 'message_id' => $message_id ) : null;
	}

	public static function mark_reminder_sent( int $order_item_id ): bool {
		return InviteRepository::conditional_update(
			$order_item_id,
			array(
				'schedule_state'      => ScheduleStates::REMINDER_SENT,
				'reminder_sent_at'    => current_time( 'mysql', true ),
				'reminder_last_error' => null,
			),
			array( 'schedule_state' => ScheduleStates::REMINDER_SENDING )
		);
	}

	public static function fail_reminder( int $order_item_id, string $error ): void {
		InviteRepository::conditional_update(
			$order_item_id,
			array(
				'schedule_state'      => ScheduleStates::INITIAL_SENT,
				'reminder_last_error' => substr( $error, 0, 191 ),
			),
			array( 'schedule_state' => ScheduleStates::REMINDER_SENDING )
		);
	}

	public static function recover_abandoned_claims(): int {
		$recovered = 0;
		foreach ( InviteRepository::find_stale_sending( Options::send_claim_stale_minutes() ) as $row ) {
			$id = (int) $row['order_item_id'];
			if ( ScheduleStates::INITIAL_SENDING === $row['schedule_state'] ) {
				InviteRepository::conditional_update(
					$id,
					array( 'schedule_state' => ScheduleStates::SCHEDULED ),
					array( 'schedule_state' => ScheduleStates::INITIAL_SENDING )
				);
				++$recovered;
			} elseif ( ScheduleStates::REMINDER_SENDING === $row['schedule_state'] ) {
				InviteRepository::conditional_update(
					$id,
					array( 'schedule_state' => ScheduleStates::INITIAL_SENT ),
					array( 'schedule_state' => ScheduleStates::REMINDER_SENDING )
				);
				++$recovered;
			}
		}
		return $recovered;
	}
}
