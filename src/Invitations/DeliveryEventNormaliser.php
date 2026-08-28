<?php
/**
 * Fail-safe normalisation for public delivery action payloads (M6 C1/C2).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

defined( 'ABSPATH' ) || exit;

/**
 * Normalises inbound WP action arguments before any typed service or persistence.
 */
final class DeliveryEventNormaliser {

	public const REASON_UNSPECIFIED = 'unspecified';

	/** Unix floor: 2000-01-01 00:00:00 UTC. */
	public const DELIVERED_AT_MIN_UNIX = 946684800;

	/** Allow at most one day ahead of wall clock. */
	public const DELIVERED_AT_FUTURE_SLACK = 86400;

	/**
	 * Coerce order ID; return null when invalid / non-positive.
	 *
	 * @param mixed $order_id Inbound action argument.
	 */
	public static function normalize_order_id( $order_id ): ?int {
		if ( is_int( $order_id ) ) {
			$id = $order_id;
		} elseif ( is_string( $order_id ) && 1 === preg_match( '/^-?[0-9]+$/', $order_id ) ) {
			$id = (int) $order_id;
		} else {
			return null;
		}

		return $id > 0 ? $id : null;
	}

	/**
	 * Non-array context becomes empty array.
	 *
	 * @param mixed $context Inbound action argument.
	 * @return array<string, mixed>
	 */
	public static function normalize_context( $context ): array {
		return is_array( $context ) ? $context : array();
	}

	/**
	 * Resolve delivered_at from context; invalid/implausible → time().
	 *
	 * @param array<string, mixed> $context Normalised context.
	 * @param int|null             $now     Override wall clock (tests).
	 */
	public static function normalize_delivered_at( array $context, ?int $now = null ): int {
		$now = null === $now ? time() : $now;

		if ( ! array_key_exists( 'delivered_at', $context ) ) {
			return $now;
		}

		$raw = $context['delivered_at'];
		if ( is_int( $raw ) ) {
			$t = $raw;
		} elseif ( is_string( $raw ) && 1 === preg_match( '/^-?[0-9]+$/', $raw ) ) {
			$t = (int) $raw;
		} else {
			return $now;
		}

		if ( $t <= 0 ) {
			return $now;
		}
		if ( $t < self::DELIVERED_AT_MIN_UNIX ) {
			return $now;
		}
		if ( $t > $now + self::DELIVERED_AT_FUTURE_SLACK ) {
			return $now;
		}

		return $t;
	}

	/**
	 * Normalise invalidate reason code before any suppress/audit persist.
	 *
	 * @param mixed $reason Inbound action argument.
	 */
	public static function normalize_reason( $reason ): string {
		if ( ! is_string( $reason ) ) {
			return self::REASON_UNSPECIFIED;
		}

		$code = trim( $reason );
		if ( '' === $code ) {
			return self::REASON_UNSPECIFIED;
		}
		if ( 1 !== preg_match( '/^[a-z0-9_]{1,64}$/', $code ) ) {
			return self::REASON_UNSPECIFIED;
		}

		return $code;
	}

	/**
	 * Composed suppression/audit code for invalidate.
	 *
	 * @param mixed $reason Inbound action argument.
	 */
	public static function compose_invalidation_code( $reason ): string {
		return 'delivery_invalidated:' . self::normalize_reason( $reason );
	}
}
