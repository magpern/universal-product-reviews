<?php
/**
 * C20 provisional read-only display helper. Cannot grant write auth.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

defined( 'ABSPATH' ) || exit;

final class CustomerEditAvailability {

	/**
	 * @return array{can_edit:bool,reason_code:string}
	 */
	public static function resolve( int $comment_id, int $user_id ): array {
		if ( $comment_id <= 0 || $user_id <= 0 ) {
			return array(
				'can_edit'    => false,
				'reason_code' => 'not_eligible',
			);
		}
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return array(
				'can_edit'    => false,
				'reason_code' => 'not_eligible',
			);
		}
		if ( ! CustomerEditEligibility::logged_in_may_edit( $comment, $user_id ) ) {
			return array(
				'can_edit'    => false,
				'reason_code' => 'not_eligible',
			);
		}
		return array(
			'can_edit'    => true,
			'reason_code' => 'ok',
		);
	}
}
