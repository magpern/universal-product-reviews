<?php
/**
 * Deterministic moderation audit for in-scope product-review status transitions.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Invitations\InviteRepository;

defined( 'ABSPATH' ) || exit;

final class ModerationAudit {

	public const EVENT_STATUS_CHANGED        = 'review.status_changed';
	public const EVENT_SYSTEM_SPAM           = 'review.system_spam';
	public const EVENT_AI_AUTO_SPAM          = 'review.ai_auto_spam';
	public const EVENT_SYSTEM_STATUS_CHANGED = 'review.system_status_changed';
	public const EVENT_REPLY_POSTED          = 'review.reply_posted';

	/** @var string|null Last emitted dedupe key in this request (adjacent duplicate suppression only). */
	private static ?string $last_key = null;

	public static function register(): void {
		add_action( 'transition_comment_status', array( self::class, 'on_transition' ), 10, 3 );
		add_action( 'comment_post', array( self::class, 'on_comment_post' ), 20, 3 );
	}

	/**
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment.
	 */
	public static function on_transition( $new_status, $old_status, $comment ): void {
		$new_status = self::normalise_status( (string) $new_status );
		$old_status = self::normalise_status( (string) $old_status );

		if ( $new_status === $old_status ) {
			return;
		}
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		if ( ! ReviewContext::is_in_scope( $comment ) ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		$event      = self::classify_event( $new_status );
		$dedupe_key = $comment_id . '|' . $old_status . '|' . $new_status . '|' . $event;
		// Suppress only adjacent duplicate hook fires — never drop a later genuine cycle
		// (e.g. approve→hold→approve must emit both approve edges).
		if ( self::$last_key === $dedupe_key ) {
			return;
		}
		self::$last_key = $dedupe_key;

		$ctx    = self::association_context( $comment );
		$origin = self::classify_origin( $new_status );

		$payload = array(
			'comment_id'  => $comment_id,
			'product_id'  => (int) $ctx['product_id'],
			'old_status'  => $old_status,
			'new_status'  => $new_status,
			'source'      => (string) $ctx['source'],
			'actor_id'    => get_current_user_id(),
			'origin'      => $origin,
		);
		if ( ! empty( $ctx['order_id'] ) ) {
			$payload['order_id'] = (int) $ctx['order_id'];
		}
		if ( ! empty( $ctx['order_item_id'] ) ) {
			$payload['order_item_id'] = (int) $ctx['order_item_id'];
		}

		$actor_type = 'operator' === $origin ? 'moderator' : 'system';
		$order_id   = ! empty( $ctx['order_id'] ) ? (int) $ctx['order_id'] : null;
		$item_id    = ! empty( $ctx['order_item_id'] ) ? (int) $ctx['order_item_id'] : null;

		AuditLogger::log( $event, $actor_type, $order_id, $item_id, $payload );
	}

	/**
	 * Emit review.reply_posted for validated staff replies only.
	 *
	 * @param int        $comment_id       Comment ID.
	 * @param int|string $comment_approved Approved status.
	 * @param array      $commentdata      Comment data.
	 */
	public static function on_comment_post( $comment_id, $comment_approved, $commentdata ): void {
		unset( $comment_approved );
		$comment_id = (int) $comment_id;
		if ( $comment_id <= 0 ) {
			return;
		}

		$data = is_array( $commentdata ) ? $commentdata : array();
		if ( empty( $data['comment_post_ID'] ) ) {
			$comment = get_comment( $comment_id );
			if ( ! $comment ) {
				return;
			}
			$data = $comment->to_array();
		}

		if ( ! StaffReplyPolicy::is_validated_staff_reply( $data ) ) {
			return;
		}

		$product_id = isset( $data['comment_post_ID'] ) ? (int) $data['comment_post_ID'] : 0;
		$parent_id  = isset( $data['comment_parent'] ) ? (int) $data['comment_parent'] : 0;
		$payload    = array(
			'comment_id' => $comment_id,
			'product_id' => $product_id,
			'parent_id'  => $parent_id,
			'actor_id'   => get_current_user_id(),
			'origin'     => 'operator',
		);

		AuditLogger::log( self::EVENT_REPLY_POSTED, 'moderator', null, null, $payload );
	}

	/**
	 * @param string $new_status Normalised new status.
	 */
	public static function classify_event( string $new_status ): string {
		if ( AiActionOrigin::is_active() ) {
			return 'spam' === $new_status ? self::EVENT_AI_AUTO_SPAM : self::EVENT_SYSTEM_STATUS_CHANGED;
		}
		if ( SystemStatusOrigin::is_active() ) {
			return 'spam' === $new_status ? self::EVENT_SYSTEM_SPAM : self::EVENT_SYSTEM_STATUS_CHANGED;
		}
		if ( is_admin() && current_user_can( 'moderate_comments' ) ) {
			return self::EVENT_STATUS_CHANGED;
		}
		return self::EVENT_SYSTEM_STATUS_CHANGED;
	}

	/**
	 * @param string $new_status Normalised new status.
	 */
	public static function classify_origin( string $new_status ): string {
		unset( $new_status );
		if ( AiActionOrigin::is_active() ) {
			return 'upr_ai_auto_action';
		}
		if ( SystemStatusOrigin::is_active() ) {
			return 'upr_system';
		}
		if ( is_admin() && current_user_can( 'moderate_comments' ) ) {
			return 'operator';
		}
		return 'external_system';
	}

	public static function normalise_status( string $status ): string {
		$map = array(
			'0'         => 'hold',
			'hold'      => 'hold',
			'unapproved'=> 'hold',
			'1'         => 'approve',
			'approve'   => 'approve',
			'approved'  => 'approve',
			'spam'      => 'spam',
			'trash'     => 'trash',
		);
		$lower = strtolower( $status );
		return $map[ $lower ] ?? $lower;
	}

	/**
	 * @param \WP_Comment $comment Comment.
	 * @return array<string, mixed>
	 */
	private static function association_context( \WP_Comment $comment ): array {
		$comment_id = (int) $comment->comment_ID;
		$cached     = CommentListPrefetch::get( $comment_id );
		if ( null !== $cached ) {
			return $cached;
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

	/**
	 * Test seam.
	 */
	public static function reset_for_tests(): void {
		self::$last_key = null;
	}
}
