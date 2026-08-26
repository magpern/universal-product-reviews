<?php
/**
 * Invite item repository.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

defined( 'ABSPATH' ) || exit;

final class InviteRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'upr_invite_items';
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find( int $order_item_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_item_id = %d LIMIT 1', $order_item_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function upsert( int $order_item_id, array $data ): void {
		global $wpdb;
		$existing = self::find( $order_item_id );
		$now      = current_time( 'mysql', true );
		$data['updated_at'] = $now;
		if ( $existing ) {
			$wpdb->update( self::table(), $data, array( 'order_item_id' => $order_item_id ) );
			return;
		}
		$data['order_item_id'] = $order_item_id;
		$data['created_at']    = $now;
		if ( ! isset( $data['schedule_state'] ) ) {
			$data['schedule_state'] = ScheduleStates::PENDING_ELIGIBILITY;
		}
		$wpdb->insert( self::table(), $data );
	}

	/**
	 * @param array<string, mixed> $set
	 * @param array<string, mixed> $where_extra Additional WHERE equality.
	 */
	public static function conditional_update( int $order_item_id, array $set, array $where_extra = array() ): bool {
		global $wpdb;
		$set['updated_at'] = current_time( 'mysql', true );
		$where             = array_merge( array( 'order_item_id' => $order_item_id ), $where_extra );
		$n                 = $wpdb->update( self::table(), $set, $where );
		return false !== $n && $n > 0;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function find_by_order( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function find_due_for_initial( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE order_id = %d AND schedule_state = %s AND (eligible_at IS NULL OR eligible_at <= UTC_TIMESTAMP()) AND (delay_until IS NULL OR delay_until <= UTC_TIMESTAMP())',
				$order_id,
				ScheduleStates::SCHEDULED
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function find_stale_sending( int $stale_minutes ): array {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $stale_minutes * MINUTE_IN_SECONDS ) );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE (schedule_state = %s AND initial_send_started_at IS NOT NULL AND initial_send_started_at < %s AND initial_sent_at IS NULL) OR (schedule_state = %s AND reminder_send_started_at IS NOT NULL AND reminder_send_started_at < %s AND reminder_sent_at IS NULL)',
				ScheduleStates::INITIAL_SENDING,
				$cutoff,
				ScheduleStates::REMINDER_SENDING,
				$cutoff
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function find_by_product( int $product_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE product_id = %d', $product_id ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
