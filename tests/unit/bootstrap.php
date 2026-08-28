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

if ( ! class_exists( 'WP_User', false ) ) {
	class WP_User { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
		/** @var int */
		public $ID = 0;
		/** @var string */
		public $user_email = '';
	}
}

if ( ! function_exists( 'get_userdata' ) ) {
	function get_userdata( $user_id ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$user_id = (int) $user_id;
		if ( ! isset( $GLOBALS['upr_test_users'][ $user_id ] ) ) {
			return false;
		}
		$raw = $GLOBALS['upr_test_users'][ $user_id ];
		if ( $raw instanceof \WP_User ) {
			return $raw;
		}
		$user             = new \WP_User();
		$user->ID         = $user_id;
		$user->user_email = (string) ( $raw->user_email ?? '' );
		return $user;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (bool) ( $GLOBALS['upr_test_logged_in'] ?? false );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (int) ( $GLOBALS['upr_test_user_id'] ?? 0 );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( isset( $GLOBALS['upr_test_filters'][ $tag ] ) && is_array( $GLOBALS['upr_test_filters'][ $tag ] ) ) {
			foreach ( $GLOBALS['upr_test_filters'][ $tag ] as $cb ) {
				$value = $cb( $value, ...$args );
			}
			return $value;
		}
		if ( 'upr_product_review_availability' === $tag ) {
			$product_id = (int) ( $args[0] ?? 0 );
			$user_id    = (int) ( $args[1] ?? 0 );
			$base       = is_array( $value ) ? $value : array();
			return \UniversalProductReviews\Submission\ReviewAvailability::default_availability( $base, $product_id, $user_id );
		}
		unset( $tag, $args );
		return $value;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $priority, $accepted_args );
		$GLOBALS['upr_test_filters'][ $tag ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'has_filter' ) ) {
	function has_filter( $tag, $function_to_check = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $function_to_check );
		if ( empty( $GLOBALS['upr_test_filters'][ $tag ] ) || ! is_array( $GLOBALS['upr_test_filters'][ $tag ] ) ) {
			return false;
		}
		return 10;
	}
}

if ( ! function_exists( 'remove_all_filters' ) ) {
	function remove_all_filters( $tag, $priority = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $priority );
		unset( $GLOBALS['upr_test_filters'][ $tag ] );
		return true;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$key = strtolower( (string) $key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '';
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $transient ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $GLOBALS['upr_test_transients'][ $transient ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $transient, $value, $expiration = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $expiration );
		$GLOBALS['upr_test_transients'][ $transient ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $transient ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $GLOBALS['upr_test_transients'][ $transient ] );
		return true;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$str = (string) $str;
		$str = strip_tags( $str );
		return trim( $str );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return $value;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $gmt );
		if ( 'mysql' === $type ) {
			return gmdate( 'Y-m-d H:i:s' );
		}
		return time();
	}
}

if ( ! isset( $GLOBALS['wpdb'] ) ) {
	$GLOBALS['wpdb'] = new class() {
		/** @var string */
		public $prefix = 'wp_';
		/** @var string */
		public $last_error = '';
		/** @var string */
		public $options = 'wp_options';
		/** @var string */
		public $commentmeta = 'wp_commentmeta';

		public function get_charset_collate() {
			return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		}

		public function insert( $table, $data, $format = null ) {
			unset( $table, $data, $format );
			return 1;
		}

		public function prepare( $query, ...$args ) {
			$out = (string) $query;
			foreach ( $args as $arg ) {
				$out = preg_replace( '/%[sdfF]/', (string) $arg, $out, 1 ) ?? $out;
			}
			return $out;
		}

		public function get_var( $query = null ) {
			if ( is_string( $query ) && false !== stripos( $query, 'SHOW TABLES LIKE' ) ) {
				if ( ! empty( $GLOBALS['upr_test_missing_tables'] ) ) {
					return null;
				}
				if ( preg_match( '/SHOW TABLES LIKE\s+(\S+)/i', $query, $m ) ) {
					return trim( $m[1], "'\"`" );
				}
				return 'wp_upr_stub';
			}
			unset( $query );
			return null;
		}

		public function get_results( $query = null, $output = OBJECT ) {
			unset( $query, $output );
			return array();
		}

		public function get_row( $query = null, $output = OBJECT, $y = 0 ) {
			unset( $query, $output, $y );
			return null;
		}

		public function query( $query ) {
			unset( $query );
			return 0;
		}
	};
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $autoload );
		$GLOBALS['upr_test_options'][ $option ] = $value;
		return true;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$caps = $GLOBALS['upr_test_caps'] ?? array();
		return ! empty( $caps[ $capability ] );
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

if ( ! function_exists( 'wc_get_product' ) ) {
	function wc_get_product( $product = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$id = is_object( $product ) ? (int) $product->get_id() : (int) $product;
		return $GLOBALS['upr_test_wc_products'][ $id ] ?? false;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $domain );
		return $text;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (bool) ( $GLOBALS['upr_test_is_admin'] ?? false );
	}
}

if ( ! function_exists( 'wp_doing_ajax' ) ) {
	function wp_doing_ajax() { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		return (bool) ( $GLOBALS['upr_test_doing_ajax'] ?? false );
	}
}

if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( $nonce, $action = -1 ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		unset( $nonce, $action );
		return ! empty( $GLOBALS['upr_test_nonce_ok'] );
	}
}

if ( ! function_exists( 'get_comment_meta' ) ) {
	function get_comment_meta( $comment_id, $key = '', $single = false ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$comment_id = (int) $comment_id;
		$meta       = $GLOBALS['upr_test_comment_meta'][ $comment_id ] ?? array();
		if ( '' === $key ) {
			return $meta;
		}
		$value = $meta[ $key ] ?? '';
		return $single ? $value : array( $value );
	}
}

if ( ! function_exists( 'get_comment' ) ) {
	function get_comment( $comment = null ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		$id = is_object( $comment ) ? (int) $comment->comment_ID : (int) $comment;
		return $GLOBALS['upr_test_comments'][ $id ] ?? null;
	}
}

