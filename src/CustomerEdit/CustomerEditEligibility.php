<?php
/**
 * E2 / E8–E11 eligibility for customer edits.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

use UniversalProductReviews\Moderation\ReviewScope;

defined( 'ABSPATH' ) || exit;

final class CustomerEditEligibility {

	public static function is_in_scope_review( \WP_Comment $comment ): bool {
		if ( (int) $comment->comment_parent !== 0 ) {
			return false;
		}
		return ReviewScope::is_product_review( $comment->to_array() );
	}

	public static function status_allows_edit( \WP_Comment $comment ): bool {
		$approved = (string) $comment->comment_approved;
		return in_array( $approved, array( '0', '1' ), true );
	}

	/**
	 * Claim prior_status label (approve vs hold) — never spam/trash.
	 */
	public static function prior_status_label( \WP_Comment $comment ): string {
		return '1' === (string) $comment->comment_approved ? 'approve' : 'hold';
	}

	/**
	 * Logged-in PDP author (E2). Invitation linkage is not a substitute.
	 */
	public static function logged_in_may_edit( \WP_Comment $comment, int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! self::is_in_scope_review( $comment ) ) {
			return false;
		}
		if ( (int) $comment->user_id !== $user_id || (int) $comment->user_id <= 0 ) {
			return false;
		}
		if ( ! self::status_allows_edit( $comment ) ) {
			return false;
		}
		if ( ! CustomerEditClock::is_in_window( $comment ) ) {
			return false;
		}
		$product_id = (int) $comment->comment_post_ID;
		if ( $product_id <= 0 || ! function_exists( 'wc_customer_bought_product' ) ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}
		$email = (string) $user->user_email;
		if ( ! wc_customer_bought_product( $email, $user_id, $product_id ) ) {
			return false;
		}
		return true;
	}
}
