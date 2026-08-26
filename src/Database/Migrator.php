<?php
/**
 * Versioned, locked schema migrator with atomic lease lock.
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

	/** @var string|null Owner token for the current process lease. */
	private static ?string $owner_token = null;

	/** @var int Test counter for schema executions. */
	private static int $schema_runs = 0;

	public static function needs_upgrade(): bool {
		$installed = (string) get_option( self::OPTION_VERSION, '' );
		if ( $installed !== Schema::DB_VERSION ) {
			return true;
		}
		return ! self::tables_exist();
	}

	/**
	 * True when all schema tables are present.
	 */
	public static function tables_exist(): bool {
		global $wpdb;
		foreach ( array_keys( Schema::table_definitions() ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Run upgrade under lock. Safe for activation, admin, CLI, or guarded AS.
	 *
	 * @return bool True if schema is at target version after call.
	 */
	public static function upgrade_now(): bool {
		self::reap_expired_lock();

		if ( ! self::needs_upgrade() ) {
			return true;
		}

		$acquired = false;
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			if ( self::acquire_lock() ) {
				$acquired = true;
				break;
			}
			usleep( 100000 );
			self::reap_expired_lock();
			if ( ! self::needs_upgrade() ) {
				return true;
			}
		}

		if ( ! $acquired ) {
			return ! self::needs_upgrade();
		}

		try {
			if ( ! self::needs_upgrade() ) {
				return true;
			}
			self::run_dbdelta();
			update_option( self::OPTION_VERSION, Schema::DB_VERSION, true );
			return self::tables_exist();
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Controlled upgrade entry for admin/cron/CLI contexts only.
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

	public static function schema_run_count(): int {
		return self::$schema_runs;
	}

	public static function reset_schema_run_count(): void {
		self::$schema_runs = 0;
	}

	/**
	 * @return string|null Current process owner token if lock held.
	 */
	public static function current_owner_token(): ?string {
		return self::$owner_token;
	}

	/**
	 * Public for tests — attempt to acquire the migrate lock.
	 */
	public static function try_acquire_lock(): bool {
		return self::acquire_lock();
	}

	/**
	 * Public for tests — release if still owner.
	 */
	public static function try_release_lock(): void {
		self::release_lock();
	}

	private static function run_dbdelta(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		foreach ( Schema::table_definitions() as $sql ) {
			dbDelta( $sql );
		}
		++self::$schema_runs;
	}

	private static function acquire_lock(): bool {
		$token   = wp_generate_uuid4();
		$payload = array(
			'owner' => $token,
			'until' => time() + self::LOCK_TTL,
		);

		if ( add_option( self::LOCK_KEY, $payload, '', 'no' ) ) {
			self::$owner_token = $token;
			return true;
		}

		$existing = get_option( self::LOCK_KEY, null );
		if ( ! is_array( $existing ) || ! isset( $existing['until'], $existing['owner'] ) ) {
			// Corrupt lock — try CAS delete of whatever is stored then add.
			self::cas_delete_lock( $existing );
			if ( add_option( self::LOCK_KEY, $payload, '', 'no' ) ) {
				self::$owner_token = $token;
				return true;
			}
			return false;
		}

		if ( (int) $existing['until'] > time() ) {
			return false;
		}

		// Stale lock: delete only if value still exactly matches observed.
		if ( ! self::cas_delete_lock( $existing ) ) {
			return false;
		}

		if ( add_option( self::LOCK_KEY, $payload, '', 'no' ) ) {
			self::$owner_token = $token;
			return true;
		}

		return false;
	}

	private static function release_lock(): void {
		if ( null === self::$owner_token ) {
			return;
		}

		$current = get_option( self::LOCK_KEY, null );
		if (
			is_array( $current )
			&& isset( $current['owner'] )
			&& (string) $current['owner'] === self::$owner_token
		) {
			self::cas_delete_lock( $current );
		}

		self::$owner_token = null;
	}

	/**
	 * Delete lock option only when its serialized value still matches $observed.
	 *
	 * @param mixed $observed Previously read option value.
	 */
	private static function cas_delete_lock( $observed ): bool {
		global $wpdb;

		if ( null === $observed || false === $observed ) {
			return false;
		}

		$serialized = maybe_serialize( $observed );
		$n          = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::LOCK_KEY,
				$serialized
			)
		);

		wp_cache_delete( self::LOCK_KEY, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return (int) $n >= 1;
	}

	/**
	 * Drop an expired or corrupt lock via CAS so a fresh add_option can proceed.
	 */
	private static function reap_expired_lock(): void {
		$existing = get_option( self::LOCK_KEY, null );
		if ( null === $existing || false === $existing ) {
			return;
		}
		if ( ! is_array( $existing ) || ! isset( $existing['until'] ) ) {
			self::cas_delete_lock( $existing );
			return;
		}
		if ( (int) $existing['until'] > time() ) {
			return;
		}
		self::cas_delete_lock( $existing );
	}
}
