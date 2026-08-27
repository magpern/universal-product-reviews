<?php
/**
 * Plugin service bootstrap (M1 + M2 + M4).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews;

use UniversalProductReviews\Admin\AdminController;
use UniversalProductReviews\CLI\Commands;
use UniversalProductReviews\Http\RewriteRules;
use UniversalProductReviews\Invitations\InvitationScheduler;
use UniversalProductReviews\Invitations\SuppressionService;
use UniversalProductReviews\Moderation\ReviewModeration;
use UniversalProductReviews\Scheduling\Jobs;
use UniversalProductReviews\Submission\GuestSubmissionGuard;
use UniversalProductReviews\Submission\NativeSubmissionGuard;
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
		NativeSubmissionGuard::register();
		ReviewAvailability::register();

		InvitationScheduler::register();
		SuppressionService::register();
		Jobs::register();
		RewriteRules::register();
		Commands::register();
		AdminController::register();
	}

	/**
	 * Test seam: reset bootstrap state between integration tests.
	 */
	public static function reset_for_tests(): void {
		self::$initialized = false;
	}
}
