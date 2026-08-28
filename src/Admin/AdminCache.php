<?php
/**
 * Invalidate short-lived admin aggregate caches.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

defined( 'ABSPATH' ) || exit;

final class AdminCache {

	public const TRANSIENT_KEY = 'upr_admin_diag_cache_v1';

	public static function get(): mixed {
		return get_transient( self::TRANSIENT_KEY );
	}

	/**
	 * @param mixed $value Cached payload.
	 */
	public static function set( $value ): void {
		set_transient( self::TRANSIENT_KEY, $value, 60 );
	}

	public static function invalidate(): void {
		delete_transient( self::TRANSIENT_KEY );
	}
}
