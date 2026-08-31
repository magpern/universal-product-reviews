<?php
/**
 * Bounded SQL aggregates for M4 Overview / Diagnostics.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ScheduleStates;

defined( 'ABSPATH' ) || exit;

final class OverviewRepository {

	public const RECENT_AUDIT_LIMIT = 25;
	public const ACTIVITY_DAYS      = 30;
	public const EMAIL_FAILED_HOURS = 24;
	public const EMAIL_FAILED_WARN  = 5;
	public const RECONCILE_MAX_AGE  = 172800; // 48h

	/**
	 * Open (non-terminal) invitation rows — includes old still-active rows.
	 *
	 * @return array{ok:bool,by_state:array<string,int>,total_open:int,error:?string}
	 */
	public static function open_workload_counts(): array {
		global $wpdb;
		$table = InviteRepository::table();
		$terminal = ScheduleStates::sending_terminal();
		$placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );
		$sql = "SELECT schedule_state, COUNT(*) AS c FROM {$table} WHERE schedule_state NOT IN ({$placeholders}) GROUP BY schedule_state";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from fixed constants.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$terminal ), ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array( 'ok' => false, 'by_state' => array(), 'total_open' => 0, 'error' => 'query_failed' );
		}
		$by    = array();
		$total = 0;
		foreach ( $rows as $row ) {
			$state = (string) ( $row['schedule_state'] ?? '' );
			$c     = (int) ( $row['c'] ?? 0 );
			$by[ $state ] = $c;
			$total       += $c;
		}
		return array( 'ok' => true, 'by_state' => $by, 'total_open' => $total, 'error' => null );
	}

	/**
	 * Lifecycle counts for rows updated in the recent activity window.
	 *
	 * @return array{ok:bool,by_state:array<string,int>,error:?string}
	 */
	public static function recent_lifecycle_counts( int $days = self::ACTIVITY_DAYS ): array {
		global $wpdb;
		$table  = InviteRepository::table();
		$since  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT schedule_state, COUNT(*) AS c FROM {$table} WHERE updated_at >= %s GROUP BY schedule_state",
				$since
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array( 'ok' => false, 'by_state' => array(), 'error' => 'query_failed' );
		}
		$by = array();
		foreach ( $rows as $row ) {
			$by[ (string) $row['schedule_state'] ] = (int) $row['c'];
		}
		return array( 'ok' => true, 'by_state' => $by, 'error' => null );
	}

	/**
	 * @return array{ok:bool,count:int,error:?string}
	 */
	public static function stale_send_claim_count(): array {
		try {
			$rows = InviteRepository::find_stale_sending( Options::send_claim_stale_minutes() );
			return array( 'ok' => true, 'count' => count( $rows ), 'error' => null );
		} catch ( \Throwable $e ) {
			return array( 'ok' => false, 'count' => 0, 'error' => 'query_failed' );
		}
	}

	/**
	 * @return array{ok:bool,count:int,error:?string}
	 */
	public static function expired_submit_claim_count(): array {
		global $wpdb;
		$table = InviteRepository::table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$n     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE schedule_state = %s AND (submit_claim_expires_at IS NULL OR submit_claim_expires_at < %s)",
				ScheduleStates::SUBMITTING,
				$now
			)
		);
		if ( null === $n && ! empty( $wpdb->last_error ) ) {
			return array( 'ok' => false, 'count' => 0, 'error' => 'query_failed' );
		}
		return array( 'ok' => true, 'count' => (int) $n, 'error' => null );
	}

	/**
	 * @return array{ok:bool,count:int,error:?string}
	 */
	public static function overdue_delayed_count(): array {
		global $wpdb;
		$table = InviteRepository::table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$n     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE schedule_state = %s AND delay_until IS NOT NULL AND delay_until < %s",
				ScheduleStates::DELAYED,
				$now
			)
		);
		if ( null === $n && ! empty( $wpdb->last_error ) ) {
			return array( 'ok' => false, 'count' => 0, 'error' => 'query_failed' );
		}
		return array( 'ok' => true, 'count' => (int) $n, 'error' => null );
	}

	/**
	 * @return array{ok:bool,count:int,error:?string}
	 */
	public static function email_failed_count_24h(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'upr_audit';
		$since = gmdate( 'Y-m-d H:i:s', time() - ( self::EMAIL_FAILED_HOURS * HOUR_IN_SECONDS ) );
		$n     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_type = %s AND occurred_at >= %s",
				'email.failed',
				$since
			)
		);
		if ( null === $n && ! empty( $wpdb->last_error ) ) {
			return array( 'ok' => false, 'count' => 0, 'error' => 'query_failed' );
		}
		return array( 'ok' => true, 'count' => (int) $n, 'error' => null );
	}

	/**
	 * Newest reconcile.completed audit row or null.
	 *
	 * @return array{found:bool,occurred_at:?string,counters:array<string,int>}|array{found:false}
	 */
	public static function last_reconcile_completed(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'upr_audit';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT occurred_at, payload_json FROM {$table} WHERE event_type = %s ORDER BY id DESC LIMIT 1",
				'reconcile.completed'
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return array( 'found' => false );
		}
		$payload = array();
		if ( ! empty( $row['payload_json'] ) ) {
			$decoded = json_decode( (string) $row['payload_json'], true );
			if ( is_array( $decoded ) ) {
				$allow = array(
					'orders_scanned',
					'rows_upserted',
					'suppressed',
					'claims_recovered',
					'orphans_repaired',
					'submit_claims_released',
					'authorisation_denied_skipped',
				);
				foreach ( $allow as $key ) {
					if ( isset( $decoded[ $key ] ) ) {
						$payload[ $key ] = (int) $decoded[ $key ];
					}
				}
			}
		}
		return array(
			'found'       => true,
			'occurred_at' => (string) $row['occurred_at'],
			'counters'    => $payload,
		);
	}

	/**
	 * Recent audit rows — allowlisted columns only (order_id may appear for admins).
	 *
	 * @return list<array{occurred_at:string,event_type:string,actor_type:string,order_id:?int}>
	 */
	public static function recent_audit_allowlisted( int $limit = self::RECENT_AUDIT_LIMIT ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'upr_audit';
		$limit = max( 1, min( self::RECENT_AUDIT_LIMIT, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT occurred_at, event_type, actor_type, order_id FROM {$table} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'occurred_at' => (string) ( $row['occurred_at'] ?? '' ),
				'event_type'  => (string) ( $row['event_type'] ?? '' ),
				'actor_type'  => (string) ( $row['actor_type'] ?? '' ),
				'order_id'    => isset( $row['order_id'] ) && null !== $row['order_id'] && '' !== $row['order_id']
					? (int) $row['order_id']
					: null,
			);
		}
		return $out;
	}

	public static function schema_is_current(): bool {
		return ! Migrator::needs_upgrade();
	}

	/**
	 * Bounded COUNT of held in-scope product reviews (no ID/body fetch).
	 *
	 * @return array{ok:bool,count:int,error:?string}
	 */
	public static function held_product_review_count(): array {
		$cached = get_transient( 'upr_held_review_count_v1' );
		if ( is_array( $cached ) && array_key_exists( 'ok', $cached ) ) {
			return array(
				'ok'    => (bool) $cached['ok'],
				'count' => (int) ( $cached['count'] ?? 0 ),
				'error' => isset( $cached['error'] ) ? (string) $cached['error'] : null,
			);
		}

		global $wpdb;
		$n = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->comments} c
			INNER JOIN {$wpdb->posts} p ON p.ID = c.comment_post_ID
			WHERE c.comment_type = 'review'
			AND c.comment_approved = '0'
			AND p.post_type = 'product'"
		);
		if ( null === $n && ! empty( $wpdb->last_error ) ) {
			$result = array( 'ok' => false, 'count' => 0, 'error' => 'query_failed' );
			set_transient( 'upr_held_review_count_v1', $result, 60 );
			return $result;
		}

		$result = array( 'ok' => true, 'count' => (int) $n, 'error' => null );
		set_transient( 'upr_held_review_count_v1', $result, 60 );
		return $result;
	}
}
