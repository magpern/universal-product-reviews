<?php
/**
 * Seven-day edit window from comment_date_gmt (UTC).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

defined( 'ABSPATH' ) || exit;

final class CustomerEditClock {

	public const WINDOW_SECONDS = 7 * 86400;

	/**
	 * Inclusive expiry unix (UTC). Null if GMT missing/invalid.
	 */
	public static function expiry_unix( \WP_Comment $comment ): ?int {
		$gmt = (string) $comment->comment_date_gmt;
		if ( '' === $gmt || '0000-00-00 00:00:00' === $gmt ) {
			return null;
		}
		$ts = strtotime( $gmt . ' UTC' );
		if ( false === $ts ) {
			return null;
		}
		return $ts + self::WINDOW_SECONDS;
	}

	public static function is_in_window( \WP_Comment $comment, ?int $now_unix = null ): bool {
		$expiry = self::expiry_unix( $comment );
		if ( null === $expiry ) {
			return false;
		}
		$now = $now_unix ?? (int) current_time( 'timestamp', true );
		return $now <= $expiry;
	}
}
