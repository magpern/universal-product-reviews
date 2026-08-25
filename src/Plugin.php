<?php
/**
 * M1 service bootstrap.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews;

use UniversalProductReviews\Moderation\ReviewModeration;
use UniversalProductReviews\Submission\GuestSubmissionGuard;
use UniversalProductReviews\Submission\ReviewAvailability;
use UniversalProductReviews\WooCommerce\WooCommerceGate;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized || ! WooCommerceGate::is_active() ) {
			return;
		}

		self::$initialized = true;

		ReviewModeration::register();
		GuestSubmissionGuard::register();
		ReviewAvailability::register();
	}

	/**
	 * Test seam: reset bootstrap state between integration tests.
	 */
	public static function reset_for_tests(): void {
		self::$initialized = false;
	}
}
