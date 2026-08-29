<?php
/**
 * Atomic daily + monthly external OpenAI request quotas (single row).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class ExternalQuotaRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'upr_moderation_external_ops';
	}

	/**
	 * Atomically consume one request against both daily and monthly caps.
	 *
	 * @return 'ok'|'budget_exceeded'
	 */
	public static function try_consume( int $daily_cap, int $monthly_cap ): string {
		global $wpdb;

		$daily_cap   = max( 1, $daily_cap );
		$monthly_cap = max( 1, $monthly_cap );
		$table       = self::table();
		$day_key     = gmdate( 'Y-m-d' );
		$month_key   = gmdate( 'Y-m' );
		$now         = gmdate( 'Y-m-d H:i:s' );

		$wpdb->query( 'START TRANSACTION' );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT day_key, day_count, month_key, month_count FROM {$table} WHERE id = %d FOR UPDATE",
					Schema::OPS_ROW_ID
				),
				ARRAY_A
			);

			if ( ! is_array( $row ) ) {
				$wpdb->query( 'ROLLBACK' );
				return 'budget_exceeded';
			}

			$day_count   = (string) ( $row['day_key'] ?? '' ) === $day_key ? (int) ( $row['day_count'] ?? 0 ) : 0;
			$month_count = (string) ( $row['month_key'] ?? '' ) === $month_key ? (int) ( $row['month_count'] ?? 0 ) : 0;

			if ( ( $day_count + 1 ) > $daily_cap || ( $month_count + 1 ) > $monthly_cap ) {
				$wpdb->query( 'ROLLBACK' );
				return 'budget_exceeded';
			}

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$n = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET day_key = %s, day_count = %d, month_key = %s, month_count = %d, updated_at = %s WHERE id = %d",
					$day_key,
					$day_count + 1,
					$month_key,
					$month_count + 1,
					$now,
					Schema::OPS_ROW_ID
				)
			);

			if ( 1 !== (int) $n ) {
				$wpdb->query( 'ROLLBACK' );
				return 'budget_exceeded';
			}

			$wpdb->query( 'COMMIT' );
			return 'ok';
		} catch ( \Throwable $e ) {
			unset( $e );
			$wpdb->query( 'ROLLBACK' );
			return 'budget_exceeded';
		}
	}

	/**
	 * @return array{ok:bool,day_key:string,day_count:int,month_key:string,month_count:int}
	 */
	public static function summarize(): array {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT day_key, day_count, month_key, month_count FROM {$table} WHERE id = %d LIMIT 1",
				Schema::OPS_ROW_ID
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return array(
				'ok'          => false,
				'day_key'     => '',
				'day_count'   => 0,
				'month_key'   => '',
				'month_count' => 0,
			);
		}

		$day_key   = gmdate( 'Y-m-d' );
		$month_key = gmdate( 'Y-m' );

		return array(
			'ok'          => true,
			'day_key'     => $day_key,
			'day_count'   => (string) ( $row['day_key'] ?? '' ) === $day_key ? (int) ( $row['day_count'] ?? 0 ) : 0,
			'month_key'   => $month_key,
			'month_count' => (string) ( $row['month_key'] ?? '' ) === $month_key ? (int) ( $row['month_count'] ?? 0 ) : 0,
		);
	}
}
