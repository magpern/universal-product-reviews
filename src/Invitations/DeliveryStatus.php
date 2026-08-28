<?php
/**
 * Public discoverability helper for delivery confirmation meta (M6 C18).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

defined( 'ABSPATH' ) || exit;

/**
 * Thin stable helper — does not prove host adapter wiring or operational delivery.
 */
final class DeliveryStatus {

	/**
	 * Whether core has recorded a delivery confirmation timestamp for the order.
	 *
	 * Uses public WooCommerce order APIs only. Invalid/missing orders → false.
	 */
	public static function has_confirmation( int $order_id ): bool {
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$raw = $order->get_meta( InvitationScheduler::META_DELIVERY_CONFIRMED_AT, true );
		return is_string( $raw ) && '' !== $raw;
	}
}
