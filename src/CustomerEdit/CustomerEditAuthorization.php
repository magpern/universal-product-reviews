<?php
/**
 * Request-local customer-edit write arm (non-forgeable).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

defined( 'ABSPATH' ) || exit;

final class CustomerEditAuthorization {

	/** @var array{comment_id:int,claim_token:string,generation:int,secret:string}|null */
	private static ?array $armed = null;

	public static function arm( int $comment_id, string $claim_token, int $generation ): void {
		self::$armed = array(
			'comment_id'  => $comment_id,
			'claim_token' => $claim_token,
			'generation'  => $generation,
			'secret'      => bin2hex( random_bytes( 16 ) ),
		);
	}

	public static function clear(): void {
		self::$armed = null;
	}

	public static function is_armed(): bool {
		return null !== self::$armed;
	}

	public static function allows_comment( int $comment_id ): bool {
		if ( null === self::$armed ) {
			return false;
		}
		if ( (int) self::$armed['comment_id'] !== $comment_id ) {
			return false;
		}
		return '' !== (string) self::$armed['secret'] && '' !== (string) self::$armed['claim_token'];
	}

	/**
	 * @return array{comment_id:int,claim_token:string,generation:int}|null
	 */
	public static function current(): ?array {
		if ( null === self::$armed ) {
			return null;
		}
		return array(
			'comment_id'  => (int) self::$armed['comment_id'],
			'claim_token' => (string) self::$armed['claim_token'],
			'generation'  => (int) self::$armed['generation'],
		);
	}
}
