<?php
/**
 * Request-local guest submit authorization (non-forgeable).
 *
 * Armed only by ReviewSubmitHandler after full validation + claim.
 * Not readable/writable via cookies, query vars, POST, or public filters.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Submission;

defined( 'ABSPATH' ) || exit;

final class GuestSubmitAuthorization {

	/** @var array{product_id:int,order_item_id:int,session_id:int,claim_token:string,secret:string}|null */
	private static ?array $armed = null;

	/**
	 * Establish authorization for this request only.
	 */
	public static function arm( int $product_id, int $order_item_id, int $session_id, string $claim_token ): void {
		self::$armed = array(
			'product_id'    => $product_id,
			'order_item_id' => $order_item_id,
			'session_id'    => $session_id,
			'claim_token'   => $claim_token,
			'secret'        => bin2hex( random_bytes( 16 ) ),
		);
	}

	public static function clear(): void {
		self::$armed = null;
	}

	public static function is_armed(): bool {
		return null !== self::$armed;
	}

	/**
	 * True only when an armed context matches the canonical product (and optional item).
	 */
	public static function allows_product( int $product_id, ?int $order_item_id = null ): bool {
		if ( null === self::$armed ) {
			return false;
		}
		if ( (int) self::$armed['product_id'] !== $product_id ) {
			return false;
		}
		if ( null !== $order_item_id && (int) self::$armed['order_item_id'] !== $order_item_id ) {
			return false;
		}
		return '' !== (string) self::$armed['secret'] && '' !== (string) self::$armed['claim_token'];
	}

	/**
	 * @return array{product_id:int,order_item_id:int,session_id:int,claim_token:string}|null
	 */
	public static function current(): ?array {
		if ( null === self::$armed ) {
			return null;
		}
		return array(
			'product_id'    => (int) self::$armed['product_id'],
			'order_item_id' => (int) self::$armed['order_item_id'],
			'session_id'    => (int) self::$armed['session_id'],
			'claim_token'   => (string) self::$armed['claim_token'],
		);
	}
}
