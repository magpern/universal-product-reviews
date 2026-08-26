<?php
/**
 * Form session authentication (cookie + server hash).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tokens;

use UniversalProductReviews\Invitations\ProductReviewability;

defined( 'ABSPATH' ) || exit;

final class FormSessionAuthenticator {

	/**
	 * @return array<string, mixed>|null Active session row.
	 */
	public static function current_session(): ?array {
		$raw = SessionCookie::get();
		if ( null === $raw ) {
			return null;
		}

		$row = TokenRepository::find_active_by_raw( $raw, 'form_session' );
		if ( null === $row ) {
			return null;
		}

		$product_id = (int) $row['product_id'];
		if ( ! ProductReviewability::is_reviewable( $product_id ) ) {
			self::invalidate_session_row( $row );
			return null;
		}

		return $row;
	}

	public static function authorize_product( int $product_id ): bool {
		$session = self::current_session();
		if ( null === $session ) {
			return false;
		}
		return (int) $session['product_id'] === $product_id;
	}

	/**
	 * @param array<string, mixed> $row
	 */
	public static function invalidate_session_row( array $row ): void {
		TokenRepository::revoke( (int) $row['id'] );
		$parent = (int) ( $row['parent_token_id'] ?? 0 );
		if ( $parent > 0 ) {
			TokenRepository::revoke_children( $parent );
		}
		SessionCookie::clear();
	}
}
