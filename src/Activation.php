<?php
/**
 * Plugin activation.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews;

use UniversalProductReviews\Database\Migrator;

defined( 'ABSPATH' ) || exit;

final class Activation {

	public static function activate(): void {
		Migrator::upgrade_now();
		update_option( 'upr_rewrite_flushed', '0', true );
		// Do not schedule invitation storms or send email on activation.
	}
}
