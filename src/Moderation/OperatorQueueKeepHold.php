<?php
/**
 * Keep-on-hold admin-post for the M15 operator queue (no status write).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

use UniversalProductReviews\Ai\Eligibility;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Invitations\InviteRepository;

defined( 'ABSPATH' ) || exit;

final class OperatorQueueKeepHold {

	public const EVENT_DEFERRED = 'review.operator_deferred';
	public const ACTION         = 'upr_queue_keep_hold';

	/** @var string|null Request-local dedupe key. */
	private static ?string $last_key = null;

	public static function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_notices', array( self::class, 'render_notices' ) );
	}

	/**
	 * Test seam.
	 */
	public static function reset_for_tests(): void {
		self::$last_key = null;
	}

	public static function handle(): void {
		if ( ! current_user_can( 'moderate_comments' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'universal-product-reviews' ), 403 );
		}

		$comment_id = isset( $_POST['comment_id'] ) ? (int) $_POST['comment_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $comment_id <= 0 ) {
			self::redirect( 'stale_status' );
		}

		check_admin_referer( 'upr_queue_keep_hold_' . $comment_id );

		$comment = get_comment( $comment_id );
		if ( ! $comment || ! ReviewContext::is_in_scope( $comment ) ) {
			self::redirect( 'stale_status' );
		}

		$status = Eligibility::approval_status( $comment );
		if ( ! Eligibility::is_held_status( $status ) ) {
			self::redirect( 'stale_status' );
		}

		// Zero status write — never call wp_set_comment_status or related APIs.
		self::emit_deferred_audit( $comment );

		self::redirect( 'keep_hold_ok' );
	}

	/**
	 * @param \WP_Comment $comment Held in-scope product review.
	 */
	public static function emit_deferred_audit( \WP_Comment $comment ): void {
		$comment_id = (int) $comment->comment_ID;
		$dedupe_key = $comment_id . '|keep_hold';
		if ( self::$last_key === $dedupe_key ) {
			return;
		}
		self::$last_key = $dedupe_key;

		$ctx = self::association_context( $comment );
		$assessment_available = false;
		$assessment_state     = 'none';

		$presented_state = self::latest_assessment_state( $comment_id );
		if ( null !== $presented_state ) {
			$assessment_available = true;
			$assessment_state     = $presented_state;
		}

		$payload = array(
			'comment_id'            => $comment_id,
			'product_id'            => (int) $ctx['product_id'],
			'old_status'            => 'hold',
			'new_status'            => 'hold',
			'queue_action'          => 'keep_hold',
			'assessment_available'  => $assessment_available,
			'assessment_state'      => $assessment_state,
			'actor_id'              => get_current_user_id(),
			'origin'                => 'operator',
		);

		$order_id = ! empty( $ctx['order_id'] ) ? (int) $ctx['order_id'] : null;
		$item_id  = ! empty( $ctx['order_item_id'] ) ? (int) $ctx['order_item_id'] : null;

		AuditLogger::log( self::EVENT_DEFERRED, 'moderator', $order_id, $item_id, $payload );
	}

	public static function render_notices(): void {
		global $pagenow;
		if ( 'edit-comments.php' !== $pagenow ) {
			return;
		}
		if ( empty( $_GET['upr_queue_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice = sanitize_key( wp_unslash( (string) $_GET['upr_queue_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map    = array(
			'keep_hold_ok' => array( 'success', __( 'Review kept on hold.', 'universal-product-reviews' ) ),
			'stale_status' => array( 'warning', __( 'Keep on hold refused: review is no longer held or is out of scope.', 'universal-product-reviews' ) ),
		);
		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $notice ][0] ),
			esc_html( $map[ $notice ][1] )
		);
	}

	/**
	 * Keep-on-hold row-action form HTML (escaped).
	 */
	public static function row_action_html( int $comment_id, string $product_title ): string {
		$url = admin_url( 'admin-post.php' );
		$sr  = sprintf(
			/* translators: 1: comment ID 2: product title */
			__( 'Keep comment %1$d for %2$s on hold', 'universal-product-reviews' ),
			$comment_id,
			$product_title
		);

		ob_start();
		echo '<form method="post" action="' . esc_url( $url ) . '" style="display:inline;">';
		wp_nonce_field( 'upr_queue_keep_hold_' . $comment_id );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<input type="hidden" name="comment_id" value="' . esc_attr( (string) $comment_id ) . '" />';
		echo '<button type="submit" class="button-link">' . esc_html__( 'Keep on hold', 'universal-product-reviews' );
		echo ' <span class="screen-reader-text">' . esc_html( $sr ) . '</span></button>';
		echo '</form>';
		return (string) ob_get_clean();
	}

	private static function redirect( string $notice ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'upr_view'         => CommentListEnhancements::VIEW_PENDING,
					'upr_queue_notice' => $notice,
				),
				admin_url( 'edit-comments.php' )
			)
		);
		exit;
	}

	/**
	 * @return array{product_id:int,order_id:int,order_item_id:int,source:string}
	 */
	private static function association_context( \WP_Comment $comment ): array {
		$comment_id = (int) $comment->comment_ID;
		$invite     = null;
		$rows       = InviteRepository::find_by_review_comment_ids( array( $comment_id ) );
		if ( isset( $rows[ $comment_id ] ) ) {
			$invite = $rows[ $comment_id ];
		} else {
			$item = ReviewContext::meta_order_item_id( $comment );
			if ( $item > 0 ) {
				$by_item = InviteRepository::find_by_order_item_ids( array( $item ) );
				$invite  = $by_item[ $item ] ?? null;
			}
		}
		$built = ReviewContext::build( $comment, is_array( $invite ) ? $invite : null );
		return array(
			'product_id'    => (int) $built['product_id'],
			'order_id'      => (int) $built['order_id'],
			'order_item_id' => (int) $built['order_item_id'],
			'source'        => (string) $built['source'],
		);
	}

	/**
	 * Latest assessment state of any kind for audit payload (opaque state only).
	 */
	private static function latest_assessment_state( int $comment_id ): ?string {
		$map = \UniversalProductReviews\Ai\AssessmentRepository::latest_for_comments( array( $comment_id ) );
		$row = $map[ $comment_id ] ?? null;
		if ( ! is_array( $row ) ) {
			return null;
		}
		$state = isset( $row['state'] ) ? (string) $row['state'] : '';
		if ( in_array( $state, PolicyAllowlist::TERMINAL_STATES, true ) ) {
			return $state;
		}
		return null;
	}
}
