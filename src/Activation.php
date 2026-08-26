<?php
/**
 * Plugin activation.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews;

use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Http\RewriteRules;

defined( 'ABSPATH' ) || exit;

final class Activation {

	public static function activate(): void {
		Migrator::upgrade_now();
		// Register rules then flush once during activation (not on frontend).
		RewriteRules::flush_controlled();
	}
}
