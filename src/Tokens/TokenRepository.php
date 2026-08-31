<?php
/**
 * Token persistence.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tokens;

defined( 'ABSPATH' ) || exit;

final class TokenRepository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'upr_tokens';
	}

	/**
	 * @param array<string, mixed> $meta
	 * @return array{id:int,raw:string}|null
	 */
	public static function create(
		int $order_item_id,
		string $purpose,
		int $product_id,
		string $expires_at_gmt,
		?int $parent_token_id = null,
		array $meta = array()
	): ?array {
		global $wpdb;

		$raw  = TokenHasher::generate_raw();
		$hash = TokenHasher::hash( $raw );
		$ok   = $wpdb->insert(
			self::table(),
			array(
				'order_item_id'   => $order_item_id,
				'purpose'         => $purpose,
				'token_hash'      => $hash,
				'parent_token_id' => $parent_token_id,
				'product_id'      => $product_id,
				'expires_at'      => $expires_at_gmt,
				'created_at'      => current_time( 'mysql', true ),
				'meta_json'       => $meta ? wp_json_encode( $meta ) : null,
			),
			array( '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			return null;
		}

		return array(
			'id'  => (int) $wpdb->insert_id,
			'raw' => $raw,
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find_active_by_raw( string $raw, string $purpose ): ?array {
		global $wpdb;

		$hash = TokenHasher::hash( $raw );
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s AND purpose = %s LIMIT 1',
				$hash,
				$purpose
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		if ( ! empty( $row['revoked_at'] ) || ! empty( $row['redeemed_at'] ) ) {
			return null;
		}

		if ( strtotime( (string) $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}

		return $row;
	}

	/**
	 * Lookup by HMAC without redeemed/revoked/expiry predicates (M14 dispatcher).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function find_by_raw( string $raw, string $purpose ): ?array {
		global $wpdb;

		$hash = TokenHasher::hash( $raw );
		$row  = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s AND purpose = %s LIMIT 1',
				$hash,
				$purpose
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function find_by_id( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d LIMIT 1', $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	public static function redeem( int $token_id ): bool {
		global $wpdb;
		$n = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET redeemed_at = %s WHERE id = %d AND redeemed_at IS NULL AND revoked_at IS NULL',
				current_time( 'mysql', true ),
				$token_id
			)
		);
		return $n === 1;
	}

	public static function revoke( int $token_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET revoked_at = %s WHERE id = %d AND revoked_at IS NULL',
				current_time( 'mysql', true ),
				$token_id
			)
		);
	}

	public static function revoke_children( int $parent_token_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET revoked_at = %s WHERE parent_token_id = %d AND revoked_at IS NULL',
				current_time( 'mysql', true ),
				$parent_token_id
			)
		);
	}

	/**
	 * Revoke unrevoked edit_session children only (E30). Does not touch the parent invite.
	 */
	public static function revoke_edit_session_children( int $parent_token_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . " SET revoked_at = %s WHERE parent_token_id = %d AND purpose = %s AND revoked_at IS NULL",
				current_time( 'mysql', true ),
				$parent_token_id,
				'edit_session'
			)
		);
	}

	public static function count_edit_sessions_in_rolling_hour( int $parent_token_id ): int {
		global $wpdb;
		$n = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . self::table() . " WHERE parent_token_id = %d AND purpose = %s AND created_at >= UTC_TIMESTAMP() - INTERVAL 1 HOUR",
				$parent_token_id,
				'edit_session'
			)
		);
		return (int) $n;
	}

	public static function revoke_for_item( int $order_item_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET revoked_at = %s WHERE order_item_id = %d AND revoked_at IS NULL AND redeemed_at IS NULL',
				current_time( 'mysql', true ),
				$order_item_id
			)
		);
	}

	/**
	 * Revoke all outstanding invite tokens and form sessions (emergency pause).
	 *
	 * Redeemed invite tokens are left unchanged. Returns rows touched.
	 */
	public static function revoke_all_outstanding(): int {
		global $wpdb;
		$n = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . self::table() . ' SET revoked_at = %s WHERE revoked_at IS NULL AND redeemed_at IS NULL',
				current_time( 'mysql', true )
			)
		);
		return is_int( $n ) ? $n : 0;
	}

	/**
	 * @return array<string, mixed>|null Active invite for item.
	 */
	public static function find_active_invite( int $order_item_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . self::table() . " WHERE order_item_id = %d AND purpose = 'invite' AND revoked_at IS NULL AND redeemed_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY id DESC LIMIT 1",
				$order_item_id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}
}
