<?php
/**
 * Availability-aligned native product-review submission guard.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Rejects native product-review inserts when UPR availability says can_submit=false.
 *
 * Runs after {@see GuestSubmissionGuard} so M2 guests still require form session
 * and request-local arm before reaching this filter. Does not register
 * `comments_open` and does not authorize guests on the native PDP route.
 */
final class NativeSubmissionGuard {

	/**
	 * After GuestSubmissionGuard (priority 5) and WC_Comments::update_comment_type (priority 1).
	 */
	public const FILTER_PRIORITY = 15;

	public static function register(): void {
		add_filter( 'preprocess_comment', array( self::class, 'reject_unavailable_product_reviews' ), self::FILTER_PRIORITY );
	}

	/**
	 * @param array<string, mixed> $commentdata Comment data.
	 * @return array<string, mixed>
	 */
	public static function reject_unavailable_product_reviews( array $commentdata ): array {
		if ( ! self::is_product_review_target( $commentdata ) ) {
			return $commentdata;
		}

		$product_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
		$user_id    = get_current_user_id();

		if ( ReviewAvailability::allows_submit( $product_id, $user_id ) ) {
			return $commentdata;
		}

		wp_die(
			esc_html__( 'Product review submission is not available for this product.', 'universal-product-reviews' ),
			esc_html__( 'Review submission unavailable', 'universal-product-reviews' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Scope by canonical product post ID. Comment type is read only after WC has had
	 * a chance to normalise product review POSTs (priority 1); enforcement is not
	 * skipped solely because an attacker omitted or forged `comment_type` on a
	 * non-product post.
	 *
	 * @param array<string, mixed> $commentdata Comment data.
	 */
	private static function is_product_review_target( array $commentdata ): bool {
		$post_id = (int) ( $commentdata['comment_post_ID'] ?? 0 );
		if ( $post_id <= 0 || 'product' !== get_post_type( $post_id ) ) {
			return false;
		}

		// After WC_Comments::update_comment_type, native product-review POSTs are type "review".
		// Non-review comments on products (type remains "comment") stay out of scope.
		$comment_type = isset( $commentdata['comment_type'] ) ? (string) $commentdata['comment_type'] : '';
		return 'review' === $comment_type;
	}
}
