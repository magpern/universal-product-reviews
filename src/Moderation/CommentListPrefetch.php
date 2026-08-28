<?php
/**
 * Bounded per-page prefetch for Comments-admin columns.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

use UniversalProductReviews\Invitations\InviteRepository;

defined( 'ABSPATH' ) || exit;

final class CommentListPrefetch {

	/** @var array<int, array<string, mixed>>|null */
	private static ?array $map = null;

	/** @var int */
	private static int $query_count = 0;

	/**
	 * Prefetch association data for the given comment IDs (current list page only).
	 *
	 * @param list<int> $comment_ids Displayed comment IDs.
	 */
	public static function hydrate( array $comment_ids ): void {
		self::$map         = array();
		self::$query_count = 0;

		$comment_ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $comment_ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);

		if ( array() === $comment_ids ) {
			return;
		}

		$comments = get_comments(
			array(
				'comment__in' => $comment_ids,
				'number'      => count( $comment_ids ),
				'status'      => 'all',
				'type'        => '',
			)
		);
		++self::$query_count;

		$by_id = array();
		foreach ( (array) $comments as $comment ) {
			if ( $comment instanceof \WP_Comment ) {
				$by_id[ (int) $comment->comment_ID ] = $comment;
			}
		}

		// Prime meta for rating + order item in one pass via update_meta_cache.
		update_meta_cache( 'comment', $comment_ids );
		++self::$query_count;

		$meta_item_ids = array();
		foreach ( $comment_ids as $cid ) {
			$raw = get_comment_meta( $cid, '_upr_order_item_id', true );
			if ( is_numeric( $raw ) && (int) $raw > 0 ) {
				$meta_item_ids[] = (int) $raw;
			}
		}
		$meta_item_ids = array_values( array_unique( $meta_item_ids ) );

		$invites_by_comment = InviteRepository::find_by_review_comment_ids( $comment_ids );
		++self::$query_count;

		$invites_by_item = array();
		if ( array() !== $meta_item_ids ) {
			$invites_by_item = InviteRepository::find_by_order_item_ids( $meta_item_ids );
			++self::$query_count;
		}

		$product_ids = array();
		foreach ( $comment_ids as $cid ) {
			$comment = $by_id[ $cid ] ?? null;
			if ( ! $comment ) {
				continue;
			}
			$invite = $invites_by_comment[ $cid ] ?? null;
			$item   = ReviewContext::meta_order_item_id( $comment );
			if ( ! $invite && $item > 0 && isset( $invites_by_item[ $item ] ) ) {
				$invite = $invites_by_item[ $item ];
			}
			$ctx                 = ReviewContext::build( $comment, $invite );
			self::$map[ $cid ]   = $ctx;
			if ( $ctx['product_id'] > 0 ) {
				$product_ids[] = $ctx['product_id'];
			}
		}

		$product_ids = array_values( array_unique( $product_ids ) );
		if ( array() !== $product_ids ) {
			get_posts(
				array(
					'post_type'              => 'product',
					'post__in'               => $product_ids,
					'posts_per_page'         => count( $product_ids ),
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			++self::$query_count;
		}
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $comment_id ): ?array {
		if ( null === self::$map ) {
			return null;
		}
		return self::$map[ $comment_id ] ?? null;
	}

	/**
	 * Ensure context for a single comment (falls back to live build if not prefetched).
	 *
	 * @return array<string, mixed>
	 */
	public static function context_for( int $comment_id ): array {
		$cached = self::get( $comment_id );
		if ( null !== $cached ) {
			return $cached;
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return ReviewContext::build( array( 'comment_ID' => $comment_id ) );
		}
		$invite = null;
		$rows   = InviteRepository::find_by_review_comment_ids( array( $comment_id ) );
		if ( isset( $rows[ $comment_id ] ) ) {
			$invite = $rows[ $comment_id ];
		} else {
			$item = ReviewContext::meta_order_item_id( $comment );
			if ( $item > 0 ) {
				$by_item = InviteRepository::find_by_order_item_ids( array( $item ) );
				$invite  = $by_item[ $item ] ?? null;
			}
		}
		return ReviewContext::build( $comment, $invite );
	}

	public static function query_count(): int {
		return self::$query_count;
	}

	/**
	 * Test seam.
	 */
	public static function reset_for_tests(): void {
		self::$map         = null;
		self::$query_count = 0;
	}
}
