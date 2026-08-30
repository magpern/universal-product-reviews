<?php
/**
 * Durable M12 auto-spam action ledger with leased processing.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

final class ActionLedgerRepository {

	public const LEASE_TTL_SECONDS = 60;

	public const STATE_PROCESSING         = 'processing';
	public const STATE_CAS_SUCCEEDED      = 'cas_succeeded';
	public const STATE_ACTED              = 'acted';
	public const STATE_ABSTAINED          = 'abstained';
	public const STATE_OBSERVED           = 'observed';
	public const STATE_UNKNOWN_AFTER_CRASH = 'unknown_after_crash';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'upr_moderation_action_ledger';
	}

	public static function ensure_row( int $comment_id, int $assessment_id, string $action_policy_version ): void {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				(comment_id, assessment_id, action_policy_version, state, lease_token, lease_expires_at, lease_owner, ai_cas_committed_at, abstain_reason, crash_reason, dry_run, created_at, updated_at)
				VALUES (%d, %d, %s, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0, %s, %s)",
				$comment_id,
				$assessment_id,
				$action_policy_version,
				$now,
				$now
			)
		);
	}

	/**
	 * Acquire processing lease when free/expired and not blocked by terminal/cas_succeeded.
	 */
	public static function acquire_processing( int $comment_id, int $assessment_id, string $action_policy_version, string $owner = '' ): ?string {
		global $wpdb;

		self::ensure_row( $comment_id, $assessment_id, $action_policy_version );

		$row = self::get_row( $comment_id, $assessment_id, $action_policy_version );
		if ( is_array( $row ) ) {
			$state = (string) ( $row['state'] ?? '' );
			$blocking = array(
				self::STATE_CAS_SUCCEEDED,
				self::STATE_ACTED,
				self::STATE_ABSTAINED,
				self::STATE_OBSERVED,
				self::STATE_UNKNOWN_AFTER_CRASH,
			);
			if ( in_array( $state, $blocking, true ) ) {
				return null;
			}
			if ( self::STATE_PROCESSING === $state ) {
				$exp = (string) ( $row['lease_expires_at'] ?? '' );
				if ( '' !== $exp && strtotime( $exp . ' UTC' ) > time() ) {
					return null;
				}
			}
		}

		$token   = wp_generate_uuid4();
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_TTL_SECONDS );
		$table   = self::table();
		$now     = current_time( 'mysql', true );
		$cutoff  = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state = %s, lease_token = %s, lease_expires_at = %s, lease_owner = %s, updated_at = %s
				WHERE comment_id = %d AND assessment_id = %d AND action_policy_version = %s
				AND (
					state IS NULL OR state = ''
					OR (state = %s AND (lease_expires_at IS NULL OR lease_expires_at < %s))
				)",
				self::STATE_PROCESSING,
				$token,
				$expires,
				$owner,
				$now,
				$comment_id,
				$assessment_id,
				$action_policy_version,
				self::STATE_PROCESSING,
				$cutoff
			)
		);

		return 1 === (int) $n ? $token : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_row( int $comment_id, int $assessment_id, string $action_policy_version ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE comment_id = %d AND assessment_id = %d AND action_policy_version = %s",
				$comment_id,
				$assessment_id,
				$action_policy_version
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public static function transition_token_matched(
		int $comment_id,
		int $assessment_id,
		string $action_policy_version,
		string $lease_token,
		string $from_state,
		string $to_state,
		?string $abstain_reason = null,
		?string $crash_reason = null,
		?string $ai_cas_committed_at = null,
		bool $dry_run = false
	): bool {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state = %s, abstain_reason = %s, crash_reason = %s, ai_cas_committed_at = COALESCE(%s, ai_cas_committed_at),
				dry_run = %d, lease_token = NULL, lease_expires_at = NULL, lease_owner = NULL, updated_at = %s
				WHERE comment_id = %d AND assessment_id = %d AND action_policy_version = %s
				AND lease_token = %s AND state = %s",
				$to_state,
				$abstain_reason,
				$crash_reason,
				$ai_cas_committed_at,
				$dry_run ? 1 : 0,
				$now,
				$comment_id,
				$assessment_id,
				$action_policy_version,
				$lease_token,
				$from_state
			)
		);
		return 1 === (int) $n;
	}

	/**
	 * Clear in-flight processing leases without inventing terminal AI action rows.
	 */
	public static function clear_all_processing(): int {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state = NULL, lease_token = NULL, lease_expires_at = NULL, lease_owner = NULL, updated_at = %s
				WHERE state = %s",
				$now,
				self::STATE_PROCESSING
			)
		);
		return max( 0, (int) $n );
	}

	/**
	 * @return array<string, int>
	 */
	public static function counts_by_state(): array {
		global $wpdb;
		$table = self::table();
		$out   = array(
			self::STATE_PROCESSING          => 0,
			self::STATE_CAS_SUCCEEDED       => 0,
			self::STATE_ACTED               => 0,
			self::STATE_ABSTAINED           => 0,
			self::STATE_OBSERVED            => 0,
			self::STATE_UNKNOWN_AFTER_CRASH => 0,
		);
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT state, COUNT(*) AS c FROM {$table} WHERE state IS NOT NULL AND state != '' GROUP BY state", ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return $out;
		}
		foreach ( $rows as $row ) {
			$s = (string) ( $row['state'] ?? '' );
			if ( isset( $out[ $s ] ) ) {
				$out[ $s ] = (int) $row['c'];
			}
		}
		return $out;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function list_cas_succeeded(): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE state = %s",
				self::STATE_CAS_SUCCEEDED
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
