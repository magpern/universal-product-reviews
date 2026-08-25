<?php
/**
 * WooCommerce availability gate.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class WooCommerceGate {

	public static function is_active(): bool {
		return class_exists( 'WooCommerce' );
	}
}
