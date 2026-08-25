<?php
/**
 * Product review scope detection.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

defined( 'ABSPATH' ) || exit;

final class ReviewScope {

	/**
	 * @param array<string, mixed> $commentdata Comment data from WordPress hooks.
	 */
	public static function is_product_review( array $commentdata ): bool {
		$post_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
		if ( $post_id <= 0 ) {
			return false;
		}

		$comment_type = isset( $commentdata['comment_type'] ) ? (string) $commentdata['comment_type'] : '';

		return 'review' === $comment_type && 'product' === get_post_type( $post_id );
	}
}
