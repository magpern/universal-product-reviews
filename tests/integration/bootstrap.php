<?php
/**
 * Integration test bootstrap.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

$upr_root = dirname( __DIR__, 2 );

require_once $upr_root . '/vendor/autoload.php';

if ( ! defined( 'UPR_VERSION' ) ) {
	define( 'UPR_VERSION', '0.0.0-test' );
}

if ( ! defined( 'UPR_PLUGIN_FILE' ) ) {
	define( 'UPR_PLUGIN_FILE', $upr_root . '/universal-product-reviews.php' );
}

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

tests_add_filter(
	'setup_theme',
	static function (): void {
		if ( class_exists( 'WC_Install' ) ) {
			\WC_Install::install();
		}
		$GLOBALS['wp_roles'] = new \WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}
);

require_once $upr_tests_dir . '/includes/bootstrap.php';

require_once UPR_PLUGIN_FILE;

\UniversalProductReviews\Plugin::init();
