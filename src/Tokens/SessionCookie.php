<?php
/**
 * Session cookie for form authorization.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tokens;

defined( 'ABSPATH' ) || exit;

final class SessionCookie {

	public const HOST_NAME = '__Host-upr_review_session';
	public const DEV_NAME  = 'upr_review_session';

	public static function cookie_name(): string {
		return self::use_host_prefix() ? self::HOST_NAME : self::DEV_NAME;
	}

	/**
	 * Production and staging must never use the local-dev exception.
	 */
	public static function use_host_prefix(): bool {
		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( in_array( $env, array( 'production', 'staging' ), true ) ) {
			return true;
		}
		if ( is_ssl() ) {
			return true;
		}
		// Local-dev exception: non-HTTPS + local|development only.
		return ! in_array( $env, array( 'local', 'development' ), true );
	}

	public static function set( string $raw_secret, int $ttl_seconds ): void {
		$secure  = self::use_host_prefix();
		$name    = self::cookie_name();
		$expires = time() + max( 60, $ttl_seconds );
		$options = array(
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => $secure,
			'httponly' => true,
			'samesite' => 'Lax',
		);
		// Never set Domain — required for __Host-.
		if ( ! headers_sent() ) {
			setcookie( $name, $raw_secret, $options );
		}
		$_COOKIE[ $name ] = $raw_secret;
	}

	public static function get(): ?string {
		$name = self::cookie_name();
		if ( isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) && $_COOKIE[ $name ] !== '' ) {
			return (string) $_COOKIE[ $name ];
		}
		// Also check the other name during env transitions.
		$other = $name === self::HOST_NAME ? self::DEV_NAME : self::HOST_NAME;
		if ( isset( $_COOKIE[ $other ] ) && is_string( $_COOKIE[ $other ] ) && $_COOKIE[ $other ] !== '' ) {
			return (string) $_COOKIE[ $other ];
		}
		return null;
	}

	public static function clear(): void {
		foreach ( array( self::HOST_NAME, self::DEV_NAME ) as $name ) {
			if ( ! headers_sent() ) {
				setcookie(
					$name,
					'',
					array(
						'expires'  => time() - 3600,
						'path'     => '/',
						'secure'   => self::use_host_prefix(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
			unset( $_COOKIE[ $name ] );
		}
	}
}
