<?php
/**
 * Plugin Name: Universal Product Reviews
 * Plugin URI: https://github.com/magpern/universal-product-reviews
 * Description: Portable WooCommerce product-review operations (invitations, moderation, retention). M0 foundation scaffold — no runtime capability yet.
 * Version: 0.0.0
 * Author: magpern
 * License: Proprietary
 * Text Domain: universal-product-reviews
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 *
 * @package UniversalProductReviews
 */

defined( 'ABSPATH' ) || exit;

define( 'UPR_VERSION', '0.0.0' );
define( 'UPR_PLUGIN_FILE', __FILE__ );
define( 'UPR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	return;
}

$upr_autoload = UPR_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $upr_autoload ) ) {
	require_once $upr_autoload;
}

if ( class_exists( \UniversalProductReviews\Plugin::class ) ) {
	\UniversalProductReviews\Plugin::init();
}
