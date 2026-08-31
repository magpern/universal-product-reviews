<?php
/**
 * Guest edit_session cookie authentication.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

use UniversalProductReviews\Tokens\SessionCookie;
use UniversalProductReviews\Tokens\TokenRepository;

defined( 'ABSPATH' ) || exit;

final class EditSessionAuthenticator {

	/**
	 * @return array<string, mixed>|null
	 */
	public static function current_session(): ?array {
		$raw = SessionCookie::get();
		if ( null === $raw ) {
			return null;
		}
		$row = TokenRepository::find_active_by_raw( $raw, 'edit_session' );
		if ( null === $row ) {
			return null;
		}
		return $row;
	}
}
