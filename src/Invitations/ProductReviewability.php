<?php
/**
 * Canonical product reviewability.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

defined( 'ABSPATH' ) || exit;

final class ProductReviewability {

	public static function is_reviewable( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}
		$post = get_post( $product_id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return false;
		}
		$default = ( 'publish' === $post->post_status );
		return (bool) apply_filters( 'upr_product_is_reviewable', $default, $product_id );
	}

	/**
	 * Resolve canonical parent product for variations.
	 *
	 * @return array{product_id:int,variation_id:int|null}
	 */
	public static function canonical_from_item_product( int $product_id, int $variation_id = 0 ): array {
		$variation = $variation_id > 0 ? $variation_id : 0;
		$canonical = $product_id;

		if ( $variation > 0 && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $variation );
			if ( $product && $product->is_type( 'variation' ) ) {
				$parent = (int) $product->get_parent_id();
				if ( $parent > 0 ) {
					$canonical = $parent;
				}
			}
		} elseif ( function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $product_id );
			if ( $product && $product->is_type( 'variation' ) ) {
				$parent = (int) $product->get_parent_id();
				$variation = $product_id;
				$canonical = $parent > 0 ? $parent : $product_id;
			}
		}

		return array(
			'product_id'   => $canonical,
			'variation_id' => $variation > 0 ? $variation : null,
		);
	}
}
