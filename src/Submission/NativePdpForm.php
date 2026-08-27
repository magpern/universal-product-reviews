<?php
/**
 * Display-only helper for native WooCommerce PDP review forms.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Submission;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a theme/storefront may render the native PDP review form.
 *
 * Display-only: not a submission authorization API. Guests always get false
 * (including M2 form sessions — guests submit only via `/upr-review/form/`).
 */
final class NativePdpForm {

	/**
	 * @param int $product_id WooCommerce product post ID.
	 */
	public static function should_render( int $product_id ): bool {
		if ( $product_id <= 0 ) {
			return false;
		}

		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}

		$availability = ReviewAvailability::resolve( $product_id, $user_id );
		if ( ! ReviewAvailability::is_allowed( $availability ) ) {
			return false;
		}

		$authorization = is_array( $availability['context'] ?? null )
			? ( $availability['context']['authorization'] ?? null )
			: null;
		if ( 'form_session' === $authorization ) {
			return false;
		}

		return true;
	}
}
