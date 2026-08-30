<?php
/**
 * M12 auto-spam control transitions (boundary refresh, silent claim clear).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

final class ActionControls {

	public static function set_master_enabled( bool $enabled ): void {
		$was = Options::ai_auto_spam_enabled();
		update_option( Options::AI_AUTO_SPAM_ENABLED, $enabled ? 'yes' : 'no', false );
		if ( $enabled && ! $was ) {
			Options::refresh_auto_action_boundary();
		}
		if ( ! $enabled ) {
			ActionLedgerRepository::clear_all_processing();
		}
	}

	public static function set_policy_enabled( bool $enabled ): void {
		update_option( Options::AI_AUTO_SPAM_POLICY_ENABLED, $enabled ? 'yes' : 'no', false );
		if ( ! $enabled ) {
			ActionLedgerRepository::clear_all_processing();
		}
	}

	public static function set_simulation_guard( bool $enabled ): void {
		update_option( Options::AI_AUTO_SPAM_SIMULATION_GUARD, $enabled ? 'yes' : 'no', false );
		if ( ! $enabled ) {
			ActionLedgerRepository::clear_all_processing();
		}
	}

	public static function set_dry_run( bool $enabled ): void {
		update_option( Options::AI_AUTO_SPAM_DRY_RUN, $enabled ? 'yes' : 'no', false );
	}

	public static function set_kill_switch( bool $enabled ): void {
		update_option( Options::AI_AUTO_SPAM_KILL_SWITCH, $enabled ? 'yes' : 'no', false );
		if ( $enabled ) {
			ActionLedgerRepository::clear_all_processing();
		}
	}
}
