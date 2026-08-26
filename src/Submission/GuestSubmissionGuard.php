<?php
/**
 * Guest submission guard for product reviews.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Submission;

use UniversalProductReviews\Moderation\ReviewScope;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;

defined( 'ABSPATH' ) || exit;

final class GuestSubmissionGuard {

	/**
	 * After WC_Comments::update_comment_type (priority 1 on preprocess_comment).
	 */
	public const FILTER_PRIORITY = 5;

	public static function register(): void {
		add_filter( 'preprocess_comment', array( self::class, 'block_guest_product_reviews' ), self::FILTER_PRIORITY );
	}

	/**
	 * Reject unauthenticated in-scope product reviews unless an M2 form session authorizes the product.
	 *
	 * @param array<string, mixed> $commentdata Comment data.
	 * @return array<string, mixed>
	 */
	public static function block_guest_product_reviews( array $commentdata ): array {
		if ( ! ReviewScope::is_product_review( $commentdata ) ) {
			return $commentdata;
		}

		if ( is_user_logged_in() ) {
			return $commentdata;
		}

		$product_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
		if ( $product_id > 0 && FormSessionAuthenticator::authorize_product( $product_id ) ) {
			return $commentdata;
		}

		wp_die(
			esc_html__( 'Product reviews require a logged-in account or a valid review invitation.', 'universal-product-reviews' ),
			esc_html__( 'Review submission unavailable', 'universal-product-reviews' ),
			array( 'response' => 403 )
		);
	}
}
