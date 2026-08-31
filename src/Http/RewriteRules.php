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

	public const VERSION_OPTION = 'upr_rewrite_version';
	public const VERSION        = '3';

	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rules' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'dispatch' ) );
		add_action( 'admin_init', array( self::class, 'maybe_flush_controlled' ) );
	}

	public static function add_rules(): void {
		add_rewrite_rule( '^upr-review/form/?$', 'index.php?upr_review_form=1', 'top' );
		add_rewrite_rule( '^upr-review/edit/?$', 'index.php?upr_review_edit=1', 'top' );
		add_rewrite_rule( '^upr-review/([^/]+)/?$', 'index.php?upr_review_token=$matches[1]', 'top' );
	}

	/**
	 * @param list<string> $vars
	 * @return list<string>
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = 'upr_review_token';
		$vars[] = 'upr_review_form';
		$vars[] = 'upr_review_edit';
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
		if ( get_query_var( 'upr_review_edit' ) ) {
			if ( 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
				ReviewEditHandler::handle_post();
			} else {
				ReviewEditHandler::handle_get();
			}
			exit;
		}
	}

	/**
	 * Flush rewrite rules only from controlled contexts (activation / admin / CLI).
	 * Never call from ordinary frontend requests.
	 */
	public static function flush_controlled(): void {
		self::add_rules();
		flush_rewrite_rules( false );
		update_option( self::VERSION_OPTION, self::VERSION, true );
	}

	/**
	 * True when stored rewrite version lags the plugin constant.
	 */
	public static function needs_flush(): bool {
		return (string) get_option( self::VERSION_OPTION, '' ) !== self::VERSION;
	}

	/**
	 * Admin/CLI-only rewrite upgrade when version lags.
	 */
	public static function maybe_flush_controlled(): void {
		$allowed = is_admin() || ( defined( 'WP_CLI' ) && WP_CLI );
		if ( ! $allowed ) {
			return;
		}
		if ( ! self::needs_flush() ) {
			return;
		}
		self::flush_controlled();
	}
}
