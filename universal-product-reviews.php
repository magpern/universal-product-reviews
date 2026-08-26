<?php
/**
 * Plugin Name: Universal Product Reviews
 * Plugin URI: https://github.com/magpern/universal-product-reviews
 * Description: Portable WooCommerce product-review operations (invitations, moderation, retention). M2 invitations and guest authorization.
 * Version: 0.2.0
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

define( 'UPR_VERSION', '0.2.0' );
define( 'UPR_PLUGIN_FILE', __FILE__ );
define( 'UPR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	return;
}

$upr_autoload = UPR_PLUGIN_DIR . 'vendor/autoload.php';
if ( is_readable( $upr_autoload ) ) {
	require_once $upr_autoload;
}

register_activation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \UniversalProductReviews\Activation::class ) ) {
			\UniversalProductReviews\Activation::activate();
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				UPR_PLUGIN_FILE,
				true
			);
		}
	}
);

add_action(
	'woocommerce_loaded',
	static function (): void {
		if ( class_exists( \UniversalProductReviews\Plugin::class ) ) {
			\UniversalProductReviews\Plugin::init();
		}
	}
);
