<?php
/**
 * Line-item invitation eligibility.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

defined( 'ABSPATH' ) || exit;

final class Eligibility {

	/**
	 * @param object $item WC order item product.
	 * @return array{eligible:bool,reason:?string,product_id?:int,variation_id?:int|null}
	 */
	public static function evaluate_item( \WC_Order $order, $item ): array {
		if ( ! is_object( $item ) || ! is_callable( array( $item, 'get_type' ) ) ) {
			return array( 'eligible' => false, 'reason' => 'not_product_line' );
		}
		if ( 'line_item' !== $item->get_type() ) {
			return array( 'eligible' => false, 'reason' => 'not_product_line' );
		}

		$product_id   = (int) $item->get_product_id();
		$variation_id = (int) $item->get_variation_id();
		$canon        = ProductReviewability::canonical_from_item_product( $product_id, $variation_id );

		$line_total = (float) $item->get_total();
		$include_zero = (bool) apply_filters( 'upr_include_zero_total_items', false, $order->get_id(), (int) $item->get_id() );
		if ( $line_total <= 0 && ! $include_zero ) {
			return array( 'eligible' => false, 'reason' => 'zero_total' );
		}

		$reviewable_item = (bool) apply_filters( 'upr_item_is_reviewable', true, $order->get_id(), (int) $item->get_id(), $item );
		if ( ! $reviewable_item ) {
			return array( 'eligible' => false, 'reason' => 'item_not_reviewable' );
		}

		if ( ! ProductReviewability::is_reviewable( $canon['product_id'] ) ) {
			return array( 'eligible' => false, 'reason' => 'product_not_reviewable' );
		}

		if ( $order->has_status( array( 'cancelled', 'failed', 'refunded' ) ) ) {
			return array( 'eligible' => false, 'reason' => 'order_status' );
		}

		if ( self::is_item_refunded( $order, (int) $item->get_id() ) ) {
			return array( 'eligible' => false, 'reason' => 'refunded' );
		}

		if ( self::is_opted_out( $order ) ) {
			return array( 'eligible' => false, 'reason' => 'opt_out' );
		}

		$decision = apply_filters(
			'upr_review_invitation_action',
			array( 'action' => 'none' ),
			$order->get_id(),
			(int) $item->get_id()
		);
		$action = is_array( $decision ) ? (string) ( $decision['action'] ?? 'none' ) : 'none';
		if ( 'suppress' === $action ) {
			return array( 'eligible' => false, 'reason' => 'adapter_suppress' );
		}

		return array(
			'eligible'     => true,
			'reason'       => null,
			'product_id'   => $canon['product_id'],
			'variation_id' => $canon['variation_id'],
			'delay'        => ( 'delay' === $action ) ? $decision : null,
		);
	}

	private static function is_opted_out( \WC_Order $order ): bool {
		if ( 'yes' === $order->get_meta( '_upr_review_opt_out' ) ) {
			return true;
		}
		$customer_id = (int) $order->get_customer_id();
		if ( $customer_id > 0 && 'yes' === get_user_meta( $customer_id, '_upr_review_opt_out', true ) ) {
			return true;
		}
		return false;
	}

	private static function is_item_refunded( \WC_Order $order, int $order_item_id ): bool {
		$qty = 0;
		foreach ( $order->get_items() as $item ) {
			if ( (int) $item->get_id() === $order_item_id ) {
				$qty = (int) $item->get_quantity();
				break;
			}
		}
		if ( $qty <= 0 ) {
			return true;
		}
		$refunded = 0;
		foreach ( $order->get_refunds() as $refund ) {
			foreach ( $refund->get_items() as $refund_item ) {
				if ( (int) $refund_item->get_meta( '_refunded_item_id' ) === $order_item_id ) {
					$refunded += abs( (int) $refund_item->get_quantity() );
				}
			}
		}
		return $refunded >= $qty;
	}
}
