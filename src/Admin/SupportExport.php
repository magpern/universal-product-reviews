<?php
/**
 * Allowlisted local support export (no PII / order IDs).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

use UniversalProductReviews\Admin\Diagnostics\DiagnosticsService;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class SupportExport {

	public const SCHEMA_VERSION = 'upr-support-export/v1';
	public const WINDOW_DAYS    = 7;

	/**
	 * Build allowlisted export payload.
	 *
	 * @return array<string, mixed>
	 */
	public static function build(): array {
		$diagnostics = array();
		try {
			foreach ( DiagnosticsService::run() as $row ) {
				$diagnostics[] = array(
					'id'            => (string) ( $row['id'] ?? '' ),
					'status'        => (string) ( $row['status'] ?? '' ),
					'severity'      => (string) ( $row['severity'] ?? '' ),
					'evidence_code' => (string) ( $row['evidence_code'] ?? '' ),
				);
			}
		} catch ( \Throwable $e ) {
			$diagnostics = array();
		}

		$lifecycle = array();
		try {
			$lc = OverviewRepository::recent_lifecycle_counts( self::WINDOW_DAYS );
			if ( ! empty( $lc['ok'] ) ) {
				$lifecycle = $lc['by_state'];
			}
		} catch ( \Throwable $e ) {
			$lifecycle = array();
		}

		$stale = 0;
		try {
			$s = OverviewRepository::stale_send_claim_count();
			if ( ! empty( $s['ok'] ) ) {
				$stale = (int) $s['count'];
			}
		} catch ( \Throwable $e ) {
			$stale = 0;
		}

		$expired_submit = 0;
		try {
			$e = OverviewRepository::expired_submit_claim_count();
			if ( ! empty( $e['ok'] ) ) {
				$expired_submit = (int) $e['count'];
			}
		} catch ( \Throwable $e ) {
			$expired_submit = 0;
		}

		$email_failed = self::email_failed_count_window( self::WINDOW_DAYS );

		$last_reconcile = array( 'no_recorded_run' => true );
		try {
			$last = OverviewRepository::last_reconcile_completed();
			if ( ! empty( $last['found'] ) ) {
				$last_reconcile = array(
					'occurred_at' => (string) ( $last['occurred_at'] ?? '' ),
					'counters'    => isset( $last['counters'] ) && is_array( $last['counters'] ) ? $last['counters'] : array(),
				);
			}
		} catch ( \Throwable $e ) {
			$last_reconcile = array( 'no_recorded_run' => true );
		}

		return array(
			'schema_version'               => self::SCHEMA_VERSION,
			'plugin_version'               => defined( 'UPR_VERSION' ) ? (string) UPR_VERSION : '',
			'db_version_option'            => (string) get_option( Migrator::OPTION_VERSION, '' ),
			'schema_target'                => Schema::DB_VERSION,
			'invitation_emails_enabled'    => Options::invitation_emails_enabled(),
			'emergency_pause'              => Options::invitation_emergency_pause(),
			'scheduling_boundary_set'      => Options::invitation_scheduling_boundary_unix() > 0,
			'window_days'                  => self::WINDOW_DAYS,
			'diagnostics'                  => $diagnostics,
			'aggregates'                   => array(
				'lifecycle_by_state'       => $lifecycle,
				'email_failed_count'       => $email_failed,
				'stale_send_claim_count'   => $stale,
				'expired_submit_claim_count' => $expired_submit,
			),
			'last_reconcile'               => $last_reconcile,
		);
	}

	/**
	 * Send download headers for a JSON attachment.
	 */
	public static function download_headers( string $filename = '' ): void {
		if ( '' === $filename ) {
			$filename = 'upr-support-export-' . gmdate( 'Ymd-His' ) . '.json';
		}
		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'X-Content-Type-Options: nosniff' );
	}

	/**
	 * Echo pretty JSON (caller owns headers / exit).
	 *
	 * @param array<string, mixed> $payload
	 */
	public static function output_json( array $payload ): void {
		$json = function_exists( 'wp_json_encode' )
			? wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
			: json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		echo false === $json ? '{}' : $json;
	}

	/**
	 * Bounded email.failed count for export window (no row dump).
	 */
	private static function email_failed_count_window( int $days ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return 0;
		}
		try {
			$table = $wpdb->prefix . 'upr_audit';
			$since = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
			$n     = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND occurred_at >= %s",
					'email.failed',
					$since
				)
			);
			return (int) $n;
		} catch ( \Throwable $e ) {
			return 0;
		}
	}
}
