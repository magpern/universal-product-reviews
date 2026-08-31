<?php
/**
 * Token-free customer review edit GET/POST.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Http;

use UniversalProductReviews\CustomerEdit\CustomerEditAuthorization;
use UniversalProductReviews\CustomerEdit\CustomerEditClock;
use UniversalProductReviews\CustomerEdit\CustomerEditEligibility;
use UniversalProductReviews\CustomerEdit\EditClaimRepository;
use UniversalProductReviews\CustomerEdit\EditFinaliser;
use UniversalProductReviews\CustomerEdit\EditSessionAuthenticator;
use UniversalProductReviews\Tokens\SessionCookie;

defined( 'ABSPATH' ) || exit;

final class ReviewEditHandler {

	public static function handle_get(): void {
		self::send_security_headers();

		$resolved = self::resolve_authorised_comment();
		if ( null === $resolved ) {
			self::render_denied();
			return;
		}

		$comment = $resolved['comment'];
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $comment->comment_post_ID ) : null;
		$name    = $product ? $product->get_name() : ( 'Product #' . (int) $comment->comment_post_ID );
		$rating  = (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true );
		if ( $rating < 1 || $rating > 5 ) {
			$rating = 5;
		}
		$nonce  = wp_create_nonce( 'upr_review_edit_' . (int) $comment->comment_ID );
		$action = esc_url( home_url( user_trailingslashit( 'upr-review/edit' ) ) );

		self::send_status( 200 );
		echo self::document_open( esc_html__( 'Edit your review', 'universal-product-reviews' ) );
		echo '<h1>' . esc_html( sprintf( /* translators: %s product name */ __( 'Edit review: %s', 'universal-product-reviews' ), $name ) ) . '</h1>';
		echo '<form method="post" action="' . $action . '">';
		echo '<input type="hidden" name="upr_comment_id" value="' . esc_attr( (string) (int) $comment->comment_ID ) . '">';
		echo '<input type="hidden" name="upr_nonce" value="' . esc_attr( $nonce ) . '">';
		echo '<fieldset>';
		echo '<legend>' . esc_html__( 'Your review', 'universal-product-reviews' ) . '</legend>';
		echo '<p>';
		echo '<label for="upr_rating">' . esc_html__( 'Rating (1-5)', 'universal-product-reviews' ) . '</label> ';
		echo '<input type="number" id="upr_rating" name="upr_rating" min="1" max="5" required aria-required="true" value="' . esc_attr( (string) $rating ) . '">';
		echo '</p>';
		echo '<p>';
		echo '<label for="upr_content">' . esc_html__( 'Your review', 'universal-product-reviews' ) . '</label><br>';
		echo '<textarea id="upr_content" name="upr_content" required aria-required="true" rows="6" cols="60">' . esc_textarea( (string) $comment->comment_content ) . '</textarea>';
		echo '</p>';
		echo '<p><button type="submit">' . esc_html__( 'Save changes', 'universal-product-reviews' ) . '</button></p>';
		echo '</fieldset>';
		echo '</form>';
		echo self::document_close();
	}

	public static function handle_post(): void {
		self::send_security_headers();

		$comment_id = (int) ( $_POST['upr_comment_id'] ?? 0 );
		$nonce      = (string) ( $_POST['upr_nonce'] ?? '' );
		if ( $comment_id <= 0 || ! wp_verify_nonce( $nonce, 'upr_review_edit_' . $comment_id ) ) {
			self::fail( 403, __( 'Invalid request.', 'universal-product-reviews' ) );
			return;
		}

		$resolved = self::resolve_authorised_comment( $comment_id );
		if ( null === $resolved ) {
			self::fail( 403, __( 'This review cannot be edited.', 'universal-product-reviews' ) );
			return;
		}

		$comment = $resolved['comment'];
		$rating  = (int) ( $_POST['upr_rating'] ?? 0 );
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

		$_FILES = array();

		$canonical   = EditClaimRepository::canonical_body( $content );
		$hmac        = EditClaimRepository::hmac_body( $canonical );
		$live_hmac   = EditClaimRepository::hmac_body( (string) $comment->comment_content );
		$live_rating = (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true );
		if ( $hmac === $live_hmac && $rating === $live_rating ) {
			self::ok( __( 'No changes to save.', 'universal-product-reviews' ) );
			return;
		}

		$prior  = CustomerEditEligibility::prior_status_label( $comment );
		$claimed = EditClaimRepository::acquire(
			(int) $comment->comment_ID,
			$resolved['auth_class'],
			$hmac,
			$rating,
			$prior
		);
		if ( null === $claimed ) {
			self::fail( 409, __( 'This review cannot be updated right now.', 'universal-product-reviews' ) );
			return;
		}

		try {
			CustomerEditAuthorization::arm( (int) $comment->comment_ID, $claimed['claim_token'], $claimed['generation'] );
			$updated = wp_update_comment(
				array(
					'comment_ID'      => (int) $comment->comment_ID,
					'comment_content' => $canonical,
				)
			);
			if ( false === $updated || is_wp_error( $updated ) ) {
				EditClaimRepository::abandon_unwritten( (int) $comment->comment_ID, $claimed['claim_token'], $claimed['generation'] );
				self::fail( 500, __( 'Could not save your review.', 'universal-product-reviews' ) );
				return;
			}
			update_comment_meta( (int) $comment->comment_ID, 'rating', $rating );

			clean_comment_cache( (int) $comment->comment_ID );
			$fresh = get_comment( (int) $comment->comment_ID );
			if ( ! $fresh instanceof \WP_Comment ) {
				EditClaimRepository::force_abandon( (int) $comment->comment_ID, $claimed['claim_token'], $claimed['generation'] );
				self::fail( 500, __( 'Could not save your review.', 'universal-product-reviews' ) );
				return;
			}
			$fresh_hmac = EditClaimRepository::hmac_body( (string) $fresh->comment_content );
			$fresh_rate = (int) get_comment_meta( (int) $fresh->comment_ID, 'rating', true );
			if ( $fresh_hmac !== $hmac || $fresh_rate !== $rating ) {
				EditClaimRepository::force_abandon( (int) $comment->comment_ID, $claimed['claim_token'], $claimed['generation'] );
				self::fail( 500, __( 'Could not save your review.', 'universal-product-reviews' ) );
				return;
			}

			$op_id = wp_generate_uuid4();
			if ( ! EditClaimRepository::mark_content_written( (int) $comment->comment_ID, $claimed['claim_token'], $claimed['generation'], $op_id ) ) {
				self::fail( 409, __( 'This review cannot be updated right now.', 'universal-product-reviews' ) );
				return;
			}

			$outcome = EditFinaliser::run( (int) $comment->comment_ID, $claimed['claim_token'], $claimed['generation'] );
			if ( 'busy' === $outcome ) {
				self::fail( 409, __( 'This review cannot be updated right now.', 'universal-product-reviews' ) );
				return;
			}
			if ( 'abandoned' === $outcome ) {
				self::fail( 403, __( 'This review cannot be edited.', 'universal-product-reviews' ) );
				return;
			}

			if ( 'guest_session' === $resolved['auth_class'] ) {
				SessionCookie::clear();
			}
			self::ok( __( 'Thank you. Your review has been updated and is awaiting moderation.', 'universal-product-reviews' ) );
		} catch ( \Throwable $e ) {
			self::fail( 500, __( 'Could not save your review.', 'universal-product-reviews' ) );
		} finally {
			CustomerEditAuthorization::clear();
		}
	}

	/**
	 * @return array{comment:\WP_Comment,auth_class:string}|null
	 */
	private static function resolve_authorised_comment( ?int $posted_comment_id = null ): ?array {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			$locator = $posted_comment_id ?? (int) ( $_GET['comment_id'] ?? $_POST['upr_comment_id'] ?? 0 );
			if ( $locator <= 0 ) {
				return null;
			}
			$comment = get_comment( $locator );
			if ( ! $comment instanceof \WP_Comment ) {
				return null;
			}
			if ( ! CustomerEditEligibility::logged_in_may_edit( $comment, $user_id ) ) {
				return null;
			}
			return array(
				'comment'    => $comment,
				'auth_class' => 'logged_in',
			);
		}

		$session = EditSessionAuthenticator::current_session();
		if ( null === $session ) {
			return null;
		}
		$parent = (int) ( $session['parent_token_id'] ?? 0 );
		if ( $parent <= 0 ) {
			return null;
		}
		$parent_row = \UniversalProductReviews\Tokens\TokenRepository::find_by_id( $parent );
		if ( ! is_array( $parent_row ) ) {
			return null;
		}
		$matched = \UniversalProductReviews\CustomerEdit\CompletedInviteLookup::match_row( $parent_row );
		if ( null === $matched ) {
			return null;
		}
		$comment = $matched['comment'];
		if ( null !== $posted_comment_id && $posted_comment_id !== (int) $comment->comment_ID ) {
			return null;
		}
		if ( ! CustomerEditClock::is_in_window( $comment ) || ! CustomerEditEligibility::status_allows_edit( $comment ) ) {
			return null;
		}
		return array(
			'comment'    => $comment,
			'auth_class' => 'guest_session',
		);
	}

	private static function render_denied(): void {
		self::send_status( 403 );
		echo self::document_open( esc_html__( 'Review cannot be edited', 'universal-product-reviews' ) );
		echo '<h1>' . esc_html__( 'This review cannot be edited.', 'universal-product-reviews' ) . '</h1>';
		echo self::document_close();
	}

	private static function fail( int $code, string $message ): void {
		self::send_status( $code );
		echo esc_html( $message );
	}

	private static function ok( string $message ): void {
		self::send_status( 200 );
		echo esc_html( $message );
	}

	private static function send_security_headers(): void {
		if ( headers_sent() ) {
			return;
		}
		header( 'Referrer-Policy: no-referrer' );
		nocache_headers();
	}

	private static function send_status( int $code ): void {
		if ( headers_sent() ) {
			return;
		}
		status_header( $code );
		header( 'Content-Type: text/html; charset=UTF-8' );
		if ( $code >= 400 ) {
			nocache_headers();
		}
	}

	private static function document_open( string $title ): string {
		return '<!DOCTYPE html><html ' . get_language_attributes( 'html' ) . '><head><meta charset="utf-8"><meta name="referrer" content="no-referrer">'
			. '<title>' . esc_html( $title ) . '</title></head><body>';
	}

	private static function document_close(): string {
		return '</body></html>';
	}
}
