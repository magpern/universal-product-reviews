<?php
/**
 * Token-free review form.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Http;

use UniversalProductReviews\Tokens\FormSessionAuthenticator;

defined( 'ABSPATH' ) || exit;

final class ReviewFormEndpoint {

	public static function handle_get(): void {
		header( 'Referrer-Policy: no-referrer' );
		$session = FormSessionAuthenticator::current_session();
		if ( null === $session ) {
			status_header( 403 );
			nocache_headers();
			echo esc_html__( 'Your review session has expired. Please use your invitation link again.', 'universal-product-reviews' );
			return;
		}

		$product_id = (int) $session['product_id'];
		$product    = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		$name       = $product ? $product->get_name() : ( 'Product #' . $product_id );
		$nonce      = wp_create_nonce( 'upr_review_submit_' . (int) $session['id'] );
		$action     = esc_url( home_url( user_trailingslashit( 'upr-review/form' ) ) );

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=UTF-8' );

		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="referrer" content="no-referrer">';
		echo '<title>' . esc_html__( 'Write a review', 'universal-product-reviews' ) . '</title></head><body>';
		echo '<h1>' . esc_html( sprintf( /* translators: %s product name */ __( 'Review: %s', 'universal-product-reviews' ), $name ) ) . '</h1>';
		echo '<form method="post" action="' . $action . '">';
		echo '<input type="hidden" name="upr_session_id" value="' . esc_attr( (string) (int) $session['id'] ) . '">';
		echo '<input type="hidden" name="upr_nonce" value="' . esc_attr( $nonce ) . '">';
		echo '<p><label>' . esc_html__( 'Rating (1-5)', 'universal-product-reviews' ) . ' <input type="number" name="upr_rating" min="1" max="5" required></label></p>';
		echo '<p><label>' . esc_html__( 'Your review', 'universal-product-reviews' ) . '<br><textarea name="upr_content" required rows="6" cols="60"></textarea></label></p>';
		echo '<p><button type="submit">' . esc_html__( 'Submit review', 'universal-product-reviews' ) . '</button></p>';
		echo '</form></body></html>';
	}
}
