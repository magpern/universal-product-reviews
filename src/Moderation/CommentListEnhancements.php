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
use UniversalProductReviews\Ai\Recommendation;
use UniversalProductReviews\Ai\RecommendationPolicy;
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
		add_action( 'restrict_manage_comments', array( self::class, 'render_recommendation_filter' ) );
		add_filter( 'comment_status_links', array( self::class, 'view_links' ) );
		add_action( 'pre_get_comments', array( self::class, 'constrain_query' ) );
		add_filter( 'comments_clauses', array( self::class, 'invitation_linked_clauses' ), 10, 2 );
		add_filter( 'comments_clauses', array( self::class, 'recommendation_filter_clauses' ), 10, 2 );
		add_action( 'the_comments', array( self::class, 'prefetch_page' ), 10, 2 );
		add_filter( 'comment_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-comments', array( self::class, 'bulk_actions' ) );
		add_filter( 'gettext', array( self::class, 'empty_state_gettext' ), 10, 3 );
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
		$comment    = get_comment( $comment_id );
		$is_held    = $comment && Eligibility::is_held_status( Eligibility::approval_status( $comment ) );
		$display_on = Options::ai_recommendations_display_enabled();

		$pending_queue = self::VIEW_PENDING === self::sanitised_view() && $is_held;

		if ( $pending_queue ) {
			$presented = QueueAssessmentPresenter::present( $assessment, true, $display_on );
			echo esc_html( (string) $presented['status_copy'] );
			// Structured dl only on held pending view.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_definition_list escapes.
			echo QueueAssessmentPresenter::render_definition_list( $presented );
		} else {
			echo esc_html( self::format_ai_advisory_display( $assessment, (bool) $is_held, $display_on ) );
		}

		self::maybe_render_reanalyse_link( $comment_id );
	}

	/**
	 * Build escaped-safe plain text for the AI advisory column.
	 *
	 * @param array<string, mixed>|null $assessment Terminal assessment or null.
	 */
	public static function format_ai_advisory_display( ?array $assessment, bool $is_held, bool $display_enabled ): string {
		if ( null === $assessment ) {
			return '—';
		}

		if ( ! $display_enabled ) {
			return '—';
		}

		if ( ! $is_held ) {
			return __( 'Historical assessment', 'universal-product-reviews' );
		}

		$rec   = RecommendationPolicy::suggest( $assessment );
		$parts = array( RecommendationPolicy::action_label( $rec->action ) );

		$state = isset( $assessment['state'] ) ? (string) $assessment['state'] : '';
		$kind  = isset( $assessment['provider_kind'] ) ? (string) $assessment['provider_kind'] : '';
		if ( in_array( $kind, array( 'local', 'openai' ), true ) ) {
			$parts[] = $kind;
		}

		if ( 'completed' === $state && isset( $assessment['publication_safety_score'] ) && is_numeric( $assessment['publication_safety_score'] ) ) {
			$parts[] = sprintf(
				/* translators: %d: publication risk score 1–100 (higher = greater risk) */
				__( 'risk %d', 'universal-product-reviews' ),
				(int) $assessment['publication_safety_score']
			);
		}

		$confidence = isset( $assessment['confidence'] ) ? (string) $assessment['confidence'] : '';
		if ( '' !== $confidence ) {
			$parts[] = $confidence;
		}

		$labels = self::reason_code_labels( $assessment['reason_codes'] ?? null );
		if ( array() !== $labels ) {
			$parts[] = implode( ', ', $labels );
		}

		unset( $rec );
		return implode( ' · ', $parts );
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
		if ( ! Options::local_ai_shadow_enabled() ) {
			return;
		}
		if ( ! Eligibility::is_ai_assessable( $comment_id ) ) {
			return;
		}

		$is_openai = 'openai' === \UniversalProductReviews\Ai\ProviderResolver::kind();
		if ( $is_openai ) {
			if ( ! Options::ai_external_enabled() ) {
				return;
			}
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}
		} elseif ( ! current_user_can( 'moderate_comments' ) ) {
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

	public static function render_recommendation_filter(): void {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			return;
		}
		$current = self::sanitised_recommendation();
		echo '<label class="screen-reader-text" for="upr_recommendation">' . esc_html__( 'UPR AI recommendation', 'universal-product-reviews' ) . '</label>';
		echo '<select name="upr_recommendation" id="upr_recommendation">';
		printf(
			'<option value="" %s>%s</option>',
			selected( $current, '', false ),
			esc_html__( 'All AI recommendations', 'universal-product-reviews' )
		);
		foreach ( Recommendation::ACTIONS as $action ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $action ),
				selected( $current, $action, false ),
				esc_html( RecommendationPolicy::action_label( $action ) )
			);
		}
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
		$rec    = self::sanitised_recommendation();

		$needs_product_scope = '' !== $view || self::SOURCE_INVITATION === $source || self::SOURCE_ALL === $source || '' !== $rec;
		if ( ! $needs_product_scope ) {
			return;
		}

		$query->query_vars['type']      = 'review';
		$query->query_vars['post_type'] = 'product';

		if ( self::VIEW_PENDING === $view || '' !== $rec ) {
			$query->query_vars['status'] = 'hold';
		}

		if ( self::SOURCE_INVITATION === $source ) {
			$query->query_vars['upr_invitation_linked'] = true;
		}

		if ( '' !== $rec ) {
			$query->query_vars['upr_recommendation'] = $rec;
			// cache_domain is a core query var included in comment-query cache keys.
			// Custom upr_* vars are not — without this, switching filters returns stale IDs.
			$query->query_vars['cache_domain'] = 'upr_recommendation_' . $rec;
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
	 * Held-only recommendation filter via RecommendationPolicy SQL compiler (EXISTS).
	 *
	 * Uses latest assessment of any state (advisory). Distinct from M12 latest_actionable_*.
	 *
	 * @param array<string, string> $clauses Clauses.
	 * @param \WP_Comment_Query     $query   Query.
	 * @return array<string, string>
	 */
	public static function recommendation_filter_clauses( array $clauses, $query ): array {
		if ( ! self::is_comments_list_query( $query ) ) {
			return $clauses;
		}

		$action = isset( $query->query_vars['upr_recommendation'] )
			? (string) $query->query_vars['upr_recommendation']
			: '';
		if ( '' === $action ) {
			$action = self::sanitised_recommendation();
		}
		if ( '' === $action ) {
			return $clauses;
		}

		$compiled = RecommendationPolicy::compile_held_filter_sql( $action );
		if ( null === $compiled ) {
			return $clauses;
		}

		global $wpdb;
		$prepared = $wpdb->prepare( $compiled['fragment'], ...$compiled['args'] );
		if ( ! is_string( $prepared ) || '' === $prepared ) {
			return $clauses;
		}

		$clauses['where'] .= ' AND ' . $prepared . ' ';

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

	public static function sanitised_recommendation(): string {
		$raw = isset( $_REQUEST['upr_recommendation'] ) ? sanitize_key( wp_unslash( (string) $_REQUEST['upr_recommendation'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $raw, Recommendation::ACTIONS, true ) ) {
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

	/**
	 * Relabel native Approve/Spam/Trash on held UPR pending queue; add Keep on hold.
	 *
	 * @param array<string, string> $actions Actions.
	 * @param \WP_Comment           $comment Comment.
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, $comment ): array {
		if ( self::VIEW_PENDING !== self::sanitised_view() ) {
			return $actions;
		}
		if ( ! $comment instanceof \WP_Comment || ! ReviewContext::is_in_scope( $comment ) ) {
			return $actions;
		}
		if ( ! Eligibility::is_held_status( Eligibility::approval_status( $comment ) ) ) {
			return $actions;
		}

		if ( isset( $actions['approve'] ) ) {
			$actions['approve'] = self::relabel_action_html( (string) $actions['approve'], __( 'Publish', 'universal-product-reviews' ) );
		}
		if ( isset( $actions['spam'] ) ) {
			$actions['spam'] = self::relabel_action_html( (string) $actions['spam'], __( 'Mark as spam', 'universal-product-reviews' ) );
		}
		if ( isset( $actions['trash'] ) ) {
			$actions['trash'] = self::relabel_action_html( (string) $actions['trash'], __( 'Move to trash', 'universal-product-reviews' ) );
		}

		if ( current_user_can( 'moderate_comments' ) ) {
			$product_id = ReviewContext::product_id( $comment );
			$title      = $product_id > 0 ? get_the_title( $product_id ) : '';
			if ( '' === $title ) {
				$title = sprintf( '#%d', $product_id );
			}
			$actions['upr_keep_hold'] = OperatorQueueKeepHold::row_action_html( (int) $comment->comment_ID, $title );
		}

		return $actions;
	}

	/**
	 * Relabel bulk Approve/Spam/Trash on held UPR pending queue only.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 * @return array<string, string>
	 */
	public static function bulk_actions( array $actions ): array {
		if ( self::VIEW_PENDING !== self::sanitised_view() ) {
			return $actions;
		}
		if ( isset( $actions['approve'] ) ) {
			$actions['approve'] = __( 'Publish', 'universal-product-reviews' );
		}
		if ( isset( $actions['spam'] ) ) {
			$actions['spam'] = __( 'Mark as spam', 'universal-product-reviews' );
		}
		if ( isset( $actions['markspam'] ) ) {
			$actions['markspam'] = __( 'Mark as spam', 'universal-product-reviews' );
		}
		if ( isset( $actions['trash'] ) ) {
			$actions['trash'] = __( 'Move to trash', 'universal-product-reviews' );
		}
		return $actions;
	}

	/**
	 * Empty-state copy for the UPR pending queue.
	 */
	public static function empty_state_gettext( string $translation, string $text, string $domain ): string {
		if ( 'default' !== $domain ) {
			return $translation;
		}
		if ( self::VIEW_PENDING !== self::sanitised_view() ) {
			return $translation;
		}
		if ( ! in_array( $text, array( 'No comments found.', 'No comments awaiting moderation.' ), true ) ) {
			return $translation;
		}
		return __( 'No product reviews awaiting moderation.', 'universal-product-reviews' );
	}

	/**
	 * Replace visible link text while preserving the native href/nonce markup.
	 */
	private static function relabel_action_html( string $html, string $label ): string {
		$replaced = preg_replace( '/>[^<]*<\/a>/', '>' . esc_html( $label ) . '</a>', $html, 1 );
		return is_string( $replaced ) ? $replaced : $html;
	}
}
