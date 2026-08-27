<?php
/**
 * WP-CLI commands.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CLI;

use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Invitations\EmergencyPause;
use UniversalProductReviews\Invitations\ReconciliationService;

defined( 'ABSPATH' ) || exit;

final class Commands {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'upr reconcile-invitations', array( self::class, 'reconcile' ) );
		\WP_CLI::add_command( 'upr db-upgrade', array( self::class, 'db_upgrade' ) );
		\WP_CLI::add_command( 'upr invitation-controls', array( self::class, 'invitation_controls' ) );
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

	/**
	 * Show invitation email control status (no PII).
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function invitation_controls( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$meta = EmergencyPause::meta();
		\WP_CLI::log(
			wp_json_encode(
				array(
					'invitation_emails_enabled'  => Options::invitation_emails_enabled(),
					'invitation_emergency_pause' => Options::invitation_emergency_pause(),
					'controls_epoch'             => Options::invitation_controls_epoch(),
					'scheduling_boundary_unix'   => Options::invitation_scheduling_boundary_unix(),
					'pause_meta'                 => array(
						'reason'     => $meta['reason'],
						'actor_id'   => $meta['actor_id'],
						'changed_at' => $meta['changed_at'],
					),
				),
				JSON_PRETTY_PRINT
			)
		);
	}
}
