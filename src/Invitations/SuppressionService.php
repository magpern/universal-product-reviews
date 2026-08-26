<?php
/**
 * Suppression and token revocation.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Scheduling\Jobs;
use UniversalProductReviews\Tokens\TokenRepository;

defined( 'ABSPATH' ) || exit;

final class SuppressionService {

	public static function suppress_item( int $order_item_id, string $code, ?int $order_id = null ): void {
		global $wpdb;

		$row = InviteRepository::find( $order_item_id );
		if ( $row && ScheduleStates::COMPLETED === $row['schedule_state'] ) {
			return;
		}

		$code  = substr( $code, 0, 64 );
		$table = InviteRepository::table();
		$now   = current_time( 'mysql', true );

		if ( ! $row ) {
			InviteRepository::upsert(
				$order_item_id,
				array(
					'schedule_state'   => ScheduleStates::SUPPRESSED,
					'suppression_code' => $code,
					'order_id'         => $order_id ?? 0,
					'product_id'       => 0,
				)
			);
		} else {
			// Atomic: never overwrite completed; clear any in-flight submit claim.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$n = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET schedule_state = %s, suppression_code = %s,
					submit_claim_token = NULL, submit_claim_expires_at = NULL, submit_claim_prior_state = NULL, updated_at = %s
					WHERE order_item_id = %d AND schedule_state <> %s",
					ScheduleStates::SUPPRESSED,
					$code,
					$now,
					$order_item_id,
					ScheduleStates::COMPLETED
				)
			);
			if ( 1 !== (int) $n ) {
				$again = InviteRepository::find( $order_item_id );
				if ( $again && ScheduleStates::COMPLETED === $again['schedule_state'] ) {
					return;
				}
				if ( $again && ScheduleStates::SUPPRESSED === $again['schedule_state'] ) {
					TokenRepository::revoke_for_item( $order_item_id );
					Jobs::unschedule_item( $order_item_id );
					return;
				}
			}
		}

		TokenRepository::revoke_for_item( $order_item_id );
		Jobs::unschedule_item( $order_item_id );
		AuditLogger::log( 'invite.suppressed', 'system', $order_id ?? ( $row['order_id'] ?? null ), $order_item_id, array( 'code' => $code ) );
	}

	public static function suppress_product_not_reviewable( int $product_id ): void {
		foreach ( InviteRepository::find_by_product( $product_id ) as $row ) {
			if ( ScheduleStates::is_terminal( (string) $row['schedule_state'] ) ) {
				continue;
			}
			self::suppress_item( (int) $row['order_item_id'], 'product_not_reviewable', (int) $row['order_id'] );
		}
	}

	public static function register(): void {
		add_action( 'woocommerce_order_status_cancelled', array( self::class, 'on_order_cancelled' ), 10, 1 );
		add_action( 'woocommerce_order_fully_refunded', array( self::class, 'on_order_cancelled' ), 10, 1 );
		add_action( 'woocommerce_order_partially_refunded', array( self::class, 'on_partial_refund' ), 10, 2 );
		add_action( 'transition_post_status', array( self::class, 'on_product_status' ), 10, 3 );
	}

	public static function on_order_cancelled( int $order_id ): void {
		foreach ( InviteRepository::find_by_order( $order_id ) as $row ) {
			self::suppress_item( (int) $row['order_item_id'], 'cancel', $order_id );
		}
	}

	public static function on_partial_refund( int $order_id, int $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$eval = Eligibility::evaluate_item( $order, $item );
			if ( ! $eval['eligible'] && 'refunded' === ( $eval['reason'] ?? '' ) ) {
				self::suppress_item( (int) $item->get_id(), 'refund', $order_id );
			}
		}
		unset( $refund_id );
	}

	public static function on_product_status( string $new, string $old, \WP_Post $post ): void {
		if ( 'product' !== $post->post_type || $new === $old ) {
			return;
		}
		if ( ! ProductReviewability::is_reviewable( (int) $post->ID ) ) {
			self::suppress_product_not_reviewable( (int) $post->ID );
		}
	}
}
