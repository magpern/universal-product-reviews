<?php
/**
 * Invite token exchange endpoint (no form render).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Http;

use UniversalProductReviews\Tokens\TokenService;

defined( 'ABSPATH' ) || exit;

final class TokenExchangeEndpoint {

	public static function handle( string $raw_token ): void {
		header( 'Referrer-Policy: no-referrer' );

		$result = TokenService::exchange_invite( $raw_token );
		if ( null === $result ) {
			status_header( 404 );
			nocache_headers();
			echo esc_html__( 'This review invitation is not available.', 'universal-product-reviews' );
			return;
		}

		nocache_headers();
		wp_safe_redirect( $result['form_url'], 303 );
	}
}
