<?php
/**
 * Durable per-comment edit claims (E20).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

defined( 'ABSPATH' ) || exit;

final class EditClaimRepository {

	public const CLAIM_TTL_SECONDS = 300;
	public const LEASE_TTL_SECONDS = 60;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'upr_review_edit_claims';
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get( int $comment_id ): ?array {
		global $wpdb;
		if ( $comment_id <= 0 ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE comment_id = %d LIMIT 1", $comment_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array{claim_token:string,generation:int}|null
	 */
	public static function acquire(
		int $comment_id,
		string $auth_class,
		string $target_hmac,
		int $target_rating,
		string $prior_status,
		bool $content_changed = false,
		bool $rating_changed = false,
		string $prior_hmac = '',
		int $prior_rating = 0
	): ?array {
		global $wpdb;

		if ( $comment_id <= 0 || ! in_array( $auth_class, array( 'logged_in', 'guest_session' ), true ) ) {
			return null;
		}
		if ( $target_rating < 1 || $target_rating > 5 ) {
			return null;
		}

		$existing = self::get( $comment_id );
		$phase    = is_array( $existing ) ? (string) ( $existing['phase'] ?? '' ) : '';
		if ( is_array( $existing ) && empty( $existing['finalized_at'] ) && in_array( $phase, array( 'writing', 'content_written' ), true ) ) {
			return null;
		}

		$token   = wp_generate_uuid4();
		$now     = current_time( 'mysql', true );
		$expires = gmdate( 'Y-m-d H:i:s', time() + self::CLAIM_TTL_SECONDS );
		$table   = self::table();

		if ( null === $existing ) {
			$ok = $wpdb->insert(
				$table,
				array(
					'comment_id'                => $comment_id,
					'claim_token'               => $token,
					'generation'                => 1,
					'auth_class'                => $auth_class,
					'target_content_hmac'       => $target_hmac,
					'target_rating'             => $target_rating,
					'prior_content_hmac'        => $prior_hmac,
					'prior_rating'              => $prior_rating,
					'content_changed'           => $content_changed ? 1 : 0,
					'rating_changed'            => $rating_changed ? 1 : 0,
					'prior_status'              => $prior_status,
					'phase'                     => 'claimed',
					'claimed_at'                => $now,
					'content_written_at'        => null,
					'finalise_op_id'            => null,
					'finalise_lease_token'      => null,
					'finalise_lease_expires_at' => null,
					'finalized_at'              => null,
					'finalise_outcome'          => null,
					'finalise_reassess'         => 'none',
					'claim_expires_at'          => $expires,
					'updated_at'                => $now,
				),
				array( '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( ! $ok ) {
				return null;
			}
			return array(
				'claim_token' => $token,
				'generation'  => 1,
			);
		}

		$cutoff = gmdate( 'Y-m-d H:i:s' );
		$n      = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET claim_token = %s, generation = generation + 1, auth_class = %s,
				target_content_hmac = %s, target_rating = %d, prior_content_hmac = %s, prior_rating = %d,
				content_changed = %d, rating_changed = %d, prior_status = %s, phase = %s,
				claimed_at = %s, content_written_at = NULL, finalise_op_id = NULL,
				finalise_lease_token = NULL, finalise_lease_expires_at = NULL,
				finalized_at = NULL, finalise_outcome = NULL, finalise_reassess = %s,
				claim_expires_at = %s, updated_at = %s
				WHERE comment_id = %d
				AND (
					finalized_at IS NOT NULL
					OR (
						phase = %s AND content_written_at IS NULL AND finalized_at IS NULL AND claim_expires_at < %s
					)
				)
				AND NOT (phase IN (%s, %s) AND finalized_at IS NULL)",
				$token,
				$auth_class,
				$target_hmac,
				$target_rating,
				$prior_hmac,
				$prior_rating,
				$content_changed ? 1 : 0,
				$rating_changed ? 1 : 0,
				$prior_status,
				'claimed',
				$now,
				'none',
				$expires,
				$now,
				$comment_id,
				'claimed',
				$cutoff,
				'content_written',
				'writing'
			)
		);

		if ( 1 !== (int) $n ) {
			return null;
		}

		$row = self::get( $comment_id );
		return array(
			'claim_token' => $token,
			'generation'  => (int) ( $row['generation'] ?? 0 ),
		);
	}

	public static function mark_writing( int $comment_id, string $claim_token, int $generation ): bool {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$n     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET phase = %s, updated_at = %s
				WHERE comment_id = %d AND claim_token = %s AND generation = %d AND phase = %s AND finalized_at IS NULL",
				'writing',
				$now,
				$comment_id,
				$claim_token,
				$generation,
				'claimed'
			)
		);
		return 1 === (int) $n;
	}

	public static function mark_content_written( int $comment_id, string $claim_token, int $generation, string $finalise_op_id ): bool {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$n     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET phase = %s, content_written_at = %s, finalise_op_id = %s, updated_at = %s
				WHERE comment_id = %d AND claim_token = %s AND generation = %d AND phase = %s AND finalized_at IS NULL
				AND (finalise_op_id IS NULL OR finalise_op_id = %s)",
				'content_written',
				$now,
				$finalise_op_id,
				$now,
				$comment_id,
				$claim_token,
				$generation,
				'writing',
				$finalise_op_id
			)
		);
		return 1 === (int) $n;
	}

	/**
	 * @return array<string, mixed>|null Locked claim row.
	 */
	public static function acquire_finalise_lease( int $comment_id, string $claim_token, int $generation ): ?array {
		global $wpdb;
		$table = self::table();
		$token = wp_generate_uuid4();
		$now   = current_time( 'mysql', true );
		$until = gmdate( 'Y-m-d H:i:s', time() + self::LEASE_TTL_SECONDS );
		$n     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET finalise_lease_token = %s, finalise_lease_expires_at = %s, updated_at = %s
				WHERE comment_id = %d AND claim_token = %s AND generation = %d
				AND phase = %s AND finalized_at IS NULL
				AND (finalise_lease_token IS NULL OR finalise_lease_expires_at IS NULL OR finalise_lease_expires_at < %s)",
				$token,
				$until,
				$now,
				$comment_id,
				$claim_token,
				$generation,
				'content_written',
				$now
			)
		);
		if ( 1 !== (int) $n ) {
			return null;
		}
		$row = self::get( $comment_id );
		return is_array( $row ) ? $row : null;
	}

	public static function mark_finalized(
		int $comment_id,
		string $claim_token,
		int $generation,
		string $finalise_op_id,
		string $lease_token,
		string $outcome,
		string $reassess
	): bool {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$n     = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET finalized_at = %s, finalise_outcome = %s, finalise_reassess = %s,
				finalise_lease_token = NULL, finalise_lease_expires_at = NULL, updated_at = %s
				WHERE comment_id = %d AND claim_token = %s AND generation = %d AND finalise_op_id = %s
				AND finalise_lease_token = %s AND finalized_at IS NULL",
				$now,
				$outcome,
				$reassess,
				$now,
				$comment_id,
				$claim_token,
				$generation,
				$finalise_op_id,
				$lease_token
			)
		);
		return 1 === (int) $n;
	}

	public static function set_reassess( int $comment_id, string $claim_token, int $generation, string $lease_token, string $reassess ): void {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET finalise_reassess = %s, updated_at = %s
				WHERE comment_id = %d AND claim_token = %s AND generation = %d AND finalise_lease_token = %s AND finalized_at IS NULL",
				$reassess,
				$now,
				$comment_id,
				$claim_token,
				$generation,
				$lease_token
			)
		);
	}

	public static function force_abandon( int $comment_id, string $claim_token, int $generation ): void {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET finalized_at = %s, finalise_outcome = %s, finalise_lease_token = NULL,
				finalise_lease_expires_at = NULL, finalise_reassess = %s, updated_at = %s
				WHERE comment_id = %d AND claim_token = %s AND generation = %d AND finalized_at IS NULL",
				$now,
				'abandoned',
				'ineligible',
				$now,
				$comment_id,
				$claim_token,
				$generation
			)
		);
	}

	public static function abandon_unwritten( int $comment_id, string $claim_token, int $generation ): void {
		global $wpdb;
		$table = self::table();
		$now   = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET finalized_at = %s, finalise_outcome = %s, updated_at = %s
				WHERE comment_id = %d AND claim_token = %s AND generation = %d AND phase = %s AND finalized_at IS NULL",
				$now,
				'abandoned',
				$now,
				$comment_id,
				$claim_token,
				$generation,
				'claimed'
			)
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function find_recovery_owned(): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results(
			"SELECT * FROM {$table} WHERE phase IN ('writing', 'content_written') AND finalized_at IS NULL",
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	public static function find_expired_unwritten(): array {
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s' );
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE phase = %s AND content_written_at IS NULL AND finalized_at IS NULL AND claim_expires_at < %s",
				'claimed',
				$cutoff
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public static function hmac_body( string $canonical_content ): string {
		return hash_hmac( 'sha256', $canonical_content, wp_salt( 'auth' ) );
	}

	public static function canonical_body( string $raw_content ): string {
		$filtered = wp_filter_comment(
			array(
				'comment_content'      => $raw_content,
				'comment_author'       => '',
				'comment_author_email' => '',
				'comment_author_url'   => '',
				'comment_author_IP'    => '',
				'comment_agent'        => '',
				'user_id'              => 0,
				'comment_type'         => 'review',
			)
		);
		return (string) ( $filtered['comment_content'] ?? $raw_content );
	}
}
