<?php
/**
 * WP-CLI commands.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CLI;

use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Invitations\ReconciliationService;

defined( 'ABSPATH' ) || exit;

final class Commands {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'upr reconcile-invitations', array( self::class, 'reconcile' ) );
		\WP_CLI::add_command( 'upr db-upgrade', array( self::class, 'db_upgrade' ) );
	}

	/**
	 * Reconcile invitation schedules.
	 *
	 * ## OPTIONS
	 *
	 * [--lookback-days=<days>]
	 * : Lookback window. Default 90.
	 *
	 * [--dry-run]
	 * : Print planned actions with zero writes (including no audit).
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function reconcile( array $args, array $assoc ): void {
		unset( $args );
		$lookback = isset( $assoc['lookback-days'] ) ? (int) $assoc['lookback-days'] : 90;
		$dry_run  = isset( $assoc['dry-run'] );
		$summary  = ReconciliationService::run( $lookback, $dry_run );
		\WP_CLI::log( wp_json_encode( $summary, JSON_PRETTY_PRINT ) );
		\WP_CLI::success( $dry_run ? 'Dry-run complete (zero writes).' : 'Reconcile complete.' );
	}

	public static function db_upgrade(): void {
		$ok = Migrator::upgrade_now();
		\UniversalProductReviews\Http\RewriteRules::flush_controlled();
		if ( $ok ) {
			\WP_CLI::success( 'Database schema is up to date.' );
		} else {
			\WP_CLI::error( 'Database upgrade did not complete.' );
		}
	}
}
