<?php
/**
 * Terminal moderation assessment rows.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

final class AssessmentRepository {

	public const PURGE_BATCH_SIZE = 100;

	/** @var bool Test seam: force insert_terminal() to fail without writing. */
	private static bool $force_insert_fail_for_tests = false;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'upr_moderation_assessments';
	}

	/**
	 * Test seam only — force the next insert_terminal() calls to return 0.
	 */
	public static function set_force_insert_fail_for_tests( bool $force ): void {
		self::$force_insert_fail_for_tests = $force;
	}

	/**
	 * @param list<string> $reason_codes
	 */
	public static function insert_terminal(
		int $comment_id,
		string $state,
		?int $publication_safety_score,
		?string $confidence,
		array $reason_codes,
		string $policy_version,
		?string $failure_code,
		string $requested_at,
		string $comment_status
	): int {
		global $wpdb;

		if ( self::$force_insert_fail_for_tests ) {
			return 0;
		}

		$completed_at = current_time( 'mysql', true );
		$retention    = AssessmentRetention::due_at_for_status( $comment_status, strtotime( $completed_at . ' UTC' ) );
		$codes_json   = array() !== $reason_codes ? wp_json_encode( array_values( $reason_codes ) ) : null;

		$inserted = $wpdb->insert(
			self::table(),
			array(
				'schema_version'           => PolicyAllowlist::SCHEMA_VERSION,
				'comment_id'               => $comment_id,
				'mode'                     => 'shadow',
				'state'                    => $state,
				'publication_safety_score' => $publication_safety_score,
				'confidence'               => $confidence,
				'reason_codes'             => $codes_json,
				'policy_version'           => $policy_version,
				'provider_kind'            => 'local',
				'provider_fingerprint'     => ProviderFingerprint::for_builtin( $policy_version ),
				'failure_code'             => $failure_code,
				'requested_at'             => $requested_at,
				'completed_at'             => $completed_at,
				'retention_due_at'         => $retention,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		if ( false === $inserted ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Latest terminal row per comment id.
	 *
	 * Authoritative ordering is monotonic `assessment_id` (insertion order).
	 * `completed_at` alone is second-resolution and can collide for same-second
	 * terminals; selecting by MAX(assessment_id) returns exactly one row.
	 *
	 * @param list<int> $comment_ids
	 * @return array<int, array<string, mixed>> Map of comment_id => assessment row.
	 */
	public static function latest_for_comments( array $comment_ids ): array {
		global $wpdb;

		$comment_ids = array_values( array_filter( array_map( 'intval', $comment_ids ) ) );
		if ( array() === $comment_ids ) {
			return array();
		}

		$table        = self::table();
		$placeholders = implode( ',', array_fill( 0, count( $comment_ids ), '%d' ) );
		// Existing KEY comment_completed (comment_id, completed_at) supports the
		// comment_id filter; join on PK assessment_id. No schema bump required.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPlaceholder
		$query = $wpdb->prepare(
			"SELECT a.* FROM {$table} a
			INNER JOIN (
				SELECT comment_id, MAX(assessment_id) AS max_id
				FROM {$table}
				WHERE comment_id IN ({$placeholders})
				GROUP BY comment_id
			) latest ON a.assessment_id = latest.max_id",
			...$comment_ids
		);

		$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['comment_id'], $row['assessment_id'] ) ) {
				continue;
			}
			$cid = (int) $row['comment_id'];
			$aid = (int) $row['assessment_id'];
			if ( ! isset( $out[ $cid ] ) || $aid > (int) $out[ $cid ]['assessment_id'] ) {
				$out[ $cid ] = $row;
			}
		}
		return $out;
	}

	public static function recompute_retention( int $comment_id, string $comment_status ): void {
		global $wpdb;

		$due   = AssessmentRetention::due_at_for_status( $comment_status, time() );
		$table = self::table();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET retention_due_at = %s WHERE comment_id = %d",
				$due,
				$comment_id
			)
		);
	}

	public static function purge_due( int $limit = self::PURGE_BATCH_SIZE ): int {
		global $wpdb;

		$table = self::table();
		$limit = max( 1, min( 500, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$n = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE retention_due_at <= UTC_TIMESTAMP() LIMIT %d",
				$limit
			)
		);

		return max( 0, (int) $n );
	}

	public static function delete_for_comment( int $comment_id ): void {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE comment_id = %d", $comment_id )
		);
	}

	/**
	 * Aggregate terminal state counts in the last 24 hours (UTC).
	 *
	 * @return array<string, int>
	 */
	public static function count_states_24h(): array {
		global $wpdb;

		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$rows = $wpdb->get_results(
			"SELECT state, COUNT(*) AS cnt FROM {$table}
			WHERE completed_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
			GROUP BY state",
			ARRAY_A
		);

		$out = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['state'] ) ) {
					continue;
				}
				$out[ (string) $row['state'] ] = (int) ( $row['cnt'] ?? 0 );
			}
		}
		return $out;
	}
}
