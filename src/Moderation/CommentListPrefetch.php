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
	 * Prefetch from already-displayed comment objects (no nested WP_Comment_Query).
	 *
	 * @param array<int, \WP_Comment|object|int> $comments Displayed comments from the_comments.
	 */
	public static function hydrate_from_comments( array $comments ): void {
		self::$map         = array();
		self::$query_count = 0;

		$by_id = array();
		foreach ( $comments as $comment ) {
			$resolved = self::resolve_comment( $comment );
			if ( ! $resolved instanceof \WP_Comment ) {
				continue;
			}
			$cid = (int) $resolved->comment_ID;
			if ( $cid > 0 ) {
				$by_id[ $cid ] = $resolved;
			}
		}

		$comment_ids = array_keys( $by_id );
		if ( array() === $comment_ids ) {
			return;
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
			$comment = $by_id[ $cid ];
			$invite  = $invites_by_comment[ $cid ] ?? null;
			$item    = ReviewContext::meta_order_item_id( $comment );
			if ( ! $invite && $item > 0 && isset( $invites_by_item[ $item ] ) ) {
				$invite = $invites_by_item[ $item ];
			}
			$ctx               = ReviewContext::build( $comment, $invite );
			self::$map[ $cid ] = $ctx;
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
	 * @param list<int> $comment_ids Displayed comment IDs (tests / fallback).
	 */
	public static function hydrate( array $comment_ids ): void {
		$objects = array();
		foreach ( $comment_ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			// get_comment() is cache-backed and does not run WP_Comment_Query / the_comments.
			$comment = get_comment( $id );
			if ( $comment instanceof \WP_Comment ) {
				$objects[] = $comment;
			}
		}
		self::hydrate_from_comments( $objects );
	}

	/**
	 * @param \WP_Comment|object|int|string $comment Raw list-table comment value.
	 */
	private static function resolve_comment( $comment ): ?\WP_Comment {
		if ( $comment instanceof \WP_Comment ) {
			return $comment;
		}
		if ( is_object( $comment ) && isset( $comment->comment_ID ) ) {
			$resolved = get_comment( (int) $comment->comment_ID );
			return $resolved instanceof \WP_Comment ? $resolved : null;
		}
		if ( is_numeric( $comment ) ) {
			$resolved = get_comment( (int) $comment );
			return $resolved instanceof \WP_Comment ? $resolved : null;
		}
		return null;
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
