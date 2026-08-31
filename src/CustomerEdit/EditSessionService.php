<?php
/**
 * Serialized edit_session mint per parent invite (E30).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Tokens\DefaultReviewLinkBuilder;
use UniversalProductReviews\Tokens\SessionCookie;
use UniversalProductReviews\Tokens\TokenRepository;

defined( 'ABSPATH' ) || exit;

final class EditSessionService {

	public const MAX_MINTS_PER_HOUR = 10;

	/** @var callable|null Test seam after parent FOR UPDATE. */
	public static $after_parent_lock_for_tests = null;

	/**
	 * @param array<string, mixed> $invite_row Locked-eligible invite token row.
	 * @return array{edit_url:string}|null
	 */
	public static function issue_serialized( array $invite_row ): ?array {
		global $wpdb;

		$parent_id = (int) ( $invite_row['id'] ?? 0 );
		if ( $parent_id <= 0 ) {
			return null;
		}

		$table = TokenRepository::table();
		$wpdb->query( 'START TRANSACTION' );
		try {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE id = %d FOR UPDATE",
					$parent_id
				),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				$wpdb->query( 'ROLLBACK' );
				return null;
			}

			if ( is_callable( self::$after_parent_lock_for_tests ) ) {
				( self::$after_parent_lock_for_tests )( $row );
			}

			$matched = CompletedInviteLookup::match_row( $row );
			if ( null === $matched ) {
				$wpdb->query( 'ROLLBACK' );
				return null;
			}

			$count = TokenRepository::count_edit_sessions_in_rolling_hour( $parent_id );
			if ( $count >= self::MAX_MINTS_PER_HOUR ) {
				$wpdb->query( 'COMMIT' );
				return null;
			}

			TokenRepository::revoke_edit_session_children( $parent_id );

			$ttl     = Options::form_session_ttl_minutes() * MINUTE_IN_SECONDS;
			$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl );
			$created = TokenRepository::create(
				(int) $row['order_item_id'],
				'edit_session',
				(int) $row['product_id'],
				$expires,
				$parent_id
			);
			if ( null === $created ) {
				$wpdb->query( 'ROLLBACK' );
				return null;
			}

			$wpdb->query( 'COMMIT' );
			SessionCookie::set( $created['raw'], $ttl );

			return array(
				'edit_url' => ( new DefaultReviewLinkBuilder() )->edit_url(),
			);
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			return null;
		}
	}
}
