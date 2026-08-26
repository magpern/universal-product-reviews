<?php
/**
 * HMAC token hashing helpers.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tokens;

defined( 'ABSPATH' ) || exit;

final class TokenHasher {

	public static function generate_raw( int $bytes = 32 ): string {
		$raw = random_bytes( max( 32, $bytes ) );
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	public static function hash( string $raw ): string {
		return hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) );
	}

	public static function equals( string $raw, string $hash ): bool {
		return hash_equals( $hash, self::hash( $raw ) );
	}
}
