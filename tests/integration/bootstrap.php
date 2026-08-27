<?php
/**
 * Integration test bootstrap.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

$upr_root = dirname( __DIR__, 2 );

require_once $upr_root . '/vendor/autoload.php';
require_once dirname( __FILE__ ) . '/M2TestHelpers.php';

$upr_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: $upr_root . '/vendor/wp-phpunit/wp-phpunit';

if ( ! getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . dirname( __DIR__ ) . '/wp-tests-config.php' );
}

require_once $upr_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
	}
);

/*
 * Declare HPOS compatibility before WooCommerce finishes bootstrapping.
 * Use the install-wp.sh copy under WP_PLUGIN_DIR so FeaturesUtil plugin IDs match.
 */
tests_add_filter(
	'before_woocommerce_init',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		$plugin_file = WP_PLUGIN_DIR . '/universal-product-reviews/universal-product-reviews.php';
		if ( ! is_readable( $plugin_file ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
			'custom_order_tables',
			$plugin_file,
			true
		);
	}
);

require_once $upr_tests_dir . '/includes/bootstrap.php';

$upr_plugin_file = WP_PLUGIN_DIR . '/universal-product-reviews/universal-product-reviews.php';
if ( ! is_readable( $upr_plugin_file ) ) {
	$upr_plugin_file = $upr_root . '/universal-product-reviews.php';
}

require_once $upr_plugin_file;

\UniversalProductReviews\Plugin::init();
