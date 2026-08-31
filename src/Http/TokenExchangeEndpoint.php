<?php
/**
 * Invite token exchange endpoint (no form render).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Http;

use UniversalProductReviews\CustomerEdit\InviteTokenDispatcher;

defined( 'ABSPATH' ) || exit;

final class TokenExchangeEndpoint {

	public static function handle( string $raw_token ): void {
		if ( ! headers_sent() ) {
			header( 'Referrer-Policy: no-referrer' );
		}

		$outcome = InviteTokenDispatcher::dispatch( $raw_token );
		if ( 'form' === $outcome['kind'] ) {
			if ( ! headers_sent() ) {
				nocache_headers();
			}
			wp_safe_redirect( $outcome['url'], 303 );
			return;
		}
		if ( 'edit' === $outcome['kind'] ) {
			if ( ! headers_sent() ) {
				nocache_headers();
			}
			wp_safe_redirect( $outcome['url'], 303 );
			return;
		}

		if ( ! headers_sent() ) {
			status_header( 404 );
			nocache_headers();
		}
		echo esc_html__( 'This review invitation is not available.', 'universal-product-reviews' );
	}
}
