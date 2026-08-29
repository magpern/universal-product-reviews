<?php
/**
 * Durable per-comment assessment claims (before terminal row insert).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

final class AssessmentClaimsRepository {

	public const CLAIM_TTL_SECONDS = 60;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'upr_moderation_assessment_claims';
	}

	public static function ensure_row( int $comment_id, string $policy_version ): void {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (comment_id, policy_version, claim_token, claim_expires_at, claim_provider_kind, requested_at, updated_at)
				VALUES (%d, %s, NULL, NULL, NULL, %s, %s)",
				$comment_id,
				$policy_version,
				$now,
				$now
			)
		);
	}

	/**
	 * Acquire exclusive claim when free or expired.
	 *
	 * Persists immutable claim_provider_kind for the acquisition (`local`|`openai`)
	 * so later operator option changes cannot reclassify an in-flight claim.
	 *
	 * @param 'local'|'openai'|null $provider_kind Null → current ProviderResolver::kind().
	 */
	public static function acquire( int $comment_id, string $policy_version, ?string $provider_kind = null ): ?string {
		global $wpdb;

		self::ensure_row( $comment_id, $policy_version );

		$kind = self::normalize_provider_kind( $provider_kind ?? ProviderResolver::kind() );

		$token   = wp_generate_uuid4();
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::CLAIM_TTL_SECONDS );
		$table   = self::table();
		$now     = current_time( 'mysql', true );
		$cutoff  = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET claim_token = %s, claim_expires_at = %s, claim_provider_kind = %s, requested_at = %s, updated_at = %s
				WHERE comment_id = %d AND policy_version = %s
				AND (claim_token IS NULL OR claim_expires_at IS NULL OR claim_expires_at < %s)",
				$token,
				$expires,
				$kind,
				$now,
				$now,
				$comment_id,
				$policy_version,
				$cutoff
			)
		);

		if ( 1 !== (int) $n ) {
			return null;
		}

		return $token;
	}

	public static function clear_owned( int $comment_id, string $policy_version, string $claim_token ): void {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET claim_token = NULL, claim_expires_at = NULL, claim_provider_kind = NULL, updated_at = %s
				WHERE comment_id = %d AND policy_version = %s AND claim_token = %s",
				$now,
				$comment_id,
				$policy_version,
				$claim_token
			)
		);
	}

	public static function clear_any_active( int $comment_id, string $policy_version ): void {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET claim_token = NULL, claim_expires_at = NULL, claim_provider_kind = NULL, updated_at = %s
				WHERE comment_id = %d AND policy_version = %s AND claim_token IS NOT NULL",
				$now,
				$comment_id,
				$policy_version
			)
		);
	}

	/**
	 * Clear active claims acquired for a specific provider kind only.
	 * Does not insert terminal rows or emit AI audit.
	 *
	 * @param 'local'|'openai' $provider_kind
	 */
	public static function clear_all_active_for_provider( string $policy_version, string $provider_kind ): void {
		global $wpdb;

		$kind  = self::normalize_provider_kind( $provider_kind );
		$table = self::table();
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET claim_token = NULL, claim_expires_at = NULL, claim_provider_kind = NULL, updated_at = %s
				WHERE policy_version = %s AND claim_token IS NOT NULL AND claim_provider_kind = %s",
				$now,
				$policy_version,
				$kind
			)
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_row( int $comment_id, string $policy_version ): ?array {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE comment_id = %d AND policy_version = %s LIMIT 1",
				$comment_id,
				$policy_version
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public static function has_active_claim( int $comment_id, string $policy_version ): bool {
		$row = self::get_row( $comment_id, $policy_version );
		if ( ! $row || empty( $row['claim_token'] ) || empty( $row['claim_expires_at'] ) ) {
			return false;
		}
		return strtotime( (string) $row['claim_expires_at'] . ' UTC' ) > time();
	}

	/**
	 * @param mixed $kind Raw kind.
	 * @return 'local'|'openai'
	 */
	private static function normalize_provider_kind( $kind ): string {
		return 'openai' === $kind ? 'openai' : 'local';
	}
}
