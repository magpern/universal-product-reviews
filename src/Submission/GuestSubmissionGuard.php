<?php
/**
 * Interim M1 guest submission guard for product reviews.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Submission;

use UniversalProductReviews\Moderation\ReviewScope;

defined( 'ABSPATH' ) || exit;

final class GuestSubmissionGuard {

	/**
	 * After WC_Comments::update_comment_type (priority 1).
	 */
	public const FILTER_PRIORITY = 5;

	public static function register(): void {
		add_filter( 'preprocess_comment', array( self::class, 'block_guest_product_reviews' ), self::FILTER_PRIORITY );
	}

	/**
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

		if ( self::should_block_without_die() ) {
			$commentdata['comment_approved'] = 'trash';
			return $commentdata;
		}

		wp_die(
			esc_html__( 'Product reviews require a logged-in account.', 'universal-product-reviews' ),
			esc_html__( 'Review submission unavailable', 'universal-product-reviews' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Integration tests set this to avoid halting the runner.
	 */
	public static function should_block_without_die(): bool {
		return (bool) apply_filters( 'upr_test_guest_block_without_die', false );
	}
}
