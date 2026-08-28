<?php
/**
 * Site-wide moderation ops rate limit and circuit breaker (single row).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class ModerationOpsRepository {

	public const RATE_LIMIT_PER_HOUR     = 60;
	public const CIRCUIT_FAILURE_THRESHOLD = 10;
	public const CIRCUIT_OPEN_MINUTES    = 30;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'upr_moderation_ops';
	}

	/**
	 * @return 'ok'|'rate_limited'|'circuit_open'
	 */
	public static function try_consume_rate_and_check_circuit(): string {
		global $wpdb;

		if ( self::is_circuit_open() ) {
			return 'circuit_open';
		}

		$table    = self::table();
		$now      = gmdate( 'Y-m-d H:i:s' );
		$hour_ago = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// Assign rate_count BEFORE rate_window_started_at. MySQL evaluates UPDATE
		// assignments left-to-right and later expressions see new values; resetting
		// the window first would make the count branch see a fresh window and
		// increment the stale count (e.g. 60 → 61) instead of resetting to 1.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET
					rate_count = CASE WHEN rate_window_started_at < %s THEN 1 ELSE rate_count + 1 END,
					rate_window_started_at = CASE WHEN rate_window_started_at < %s THEN %s ELSE rate_window_started_at END,
					updated_at = %s
				WHERE id = %d
				AND (circuit_open_until IS NULL OR circuit_open_until <= %s)
				AND (
					rate_window_started_at < %s
					OR rate_count < %d
				)",
				$hour_ago,
				$hour_ago,
				$now,
				$now,
				Schema::OPS_ROW_ID,
				$now,
				$hour_ago,
				self::RATE_LIMIT_PER_HOUR
			)
		);

		if ( 1 === (int) $n ) {
			return 'ok';
		}

		if ( self::is_circuit_open() ) {
			return 'circuit_open';
		}

		return 'rate_limited';
	}

	public static function record_success(): void {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET consecutive_failures = 0, updated_at = %s WHERE id = %d",
				$now,
				Schema::OPS_ROW_ID
			)
		);
	}

	public static function record_failure(): void {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );
		$open  = gmdate( 'Y-m-d H:i:s', time() + ( self::CIRCUIT_OPEN_MINUTES * MINUTE_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET
					consecutive_failures = consecutive_failures + 1,
					circuit_open_until = CASE
						WHEN consecutive_failures + 1 >= %d THEN %s
						ELSE circuit_open_until
					END,
					updated_at = %s
				WHERE id = %d",
				self::CIRCUIT_FAILURE_THRESHOLD,
				$open,
				$now,
				Schema::OPS_ROW_ID
			)
		);
	}

	/**
	 * Read-only ops summary for diagnostics (no side effects).
	 *
	 * @return array{ok:bool,circuit_open:bool,rate_count:int,rate_limited:bool}
	 */
	public static function summarize(): array {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT rate_window_started_at, rate_count, circuit_open_until FROM {$table} WHERE id = %d LIMIT 1",
				Schema::OPS_ROW_ID
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array(
				'ok'           => false,
				'circuit_open' => false,
				'rate_count'   => 0,
				'rate_limited' => false,
			);
		}

		$now          = gmdate( 'Y-m-d H:i:s' );
		$circuit_open = is_string( $row['circuit_open_until'] ?? null )
			&& '' !== $row['circuit_open_until']
			&& $row['circuit_open_until'] > $now;

		$hour_ago     = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );
		$window_start = (string) ( $row['rate_window_started_at'] ?? '' );
		$rate_count   = (int) ( $row['rate_count'] ?? 0 );
		$rate_limited = '' !== $window_start && $window_start >= $hour_ago && $rate_count >= self::RATE_LIMIT_PER_HOUR;

		return array(
			'ok'           => true,
			'circuit_open' => $circuit_open,
			'rate_count'   => $rate_count,
			'rate_limited' => $rate_limited,
		);
	}

	private static function is_circuit_open(): bool {
		global $wpdb;

		$table = self::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$until = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT circuit_open_until FROM {$table} WHERE id = %d LIMIT 1",
				Schema::OPS_ROW_ID
			)
		);

		if ( ! is_string( $until ) || '' === $until ) {
			return false;
		}

		return $until > $now;
	}
}
