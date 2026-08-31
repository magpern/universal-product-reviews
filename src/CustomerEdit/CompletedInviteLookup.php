<?php
/**
 * Scoped completed-invite proof for M14 guest edit (E3).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Moderation\ReviewContext;
use UniversalProductReviews\Moderation\ReviewScope;

defined( 'ABSPATH' ) || exit;

final class CompletedInviteLookup {

	/**
	 * @param array<string, mixed> $invite_row Token row purpose=invite.
	 * @return array{invite:array<string,mixed>,item:array<string,mixed>,comment:\WP_Comment}|null
	 */
	public static function match_row( array $invite_row ): ?array {
		if ( 'invite' !== (string) ( $invite_row['purpose'] ?? '' ) ) {
			return null;
		}
		if ( empty( $invite_row['redeemed_at'] ) ) {
			return null;
		}
		if ( ! empty( $invite_row['revoked_at'] ) ) {
			return null;
		}

		$order_item_id = (int) ( $invite_row['order_item_id'] ?? 0 );
		$product_id    = (int) ( $invite_row['product_id'] ?? 0 );
		if ( $order_item_id <= 0 || $product_id <= 0 ) {
			return null;
		}

		$item = InviteRepository::find( $order_item_id );
		if ( ! is_array( $item ) ) {
			return null;
		}
		if ( (int) ( $item['product_id'] ?? 0 ) !== $product_id ) {
			return null;
		}

		$comment_id = (int) ( $item['review_comment_id'] ?? 0 );
		if ( $comment_id <= 0 ) {
			return null;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return null;
		}

		$data = $comment->to_array();
		if ( ! ReviewScope::is_product_review( $data ) ) {
			return null;
		}
		if ( (int) $comment->comment_parent !== 0 ) {
			return null;
		}
		if ( (int) $comment->comment_post_ID !== $product_id ) {
			return null;
		}
		if ( ReviewContext::meta_order_item_id( $comment ) !== $order_item_id ) {
			return null;
		}

		$status = wp_get_comment_status( $comment );
		if ( ! in_array( (string) $status, array( 'approved', 'hold', 'unapproved' ), true ) ) {
			return null;
		}
		$approved = (string) $comment->comment_approved;
		if ( ! in_array( $approved, array( '0', '1' ), true ) ) {
			return null;
		}

		if ( ! CustomerEditClock::is_in_window( $comment ) ) {
			return null;
		}

		return array(
			'invite'  => $invite_row,
			'item'    => $item,
			'comment' => $comment,
		);
	}
}
