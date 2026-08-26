<?php
/**
 * Invite token and form-session lifecycle.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tokens;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Invitations\ProductReviewability;

defined( 'ABSPATH' ) || exit;

final class TokenService {

	/**
	 * @return array{id:int,raw:string}|null
	 */
	public static function issue_invite( int $order_item_id, int $product_id ): ?array {
		$prior = TokenRepository::find_active_invite( $order_item_id );
		if ( $prior ) {
			TokenRepository::revoke( (int) $prior['id'] );
			TokenRepository::revoke_children( (int) $prior['id'] );
		}

		$ttl     = Options::token_ttl_days() * DAY_IN_SECONDS;
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl );
		$created = TokenRepository::create( $order_item_id, 'invite', $product_id, $expires );
		if ( $created ) {
			AuditLogger::log( 'token.issued', 'system', null, $order_item_id, array( 'token_id' => $created['id'] ) );
		}
		return $created;
	}

	/**
	 * Exchange invite raw token for form session cookie + redirect target.
	 *
	 * @return array{form_url:string}|null
	 */
	public static function exchange_invite( string $raw_invite ): ?array {
		$invite = TokenRepository::find_active_by_raw( $raw_invite, 'invite' );
		if ( null === $invite ) {
			return null;
		}

		$product_id = (int) $invite['product_id'];
		if ( ! ProductReviewability::is_reviewable( $product_id ) ) {
			TokenRepository::revoke( (int) $invite['id'] );
			TokenRepository::revoke_children( (int) $invite['id'] );
			return null;
		}

		$ttl     = Options::form_session_ttl_minutes() * MINUTE_IN_SECONDS;
		$expires = gmdate( 'Y-m-d H:i:s', time() + $ttl );
		$session = TokenRepository::create(
			(int) $invite['order_item_id'],
			'form_session',
			$product_id,
			$expires,
			(int) $invite['id']
		);
		if ( null === $session ) {
			return null;
		}

		SessionCookie::set( $session['raw'], $ttl );
		AuditLogger::log(
			'token.session_created',
			'customer',
			null,
			(int) $invite['order_item_id'],
			array( 'parent_token_id' => (int) $invite['id'] )
		);

		return array(
			'form_url' => DefaultReviewLinkBuilder::resolve()->form_url(),
		);
	}

	/**
	 * Redeem invite after successful comment; revoke siblings/sessions.
	 */
	public static function redeem_after_submit( int $invite_token_id, int $order_item_id ): bool {
		if ( ! TokenRepository::redeem( $invite_token_id ) ) {
			return false;
		}
		TokenRepository::revoke_children( $invite_token_id );
		SessionCookie::clear();
		AuditLogger::log( 'token.redeemed', 'customer', null, $order_item_id, array( 'token_id' => $invite_token_id ) );
		return true;
	}
}
