<?php
/**
 * Invitation scheduling from delivery / completed fallback.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Scheduling\Jobs;

defined( 'ABSPATH' ) || exit;

final class InvitationScheduler {

	public static function register(): void {
		add_action( 'upr_order_delivery_confirmed', array( self::class, 'on_delivery_confirmed' ), 10, 2 );
		add_action( 'upr_order_delivery_invalidated', array( self::class, 'on_delivery_invalidated' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'on_order_completed' ), 20, 1 );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function on_delivery_confirmed( int $order_id, array $context = array() ): void {
		unset( $context );
		Jobs::schedule_order_items( $order_id, 'adapter' );
	}

	public static function on_delivery_invalidated( int $order_id, string $reason ): void {
		foreach ( InviteRepository::find_by_order( $order_id ) as $row ) {
			if ( ! ScheduleStates::is_terminal( (string) $row['schedule_state'] ) ) {
				SuppressionService::suppress_item( (int) $row['order_item_id'], 'delivery_invalidated:' . $reason, $order_id );
			}
		}
	}

	public static function on_order_completed( int $order_id ): void {
		$delivered = (bool) apply_filters( 'upr_is_order_delivered', false, $order_id );
		if ( $delivered ) {
			return;
		}
		Jobs::schedule_order_items( $order_id, 'fallback' );
	}

	/**
	 * Create/update invite rows and enqueue send when due.
	 */
	public static function schedule_order( int $order_id, string $delivery_source ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$delay_days = 'adapter' === $delivery_source
			? Options::delay_days_after_delivery()
			: Options::delay_days_fallback_completed();
		$eligible_at = gmdate( 'Y-m-d H:i:s', time() + ( $delay_days * DAY_IN_SECONDS ) );

		foreach ( $order->get_items() as $item ) {
			$order_item_id = (int) $item->get_id();
			$eval          = Eligibility::evaluate_item( $order, $item );
			if ( ! $eval['eligible'] ) {
				$existing = InviteRepository::find( $order_item_id );
				if ( $existing && ! ScheduleStates::is_terminal( (string) $existing['schedule_state'] ) ) {
					SuppressionService::suppress_item( $order_item_id, (string) ( $eval['reason'] ?? 'ineligible' ), $order_id );
				}
				continue;
			}

			$existing = InviteRepository::find( $order_item_id );
			if ( $existing && ScheduleStates::is_terminal( (string) $existing['schedule_state'] ) ) {
				continue;
			}

			$state = ScheduleStates::SCHEDULED;
			$delay_until = null;
			if ( ! empty( $eval['delay'] ) && is_array( $eval['delay'] ) ) {
				$state = ScheduleStates::DELAYED;
				if ( ! empty( $eval['delay']['delay_until'] ) ) {
					$delay_until = gmdate( 'Y-m-d H:i:s', (int) $eval['delay']['delay_until'] );
				}
			}

			InviteRepository::upsert(
				$order_item_id,
				array(
					'order_id'         => $order_id,
					'product_id'       => (int) $eval['product_id'],
					'variation_id'     => $eval['variation_id'],
					'eligible_at'      => $eligible_at,
					'schedule_state'   => $state,
					'delay_until'      => $delay_until,
					'delivery_source'  => $delivery_source,
					'suppression_code' => null,
				)
			);

			AuditLogger::log(
				'invite.scheduled',
				'system',
				$order_id,
				$order_item_id,
				array(
					'delivery_source' => $delivery_source,
					'eligible_at'     => $eligible_at,
				)
			);
		}

		Jobs::schedule_initial_bundle( $order_id, $eligible_at );
	}
}
