<?php
/**
 * Review-scoped moderation hold for new product reviews.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

defined( 'ABSPATH' ) || exit;

final class ReviewModeration {

	public const FILTER_PRIORITY = 10;

	public static function register(): void {
		add_filter( 'pre_comment_approved', array( self::class, 'hold_new_product_reviews' ), self::FILTER_PRIORITY, 2 );
	}

	/**
	 * @param int|string|\WP_Error $approved   Proposed approval status.
	 * @param array<string, mixed> $commentdata Comment data.
	 * @return int|string|\WP_Error
	 */
	public static function hold_new_product_reviews( $approved, array $commentdata ) {
		if ( ! ReviewScope::is_product_review( $commentdata ) ) {
			return $approved;
		}

		// Validated staff replies: pass core's approval through unchanged (never force approve).
		if ( StaffReplyPolicy::is_validated_staff_reply( $commentdata ) ) {
			return $approved;
		}

		if ( self::is_approved_result( $approved ) ) {
			return 0;
		}

		return $approved;
	}

	/**
	 * @param mixed $approved Approval value from prior filters.
	 */
	public static function is_approved_result( $approved ): bool {
		return 1 === $approved || '1' === $approved;
	}
}
