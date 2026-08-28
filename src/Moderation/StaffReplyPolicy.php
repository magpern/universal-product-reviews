<?php
/**
 * Verified staff-reply exemption predicate for ReviewModeration.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

defined( 'ABSPATH' ) || exit;

final class StaffReplyPolicy {

	public const REPLY_ACTION = 'replyto-comment';

	/**
	 * Whether the current request + commentdata qualify as a validated native staff reply.
	 *
	 * Fail closed on missing/invalid nonce, frontend posts, arbitrary AJAX, nested replies,
	 * parent forgery, or missing capabilities.
	 *
	 * @param array<string, mixed> $commentdata Comment data being inserted.
	 */
	public static function is_validated_staff_reply( array $commentdata ): bool {
		if ( ! self::is_exact_native_reply_request() ) {
			return false;
		}
		if ( ! self::verify_reply_nonce() ) {
			return false;
		}
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return false;
		}

		$product_id = isset( $commentdata['comment_post_ID'] ) ? (int) $commentdata['comment_post_ID'] : 0;
		if ( $product_id <= 0 || ! current_user_can( 'edit_post', $product_id ) ) {
			return false;
		}

		$parent_id = isset( $commentdata['comment_parent'] ) ? (int) $commentdata['comment_parent'] : 0;
		if ( $parent_id <= 0 ) {
			return false;
		}

		$parent = get_comment( $parent_id );
		if ( ! $parent instanceof \WP_Comment ) {
			return false;
		}

		// Parent must be a top-level in-scope product review.
		if ( (int) $parent->comment_parent !== 0 ) {
			return false;
		}
		if ( ! ReviewContext::is_in_scope( $parent ) ) {
			return false;
		}

		// Same product post.
		if ( (int) $parent->comment_post_ID !== $product_id ) {
			return false;
		}

		// Depth exactly one: reply's parent is the top-level review (already ensured).
		// Reply itself must not claim a deeper chain via mismatched parent.

		// Reply must be in-scope product review type (native admin replies typically are).
		if ( ! ReviewScope::is_product_review( $commentdata ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Exact WordPress authenticated native reply AJAX action only.
	 */
	public static function is_exact_native_reply_request(): bool {
		if ( ! self::is_doing_ajax() ) {
			return false;
		}
		// Frontend comment posts never set this action.
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::REPLY_ACTION === $action;
	}

	private static function is_doing_ajax(): bool {
		if ( function_exists( 'wp_doing_ajax' ) ) {
			return (bool) wp_doing_ajax();
		}
		return defined( 'DOING_AJAX' ) && DOING_AJAX;
	}

	/**
	 * Independently verify the replyto-comment nonce via public WP nonce APIs.
	 */
	public static function verify_reply_nonce(): bool {
		$nonce = '';
		if ( isset( $_REQUEST['_ajax_nonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$nonce = sanitize_text_field( wp_unslash( (string) $_REQUEST['_ajax_nonce'] ) );
		} elseif ( isset( $_REQUEST['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$nonce = sanitize_text_field( wp_unslash( (string) $_REQUEST['_wpnonce'] ) );
		}
		if ( '' === $nonce ) {
			return false;
		}
		return (bool) wp_verify_nonce( $nonce, self::REPLY_ACTION );
	}
}
