<?php
/**
 * M0 foundation bootstrap — inert placeholder.
 *
 * No hooks, database tables, settings, cron, CLI, or review logic are
 * registered in M0. Future milestones extend this class.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/**
	 * M0: intentionally empty. Establishes package entry point only.
	 */
	public static function init(): void {
		// M1+ registers review-scoped moderation and related services here.
	}
}
