<?php
/**
 * Uncached integration-readiness checks I1–I5 (M6). Wiring signals only.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin\Diagnostics;

use UniversalProductReviews\Submission\ReviewAvailability;

defined( 'ABSPATH' ) || exit;

/**
 * Advisory readiness — never cached with D1–D11. Never proves operational health.
 */
final class IntegrationReadiness {

	/**
	 * Run I1–I5 fresh every call (no transient).
	 *
	 * @return list<array{id:string,status:string,severity:string,message:string,evidence_code:string}>
	 */
	public static function run(): array {
		return array(
			self::check_i1(),
			self::check_i2(),
			self::check_i3(),
			self::check_i4(),
			self::check_i5(),
		);
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_i1(): array {
		$present = self::filter_registered( 'upr_is_order_delivered' );
		if ( null === $present ) {
			return self::result(
				'I1',
				'information',
				'Information',
				'Delivery lookup callback inspection unavailable.',
				'lookup_unknown'
			);
		}
		if ( ! $present ) {
			return self::result(
				'I1',
				'information',
				'Information',
				'Delivery lookup callback not detected.',
				'lookup_not_detected'
			);
		}
		return self::result(
			'I1',
			'pass',
			'Pass',
			'Delivery lookup callback is registered (wiring only; not operational proof).',
			'lookup_detected'
		);
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_i2(): array {
		$present = self::filter_registered( 'upr_review_invitation_action' );
		if ( null === $present ) {
			return self::result(
				'I2',
				'information',
				'Information',
				'Support action callback inspection unavailable.',
				'support_unknown'
			);
		}
		if ( ! $present ) {
			return self::result(
				'I2',
				'information',
				'Information',
				'Support invitation-action callback not detected (optional).',
				'support_not_detected'
			);
		}
		return self::result(
			'I2',
			'pass',
			'Pass',
			'Support invitation-action callback is registered (wiring only).',
			'support_detected'
		);
	}

	/**
	 * Mail transport mode: default | custom | unknown — registration only.
	 *
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_i3(): array {
		$mode = self::mail_transport_mode();
		if ( 'unknown' === $mode ) {
			return self::result(
				'I3',
				'information',
				'Information',
				'Mail transport wiring could not be inspected safely.',
				'mail_unknown'
			);
		}
		if ( 'custom' === $mode ) {
			return self::result(
				'I3',
				'pass',
				'Pass',
				'Custom mail transport filter is registered (wiring only; not delivery proof).',
				'mail_custom'
			);
		}
		return self::result(
			'I3',
			'pass',
			'Pass',
			'Default mail transport path (no custom upr_mail_transport callback).',
			'mail_default'
		);
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_i4(): array {
		$present = self::filter_registered( 'upr_invitation_send_authorisation' );
		if ( null === $present ) {
			return self::result(
				'I4',
				'information',
				'Information',
				'Send-authorisation callback inspection unavailable.',
				'auth_unknown'
			);
		}
		if ( ! $present ) {
			return self::result(
				'I4',
				'information',
				'Information',
				'Send-authorisation callback not detected (optional).',
				'auth_not_detected'
			);
		}
		return self::result(
			'I4',
			'pass',
			'Pass',
			'Send-authorisation callback is registered (wiring only).',
			'auth_detected'
		);
	}

	/**
	 * Core availability service present — not host storefront wiring.
	 *
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_i5(): array {
		$present = class_exists( ReviewAvailability::class )
			&& method_exists( ReviewAvailability::class, 'resolve' );
		if ( ! $present ) {
			return self::result(
				'I5',
				'information',
				'Information',
				'Core availability service missing.',
				'availability_missing'
			);
		}
		return self::result(
			'I5',
			'pass',
			'Pass',
			'Core availability service present.',
			'availability_present'
		);
	}

	/**
	 * @return 'default'|'custom'|'unknown'
	 */
	public static function mail_transport_mode(): string {
		$present = self::filter_registered( 'upr_mail_transport' );
		if ( null === $present ) {
			return 'unknown';
		}
		return $present ? 'custom' : 'default';
	}

	/**
	 * @return bool|null True/false when inspectable; null when unknown.
	 */
	private static function filter_registered( string $tag ): ?bool {
		try {
			if ( ! function_exists( 'has_filter' ) ) {
				return null;
			}
			return false !== has_filter( $tag );
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	private static function result( string $id, string $status, string $headline, string $message, string $evidence_code ): array {
		return array(
			'id'            => $id,
			'status'        => $status,
			'severity'      => $headline,
			'message'       => $message,
			'evidence_code' => $evidence_code,
		);
	}
}
