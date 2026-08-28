<?php
/**
 * Nesting-safe marker for UPR-originated comment status mutations.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

defined( 'ABSPATH' ) || exit;

/**
 * Every UPR call that mutates comment status via WordPress public APIs must
 * go through this class. CI forbids direct status APIs elsewhere in src/
 * unless an explicit freeze-amended allowlist entry exists.
 */
final class SystemStatusOrigin {

	private static int $depth = 0;

	/**
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public static function run( callable $fn ) {
		++self::$depth;
		try {
			return $fn();
		} finally {
			--self::$depth;
		}
	}

	public static function is_active(): bool {
		return self::$depth > 0;
	}

	/**
	 * Sole authorised src/ call site for wp_set_comment_status.
	 *
	 * @return bool|string|\WP_Error WordPress return value.
	 */
	public static function set_comment_status( int $comment_id, string $status ) {
		return self::run(
			static function () use ( $comment_id, $status ) {
				return wp_set_comment_status( $comment_id, $status );
			}
		);
	}

	/**
	 * Test seam: reset depth between unit tests.
	 */
	public static function reset_for_tests(): void {
		self::$depth = 0;
	}
}
