<?php
/**
 * Single HMAC SELECT then submit-or-edit dispatch (E29).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

use UniversalProductReviews\Tokens\TokenRepository;
use UniversalProductReviews\Tokens\TokenService;

defined( 'ABSPATH' ) || exit;

final class InviteTokenDispatcher {

	/**
	 * @return array{kind:'form',url:string}|array{kind:'edit',url:string}|array{kind:'deny'}
	 */
	public static function dispatch( string $raw ): array {
		$row = TokenRepository::find_by_raw( $raw, 'invite' );
		if ( ! is_array( $row ) ) {
			return array( 'kind' => 'deny' );
		}

		$redeemed = ! empty( $row['redeemed_at'] );
		$revoked  = ! empty( $row['revoked_at'] );
		$expired  = strtotime( (string) $row['expires_at'] . ' UTC' ) < time();

		if ( ! $redeemed && ! $revoked && ! $expired ) {
			$result = TokenService::issue_form_session_for_invite_row( $row );
			if ( null === $result ) {
				return array( 'kind' => 'deny' );
			}
			return array(
				'kind' => 'form',
				'url'  => (string) $result['form_url'],
			);
		}

		if ( $redeemed && ! $revoked ) {
			$issued = EditSessionService::issue_serialized( $row );
			if ( null === $issued ) {
				return array( 'kind' => 'deny' );
			}
			return array(
				'kind' => 'edit',
				'url'  => (string) $issued['edit_url'],
			);
		}

		return array( 'kind' => 'deny' );
	}
}
