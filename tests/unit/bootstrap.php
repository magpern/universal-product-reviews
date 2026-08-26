<?php
/**
 * Unit test bootstrap — WordPress not loaded.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! defined( 'UPR_VERSION' ) ) {
	define( 'UPR_VERSION', '0.0.0-test' );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $post = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $post );
		return $GLOBALS['upr_test_post_type'] ?? 'post';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $option, $default = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['upr_test_options'][ $option ] ?? $default;
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['upr_test_users'][ $user_id ] ?? false;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (bool) ( $GLOBALS['upr_test_logged_in'] ?? false );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $tag, $args );
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $args );
		return true;
	}
}

if ( ! function_exists( 'wc_customer_bought_product' ) ) {
	function wc_customer_bought_product( $email, $user_id, $product_id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $email, $user_id, $product_id );
		return (bool) ( $GLOBALS['upr_test_verified_purchase'] ?? false );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $title, $args );
		throw new RuntimeException( (string) $message );
	}
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return 'upr-test-salt-' . $scheme;
	}
}

if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['upr_test_env'] ?? 'production';
	}
}

if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (bool) ( $GLOBALS['upr_test_ssl'] ?? true );
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return $GLOBALS['upr_test_posts'][ $id ] ?? null;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $domain );
		return $text;
	}
}

