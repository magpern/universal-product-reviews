<?php
/**
 * Versioned, locked schema migrator.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Database;

defined( 'ABSPATH' ) || exit;

final class Migrator {

	public const OPTION_VERSION = 'upr_db_version';
	public const LOCK_KEY       = 'upr_db_migrate_lock';
	public const LOCK_TTL       = 120;

	public static function needs_upgrade(): bool {
		$installed = (string) get_option( self::OPTION_VERSION, '' );
		return $installed !== Schema::DB_VERSION;
	}

	/**
	 * Run upgrade under lock. Safe for activation, admin, CLI, or guarded AS.
	 *
	 * @return bool True if schema is at target version after call.
	 */
	public static function upgrade_now(): bool {
		if ( ! self::needs_upgrade() ) {
			return true;
		}

		if ( ! self::acquire_lock() ) {
			// Another process is migrating; re-check shortly.
			usleep( 200000 );
			return ! self::needs_upgrade();
		}

		try {
			if ( ! self::needs_upgrade() ) {
				return true;
			}
			self::run_dbdelta();
			update_option( self::OPTION_VERSION, Schema::DB_VERSION, true );
			return true;
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Controlled upgrade entry for admin/cron contexts only.
	 */
	public static function maybe_upgrade_controlled(): void {
		if ( ! self::needs_upgrade() ) {
			return;
		}

		$allowed = is_admin()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON );

		if ( ! $allowed ) {
			return;
		}

		self::upgrade_now();
	}

	private static function run_dbdelta(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( Schema::table_definitions() as $sql ) {
			dbDelta( $sql );
		}
	}

	private static function acquire_lock(): bool {
		$now = time();
		$lock = get_option( self::LOCK_KEY, null );
		if ( is_array( $lock ) && isset( $lock['until'] ) && (int) $lock['until'] > $now ) {
			return false;
		}
		$payload = array(
			'until' => $now + self::LOCK_TTL,
			'pid'   => getmypid(),
		);
		if ( false === get_option( self::LOCK_KEY, false ) ) {
			return add_option( self::LOCK_KEY, $payload, '', 'no' );
		}
		update_option( self::LOCK_KEY, $payload, false );
		return true;
	}

	private static function release_lock(): void {
		delete_option( self::LOCK_KEY );
	}
}
