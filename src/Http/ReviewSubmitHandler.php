<?php
/**
 * Guest review submit handler.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Http;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ProductReviewability;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Scheduling\Jobs;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;
use UniversalProductReviews\Tokens\SessionCookie;
use UniversalProductReviews\Tokens\TokenRepository;
use UniversalProductReviews\Tokens\TokenService;

defined( 'ABSPATH' ) || exit;

final class ReviewSubmitHandler {

	public static function handle(): void {
		header( 'Referrer-Policy: no-referrer' );
		$session = FormSessionAuthenticator::current_session();
		if ( null === $session ) {
			self::fail( 403, __( 'Your review session has expired.', 'universal-product-reviews' ) );
			return;
		}

		$session_id = (int) ( $_POST['upr_session_id'] ?? 0 );
		$nonce      = (string) ( $_POST['upr_nonce'] ?? '' );
		if ( $session_id !== (int) $session['id'] || ! wp_verify_nonce( $nonce, 'upr_review_submit_' . $session_id ) ) {
			self::fail( 403, __( 'Invalid request.', 'universal-product-reviews' ) );
			return;
		}

		$product_id    = (int) $session['product_id'];
		$order_item_id = (int) $session['order_item_id'];
		$parent_token  = (int) $session['parent_token_id'];

		if ( ! ProductReviewability::is_reviewable( $product_id ) ) {
			TokenRepository::revoke_for_item( $order_item_id );
			SessionCookie::clear();
			self::fail( 410, __( 'This product is no longer accepting reviews.', 'universal-product-reviews' ) );
			return;
		}

		$rating = (int) ( $_POST['upr_rating'] ?? 0 );
		if ( $rating < 1 || $rating > 5 ) {
			self::fail( 400, __( 'Rating must be between 1 and 5.', 'universal-product-reviews' ) );
			return;
		}

		$content = trim( wp_unslash( (string) ( $_POST['upr_content'] ?? '' ) ) );
		$min     = (int) apply_filters( 'upr_review_min_length', 1 );
		if ( strlen( $content ) < $min ) {
			self::fail( 400, __( 'Please enter a review.', 'universal-product-reviews' ) );
			return;
		}

		$invite = InviteRepository::find( $order_item_id );
		if ( ! $invite || ScheduleStates::COMPLETED === $invite['schedule_state'] ) {
			self::fail( 409, __( 'This review invitation has already been used.', 'universal-product-reviews' ) );
			return;
		}

		$order = wc_get_order( (int) $invite['order_id'] );
		if ( ! $order ) {
			self::fail( 400, __( 'Order not found.', 'universal-product-reviews' ) );
			return;
		}

		$author = $order->get_formatted_billing_full_name();
		$email  = $order->get_billing_email();

		$commentdata = array(
			'comment_post_ID'      => $product_id,
			'comment_author'       => $author ?: __( 'Customer', 'universal-product-reviews' ),
			'comment_author_email' => $email,
			'comment_author_url'   => '',
			'comment_content'      => $content,
			'comment_type'         => 'review',
			'comment_parent'       => 0,
			'user_id'              => 0,
		);

		$comment_id = wp_new_comment( $commentdata, true );
		if ( is_wp_error( $comment_id ) || ! $comment_id ) {
			self::fail( 500, __( 'Could not save your review.', 'universal-product-reviews' ) );
			return;
		}

		$comment_id = (int) $comment_id;
		update_comment_meta( $comment_id, 'rating', $rating );
		update_comment_meta( $comment_id, '_upr_order_item_id', $order_item_id );
		if ( ! empty( $invite['variation_id'] ) ) {
			update_comment_meta( $comment_id, '_upr_variation_id', (int) $invite['variation_id'] );
		}

		self::persist_upr_completion( $order_item_id, $comment_id, $parent_token, (int) $invite['order_id'] );

		nocache_headers();
		status_header( 200 );
		echo esc_html__( 'Thank you. Your review has been submitted and is awaiting moderation.', 'universal-product-reviews' );
	}

	private static function persist_upr_completion( int $order_item_id, int $comment_id, int $invite_token_id, int $order_id ): void {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );
		try {
			$ok = InviteRepository::conditional_update(
				$order_item_id,
				array(
					'schedule_state'      => ScheduleStates::COMPLETED,
					'review_completed_at' => current_time( 'mysql', true ),
					'review_comment_id'   => $comment_id,
				)
			);
			if ( ! $ok ) {
				// Concurrent winner — still attach meta if needed.
				$wpdb->query( 'ROLLBACK' );
				return;
			}

			if ( $invite_token_id > 0 ) {
				TokenService::redeem_after_submit( $invite_token_id, $order_item_id );
			} else {
				TokenRepository::revoke_for_item( $order_item_id );
				SessionCookie::clear();
			}

			Jobs::unschedule_item( $order_item_id );
			AuditLogger::log( 'invite.completed', 'customer', $order_id, $order_item_id, array( 'comment_id' => $comment_id ) );
			$wpdb->query( 'COMMIT' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			// Comment exists; reconcile will repair.
			AuditLogger::log( 'invite.completion_failed', 'system', $order_id, $order_item_id, array( 'comment_id' => $comment_id ) );
		}

		// Meta already written above; keep idempotent.
	}

	private static function fail( int $code, string $message ): void {
		status_header( $code );
		nocache_headers();
		echo esc_html( $message );
	}
}
