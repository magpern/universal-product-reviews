<?php
/**
 * Pure helpers for UPR product-review list context and source labels.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

defined( 'ABSPATH' ) || exit;

final class ReviewContext {

	public const SOURCE_INVITATION = 'invitation_linked';
	public const SOURCE_UNLINKED   = 'unlinked_unknown';

	public const LABEL_INVITATION = 'Invitation-linked';
	public const LABEL_UNLINKED   = 'Unlinked/unknown';

	/**
	 * Whether a comment object/array is an in-scope UPR product review.
	 *
	 * @param \WP_Comment|object|array<string, mixed> $comment Comment.
	 */
	public static function is_in_scope( $comment ): bool {
		$data = self::comment_to_array( $comment );
		return ReviewScope::is_product_review( $data );
	}

	/**
	 * @param \WP_Comment|object|array<string, mixed> $comment Comment.
	 * @param array<string, mixed>|null               $invite  Optional invite row.
	 * @param int|null                                $order_item_id Meta order item id if known.
	 * @return self::SOURCE_*
	 */
	public static function source_key( $comment, ?array $invite = null, ?int $order_item_id = null ): string {
		if ( null === $order_item_id ) {
			$order_item_id = self::meta_order_item_id( $comment );
		}
		if ( $order_item_id > 0 ) {
			return self::SOURCE_INVITATION;
		}
		if ( is_array( $invite ) && ! empty( $invite['review_comment_id'] ) ) {
			return self::SOURCE_INVITATION;
		}
		return self::SOURCE_UNLINKED;
	}

	/**
	 * @param self::SOURCE_* $key Source key.
	 */
	public static function source_label( string $key ): string {
		return self::SOURCE_INVITATION === $key ? self::LABEL_INVITATION : self::LABEL_UNLINKED;
	}

	/**
	 * @param \WP_Comment|object|array<string, mixed> $comment Comment.
	 */
	public static function meta_order_item_id( $comment ): int {
		$id = self::comment_id( $comment );
		if ( $id <= 0 ) {
			return 0;
		}
		$raw = get_comment_meta( $id, '_upr_order_item_id', true );
		return is_numeric( $raw ) ? (int) $raw : 0;
	}

	/**
	 * @param \WP_Comment|object|array<string, mixed> $comment Comment.
	 */
	public static function rating( $comment ): ?int {
		$id = self::comment_id( $comment );
		if ( $id <= 0 ) {
			return null;
		}
		$raw = get_comment_meta( $id, 'rating', true );
		if ( '' === $raw || null === $raw || false === $raw ) {
			return null;
		}
		return is_numeric( $raw ) ? (int) $raw : null;
	}

	/**
	 * @param \WP_Comment|object|array<string, mixed> $comment Comment.
	 */
	public static function product_id( $comment ): int {
		$data = self::comment_to_array( $comment );
		return isset( $data['comment_post_ID'] ) ? (int) $data['comment_post_ID'] : 0;
	}

	/**
	 * @param \WP_Comment|object|array<string, mixed> $comment Comment.
	 * @return array{
	 *   comment_id:int,
	 *   product_id:int,
	 *   order_item_id:int,
	 *   order_id:int,
	 *   rating:?int,
	 *   source:string,
	 *   source_label:string,
	 *   invite:?array<string, mixed>
	 * }
	 */
	public static function build( $comment, ?array $invite = null ): array {
		$comment_id    = self::comment_id( $comment );
		$product_id    = self::product_id( $comment );
		$order_item_id = self::meta_order_item_id( $comment );
		$order_id      = 0;
		if ( is_array( $invite ) ) {
			$order_id = isset( $invite['order_id'] ) ? (int) $invite['order_id'] : 0;
			if ( $order_item_id <= 0 && ! empty( $invite['order_item_id'] ) ) {
				$order_item_id = (int) $invite['order_item_id'];
			}
		}
		$source = self::source_key( $comment, $invite, $order_item_id > 0 ? $order_item_id : null );
		return array(
			'comment_id'    => $comment_id,
			'product_id'    => $product_id,
			'order_item_id' => $order_item_id,
			'order_id'      => $order_id,
			'rating'        => self::rating( $comment ),
			'source'        => $source,
			'source_label'  => self::source_label( $source ),
			'invite'        => $invite,
		);
	}

	/**
	 * @param \WP_Comment|object|array<string, mixed> $comment Comment.
	 */
	public static function comment_id( $comment ): int {
		if ( is_array( $comment ) ) {
			return isset( $comment['comment_ID'] ) ? (int) $comment['comment_ID'] : 0;
		}
		if ( is_object( $comment ) && isset( $comment->comment_ID ) ) {
			return (int) $comment->comment_ID;
		}
		return 0;
	}

	/**
	 * @param \WP_Comment|object|array<string, mixed> $comment Comment.
	 * @return array<string, mixed>
	 */
	public static function comment_to_array( $comment ): array {
		if ( is_array( $comment ) ) {
			return $comment;
		}
		if ( $comment instanceof \WP_Comment ) {
			return $comment->to_array();
		}
		if ( is_object( $comment ) ) {
			return array(
				'comment_ID'      => isset( $comment->comment_ID ) ? (int) $comment->comment_ID : 0,
				'comment_post_ID' => isset( $comment->comment_post_ID ) ? (int) $comment->comment_post_ID : 0,
				'comment_type'    => isset( $comment->comment_type ) ? (string) $comment->comment_type : '',
				'comment_parent'  => isset( $comment->comment_parent ) ? (int) $comment->comment_parent : 0,
			);
		}
		return array();
	}
}
