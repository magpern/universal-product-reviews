<?php
/**
 * Guest review submit handler (controlled M2 form POST only).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Http;

use UniversalProductReviews\Invitations\CompletionService;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ProductReviewability;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Invitations\SubmitClaimService;
use UniversalProductReviews\Submission\GuestSubmitAuthorization;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;
use UniversalProductReviews\Tokens\SessionCookie;
use UniversalProductReviews\Tokens\TokenRepository;

defined( 'ABSPATH' ) || exit;

final class ReviewSubmitHandler {

	/**
	 * Handle POST on the token-free M2 form route only.
	 */
	public static function handle(): void {
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}

		if ( ! self::is_m2_form_post_route() ) {
			self::fail( 403, __( 'Invalid review submission route.', 'universal-product-reviews' ) );
			return;
		}

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
		if ( ScheduleStates::SUPPRESSED === $invite['schedule_state'] ) {
			TokenRepository::revoke_for_item( $order_item_id );
			SessionCookie::clear();
			self::fail( 410, __( 'This product is no longer accepting reviews.', 'universal-product-reviews' ) );
			return;
		}
		if ( (int) $invite['product_id'] !== $product_id ) {
			self::fail( 403, __( 'Invalid request.', 'universal-product-reviews' ) );
			return;
		}

		$order = wc_get_order( (int) $invite['order_id'] );
		if ( ! $order ) {
			self::fail( 400, __( 'Order not found.', 'universal-product-reviews' ) );
			return;
		}

		$claim = SubmitClaimService::acquire( $order_item_id );
		if ( null === $claim ) {
			self::fail( 409, __( 'This review is already being submitted. Please wait.', 'universal-product-reviews' ) );
			return;
		}

		$claim_token = $claim['token'];
		$author      = $order->get_formatted_billing_full_name();
		$email       = $order->get_billing_email();

		// Ignore arbitrary posted identity — order fields only.
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

		$comment_id = null;
		try {
			GuestSubmitAuthorization::arm( $product_id, $order_item_id, $session_id, $claim_token );
			$comment_id = wp_new_comment( $commentdata, true );
			if ( ! is_wp_error( $comment_id ) && $comment_id ) {
				update_comment_meta( (int) $comment_id, 'rating', $rating );
			}
		} finally {
			GuestSubmitAuthorization::clear();
		}

		if ( is_wp_error( $comment_id ) || ! $comment_id ) {
			SubmitClaimService::release( $order_item_id, $claim_token );
			self::fail( 500, __( 'Could not save your review.', 'universal-product-reviews' ) );
			return;
		}

		$comment_id = (int) $comment_id;
		update_comment_meta( $comment_id, '_upr_order_item_id', $order_item_id );
		if ( ! empty( $invite['variation_id'] ) ) {
			update_comment_meta( $comment_id, '_upr_variation_id', (int) $invite['variation_id'] );
		}

		$finalized = CompletionService::finalize(
			$order_item_id,
			$comment_id,
			(int) $invite['order_id'],
			$parent_token > 0 ? $parent_token : null,
			$claim_token
		);

		if ( ! $finalized && CompletionService::abandon_lost_submission( $order_item_id, $comment_id, $claim_token ) ) {
			SessionCookie::clear();
			self::fail( 410, __( 'This product is no longer accepting reviews.', 'universal-product-reviews' ) );
			return;
		}

		if ( ! headers_sent() ) {
			nocache_headers();
			status_header( 200 );
		}
		echo esc_html__( 'Thank you. Your review has been submitted and is awaiting moderation.', 'universal-product-reviews' );
	}

	/**
	 * True only for the M2 token-free form POST query var route.
	 */
	public static function is_m2_form_post_route(): bool {
		if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
			return false;
		}
		$form = get_query_var( 'upr_review_form' );
		return (bool) $form;
	}

	private static function fail( int $code, string $message ): void {
		if ( ! headers_sent() ) {
			status_header( $code );
			nocache_headers();
		}
		echo esc_html( $message );
	}
}
