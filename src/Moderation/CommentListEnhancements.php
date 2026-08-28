<?php
/**
 * Native Comments-admin columns, views, and filters for UPR product reviews.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

use UniversalProductReviews\Ai\Eligibility;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

final class CommentListEnhancements {

	public const VIEW_PRODUCT_REVIEWS = 'product_reviews';
	public const VIEW_PENDING         = 'pending';
	public const SOURCE_INVITATION    = 'invitation';
	public const SOURCE_ALL           = 'all';

	/** Request-local reentrancy guard for the_comments → prefetch. */
	private static bool $prefetching = false;

	public static function register(): void {
		add_action( 'current_screen', array( self::class, 'on_current_screen' ) );
	}

	/**
	 * Test seam: clear reentrancy guard between tests.
	 */
	public static function reset_for_tests(): void {
		self::$prefetching = false;
	}

	/**
	 * Attach Comments-list hooks only on edit-comments.php.
	 *
	 * @param \WP_Screen|null $screen Screen.
	 */
	public static function on_current_screen( $screen ): void {
		if ( ! $screen || 'edit-comments' !== $screen->id ) {
			return;
		}

		add_filter( 'manage_edit-comments_columns', array( self::class, 'columns' ) );
		add_action( 'manage_comments_custom_column', array( self::class, 'render_column' ), 10, 2 );
		add_action( 'restrict_manage_comments', array( self::class, 'render_source_filter' ) );
		add_filter( 'comment_status_links', array( self::class, 'view_links' ) );
		add_action( 'pre_get_comments', array( self::class, 'constrain_query' ) );
		add_filter( 'comments_clauses', array( self::class, 'invitation_linked_clauses' ), 10, 2 );
		add_action( 'the_comments', array( self::class, 'prefetch_page' ), 10, 2 );
	}

	/**
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$columns['upr_product'] = __( 'Product', 'universal-product-reviews' );
		$columns['upr_rating']  = __( 'Rating', 'universal-product-reviews' );
		$columns['upr_source']  = __( 'Source', 'universal-product-reviews' );
		$columns['upr_order']   = __( 'Order', 'universal-product-reviews' );
		$columns['upr_ai']      = __( 'AI advisory', 'universal-product-reviews' );
		return $columns;
	}

	/**
	 * @param string $column     Column id.
	 * @param string $comment_id Comment id string from WP.
	 */
	public static function render_column( string $column, $comment_id ): void {
		$comment_id = (int) $comment_id;
		if ( $comment_id <= 0 ) {
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment || ! ReviewContext::is_in_scope( $comment ) ) {
			if ( in_array( $column, array( 'upr_product', 'upr_rating', 'upr_source', 'upr_order', 'upr_ai' ), true ) ) {
				echo '&mdash;';
			}
			return;
		}

		$ctx = CommentListPrefetch::context_for( $comment_id );

		switch ( $column ) {
			case 'upr_product':
				self::render_product( $ctx );
				break;
			case 'upr_rating':
				echo null !== $ctx['rating'] ? esc_html( (string) $ctx['rating'] ) : '&mdash;';
				break;
			case 'upr_source':
				echo esc_html( (string) $ctx['source_label'] );
				break;
			case 'upr_order':
				self::render_order( $ctx );
				break;
			case 'upr_ai':
				self::render_ai_advisory( $comment_id, $ctx );
				break;
		}
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_product( array $ctx ): void {
		$product_id = (int) $ctx['product_id'];
		if ( $product_id <= 0 ) {
			echo '&mdash;';
			return;
		}
		$title = get_the_title( $product_id );
		if ( '' === $title ) {
			$title = sprintf( '#%d', $product_id );
		}
		if ( current_user_can( 'edit_post', $product_id ) ) {
			$url = get_edit_post_link( $product_id, 'raw' );
			if ( $url ) {
				printf(
					'<a href="%s">%s</a>',
					esc_url( $url ),
					esc_html( $title )
				);
				return;
			}
		}
		echo esc_html( $title );
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_order( array $ctx ): void {
		$order_id = (int) $ctx['order_id'];
		if ( $order_id <= 0 ) {
			echo '&mdash;';
			return;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			echo '&mdash;';
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			echo '&mdash;';
			return;
		}
		if ( ! current_user_can( 'edit_post', $order_id ) ) {
			echo '&mdash;';
			return;
		}
		$url = $order->get_edit_order_url();
		printf(
			'<a href="%s">#%d</a>',
			esc_url( $url ),
			$order_id
		);
	}

	/**
	 * @param array<string, mixed> $ctx Context.
	 */
	private static function render_ai_advisory( int $comment_id, array $ctx ): void {
		$assessment = isset( $ctx['ai_assessment'] ) && is_array( $ctx['ai_assessment'] ) ? $ctx['ai_assessment'] : null;

		if ( null === $assessment ) {
			echo '&mdash;';
		} else {
			echo esc_html( self::format_ai_advisory( $assessment ) );
		}

		self::maybe_render_reanalyse_link( $comment_id );
	}

	/**
	 * @param array<string, mixed> $assessment Terminal assessment row.
	 */
	private static function format_ai_advisory( array $assessment ): string {
		$parts = array();

		$state = isset( $assessment['state'] ) ? (string) $assessment['state'] : '';
		if ( '' !== $state ) {
			$parts[] = $state;
		}

		if ( 'completed' === $state && isset( $assessment['publication_safety_score'] ) && is_numeric( $assessment['publication_safety_score'] ) ) {
			$parts[] = (string) (int) $assessment['publication_safety_score'];
		}

		$confidence = isset( $assessment['confidence'] ) ? (string) $assessment['confidence'] : '';
		if ( '' !== $confidence ) {
			$parts[] = $confidence;
		}

		$labels = self::reason_code_labels( $assessment['reason_codes'] ?? null );
		if ( array() !== $labels ) {
			$parts[] = implode( ', ', $labels );
		}

		return array() !== $parts ? implode( ' · ', $parts ) : '—';
	}

	/**
	 * @param mixed $raw JSON string or null.
	 * @return list<string>
	 */
	private static function reason_code_labels( $raw ): array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		$labels = array();
		foreach ( $decoded as $code ) {
			if ( ! is_string( $code ) || ! PolicyAllowlist::is_reason_code( $code ) ) {
				continue;
			}
			$labels[] = str_replace( '_', ' ', $code );
			if ( count( $labels ) >= PolicyAllowlist::MAX_REASON_CODES ) {
				break;
			}
		}
		return $labels;
	}

	private static function maybe_render_reanalyse_link( int $comment_id ): void {
		if ( ! Options::local_ai_shadow_enabled() || ! current_user_can( 'moderate_comments' ) ) {
			return;
		}
		if ( ! Eligibility::is_ai_assessable( $comment_id ) ) {
			return;
		}

		echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;margin-left:4px;">';
		wp_nonce_field( 'upr_ai_reanalyze_' . $comment_id );
		echo '<input type="hidden" name="action" value="upr_ai_reanalyze" />';
		echo '<input type="hidden" name="comment_id" value="' . esc_attr( (string) $comment_id ) . '" />';
		submit_button( __( 'Re-analyse', 'universal-product-reviews' ), 'link', 'submit', false );
		echo '</form>';
	}

	public static function render_source_filter(): void {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return;
		}
		$current = self::sanitised_source();
		echo '<label class="screen-reader-text" for="upr_source">' . esc_html__( 'UPR source', 'universal-product-reviews' ) . '</label>';
		echo '<select name="upr_source" id="upr_source">';
		printf(
			'<option value="" %s>%s</option>',
			selected( $current, '', false ),
			esc_html__( 'All UPR sources', 'universal-product-reviews' )
		);
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( self::SOURCE_INVITATION ),
			selected( $current, self::SOURCE_INVITATION, false ),
			esc_html__( 'Invitation-linked', 'universal-product-reviews' )
		);
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( self::SOURCE_ALL ),
			selected( $current, self::SOURCE_ALL, false ),
			esc_html__( 'All UPR product reviews', 'universal-product-reviews' )
		);
		echo '</select>';
	}

	/**
	 * @param array<string, string> $status_links Status links.
	 * @return array<string, string>
	 */
	public static function view_links( array $status_links ): array {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return $status_links;
		}

		$base = admin_url( 'edit-comments.php' );
		$product_url = add_query_arg(
			array(
				'upr_view' => self::VIEW_PRODUCT_REVIEWS,
			),
			$base
		);
		$pending_url = add_query_arg(
			array(
				'upr_view' => self::VIEW_PENDING,
			),
			$base
		);

		$view = self::sanitised_view();
		$status_links['upr_product_reviews'] = sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( $product_url ),
			self::VIEW_PRODUCT_REVIEWS === $view ? ' class="current" aria-current="page"' : '',
			esc_html__( 'UPR product reviews', 'universal-product-reviews' )
		);
		$status_links['upr_pending'] = sprintf(
			'<a href="%s"%s>%s</a>',
			esc_url( $pending_url ),
			self::VIEW_PENDING === $view ? ' class="current" aria-current="page"' : '',
			esc_html__( 'UPR pending', 'universal-product-reviews' )
		);

		return $status_links;
	}

	/**
	 * @param \WP_Comment_Query $query Query.
	 */
	public static function constrain_query( $query ): void {
		if ( ! self::is_comments_list_query( $query ) ) {
			return;
		}

		$view   = self::sanitised_view();
		$source = self::sanitised_source();

		$needs_product_scope = '' !== $view || self::SOURCE_INVITATION === $source || self::SOURCE_ALL === $source;
		if ( ! $needs_product_scope ) {
			return;
		}

		$query->query_vars['type']      = 'review';
		$query->query_vars['post_type'] = 'product';

		if ( self::VIEW_PENDING === $view ) {
			$query->query_vars['status'] = 'hold';
		}

		if ( self::SOURCE_INVITATION === $source ) {
			$query->query_vars['upr_invitation_linked'] = true;
		}
	}

	/**
	 * Pagination-safe invitation-linked filter via EXISTS (no duplicate rows).
	 *
	 * @param array<string, string> $clauses Clauses.
	 * @param \WP_Comment_Query     $query   Query.
	 * @return array<string, string>
	 */
	public static function invitation_linked_clauses( array $clauses, $query ): array {
		if ( empty( $query->query_vars['upr_invitation_linked'] ) ) {
			return $clauses;
		}

		global $wpdb;
		$invite_table = $wpdb->prefix . 'upr_invite_items';
		$meta_key     = '_upr_order_item_id';

		$exists_meta = $wpdb->prepare(
			"EXISTS (
				SELECT 1 FROM {$wpdb->commentmeta} cm_upr
				WHERE cm_upr.comment_id = {$wpdb->comments}.comment_ID
				AND cm_upr.meta_key = %s
				AND cm_upr.meta_value <> ''
			)",
			$meta_key
		);

		$exists_invite = "EXISTS (
			SELECT 1 FROM {$invite_table} inv_upr
			WHERE inv_upr.review_comment_id = {$wpdb->comments}.comment_ID
		)";

		$clauses['where'] .= " AND ( {$exists_meta} OR {$exists_invite} ) ";

		return $clauses;
	}

	/**
	 * @param list<\WP_Comment|object> $comments Comments for the page.
	 * @param \WP_Comment_Query        $query    Query.
	 * @return list<\WP_Comment|object>
	 */
	public static function prefetch_page( $comments, $query ) {
		if ( self::$prefetching ) {
			return $comments;
		}
		if ( ! self::is_comments_list_query( $query ) ) {
			return $comments;
		}

		self::$prefetching = true;
		try {
			CommentListPrefetch::hydrate_from_comments( (array) $comments );
		} finally {
			self::$prefetching = false;
		}

		return $comments;
	}

	public static function sanitised_view(): string {
		$raw = isset( $_GET['upr_view'] ) ? sanitize_key( wp_unslash( (string) $_GET['upr_view'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $raw, array( self::VIEW_PRODUCT_REVIEWS, self::VIEW_PENDING ), true ) ) {
			return $raw;
		}
		return '';
	}

	public static function sanitised_source(): string {
		$raw = isset( $_REQUEST['upr_source'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['upr_source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $raw, array( self::SOURCE_INVITATION, self::SOURCE_ALL ), true ) ) {
			return $raw;
		}
		return '';
	}

	/**
	 * Whether this is the primary native Comments list-table query (not counts / secondary lookups).
	 *
	 * @param \WP_Comment_Query $query Query.
	 */
	public static function is_comments_list_query( $query ): bool {
		if ( ! is_admin() ) {
			return false;
		}
		global $pagenow;
		if ( 'edit-comments.php' !== $pagenow ) {
			return false;
		}
		if ( ! $query instanceof \WP_Comment_Query ) {
			return false;
		}

		$qv = $query->query_vars;

		// Status-tab / aggregate counts are not the list body.
		if ( ! empty( $qv['count'] ) ) {
			return false;
		}

		// Targeted ID lookups (plugins, nested fetches) are not the primary list.
		if ( ! empty( $qv['comment__in'] ) ) {
			return false;
		}

		// Primary list always requests a positive page size.
		if ( empty( $qv['number'] ) || (int) $qv['number'] <= 0 ) {
			return false;
		}

		// ID-only fetches are not row rendering.
		if ( isset( $qv['fields'] ) && in_array( $qv['fields'], array( 'ids', 'id=>parent' ), true ) ) {
			return false;
		}

		return true;
	}
}
