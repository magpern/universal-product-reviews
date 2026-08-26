<?php
/**
 * Read-only product review submission availability contract.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Submission;

use UniversalProductReviews\Invitations\ProductReviewability;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;

defined( 'ABSPATH' ) || exit;

final class ReviewAvailability {

	public static function register(): void {
		add_filter( 'upr_product_review_availability', array( self::class, 'default_availability' ), 10, 3 );
	}

	/**
	 * @param array{can_submit: bool, reason_code: string|null, context: array<string, mixed>} $availability
	 * @return array{can_submit: bool, reason_code: string|null, context: array<string, mixed>}
	 */
	public static function default_availability( array $availability, int $product_id, int $user_id ): array {
		unset( $availability );

		if ( 'yes' !== get_option( 'woocommerce_enable_reviews', 'yes' ) ) {
			return self::result( false, 'reviews_disabled', $product_id, $user_id );
		}

		if ( ! ProductReviewability::is_reviewable( $product_id ) ) {
			return self::result( false, 'product_not_reviewable', $product_id, $user_id );
		}

		if ( $user_id <= 0 ) {
			if ( FormSessionAuthenticator::authorize_product( $product_id ) ) {
				$result = self::result( true, null, $product_id, $user_id );
				$result['context']['authorization'] = 'form_session';
				return $result;
			}
			return self::result( false, 'guest_requires_invitation', $product_id, $user_id );
		}

		if ( self::verification_required() && ! self::user_has_verified_purchase( $product_id, $user_id ) ) {
			return self::result( false, 'not_verified_purchaser', $product_id, $user_id );
		}

		return self::result( true, null, $product_id, $user_id );
	}

	/**
	 * @return array{can_submit: bool, reason_code: string|null, context: array<string, mixed>}
	 */
	private static function result( bool $can_submit, ?string $reason_code, int $product_id, int $user_id ): array {
		return array(
			'can_submit'  => $can_submit,
			'reason_code' => $reason_code,
			'context'     => array(
				'product_id' => $product_id,
				'user_id'    => $user_id,
			),
		);
	}

	private static function verification_required(): bool {
		return 'yes' === get_option( 'woocommerce_review_rating_verification_required', 'no' );
	}

	private static function user_has_verified_purchase( int $product_id, int $user_id ): bool {
		if ( ! function_exists( 'wc_customer_bought_product' ) ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}

		return wc_customer_bought_product( $user->user_email, $user_id, $product_id );
	}
}
