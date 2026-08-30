<?php
/**
 * Nesting-safe marker for M12 AI auto-spam CAS + happy-path hooks.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps only the CAS mutator happy-path hook delivery. Distinct from SystemStatusOrigin
 * (invitation abandon → review.system_spam).
 */
final class AiActionOrigin {

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
	 * Test seam: reset depth between unit tests.
	 */
	public static function reset_for_tests(): void {
		self::$depth = 0;
	}
}
