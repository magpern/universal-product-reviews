<?php
/**
 * Rewrite rules for review endpoints.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Http;

defined( 'ABSPATH' ) || exit;

final class RewriteRules {

	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rules' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'dispatch' ) );
	}

	public static function add_rules(): void {
		add_rewrite_rule( '^upr-review/form/?$', 'index.php?upr_review_form=1', 'top' );
		add_rewrite_rule( '^upr-review/([^/]+)/?$', 'index.php?upr_review_token=$matches[1]', 'top' );
	}

	/**
	 * @param list<string> $vars
	 * @return list<string>
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = 'upr_review_token';
		$vars[] = 'upr_review_form';
		return $vars;
	}

	public static function dispatch(): void {
		$token = get_query_var( 'upr_review_token' );
		if ( is_string( $token ) && $token !== '' ) {
			TokenExchangeEndpoint::handle( $token );
			exit;
		}
		if ( get_query_var( 'upr_review_form' ) ) {
			if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
				ReviewSubmitHandler::handle();
			} else {
				ReviewFormEndpoint::handle_get();
			}
			exit;
		}
	}

	public static function maybe_flush(): void {
		if ( get_option( 'upr_rewrite_flushed' ) === UPR_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'upr_rewrite_flushed', UPR_VERSION, true );
	}
}
