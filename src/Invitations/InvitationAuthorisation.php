<?php
/**
 * Invitation send / schedule authorisation (core + host filter).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

final class InvitationAuthorisation {

	public const FILTER = 'upr_invitation_send_authorisation';

	public const DECISION_ALLOW           = 'allow';
	public const DECISION_EMAIL_DISABLED  = 'email_disabled';
	public const DECISION_PAUSED          = 'paused';
	public const DECISION_NOT_AUTHORISED  = 'not_authorised';

	public const OP_SCHEDULE       = 'schedule';
	public const OP_INITIAL_SEND   = 'initial_send';
	public const OP_REMINDER_SEND  = 'reminder_send';

	/**
	 * @param array{order_id:int,order_item_id:int,product_id:int,operation:string} $context
	 * @return array{decision:string,reason_code?:string}
	 */
	public static function evaluate( array $context ): array {
		$context = self::normalise_context( $context );

		if ( Options::invitation_emergency_pause() ) {
			return array( 'decision' => self::DECISION_PAUSED );
		}

		if ( ! Options::invitation_emails_enabled() ) {
			return array( 'decision' => self::DECISION_EMAIL_DISABLED );
		}

		$provisional = array( 'decision' => self::DECISION_ALLOW );

		/**
		 * Host may further restrict an otherwise allowed invitation operation.
		 *
		 * @param array{decision:string,reason_code?:string} $provisional
		 * @param array{order_id:int,order_item_id:int,product_id:int,operation:string} $context
		 */
		$filtered = apply_filters( self::FILTER, $provisional, $context );
		if ( ! is_array( $filtered ) ) {
			return $provisional;
		}

		$decision = isset( $filtered['decision'] ) ? (string) $filtered['decision'] : self::DECISION_ALLOW;
		if ( self::DECISION_ALLOW === $decision ) {
			return array( 'decision' => self::DECISION_ALLOW );
		}

		// Host may only deny; unknown values coerce to not_authorised.
		$out = array( 'decision' => self::DECISION_NOT_AUTHORISED );
		if ( ! empty( $filtered['reason_code'] ) && is_string( $filtered['reason_code'] ) ) {
			$out['reason_code'] = substr( sanitize_key( $filtered['reason_code'] ), 0, 64 );
		} elseif ( self::DECISION_NOT_AUTHORISED === $decision ) {
			// keep empty reason
		} else {
			$out['reason_code'] = 'host_denied';
		}

		return $out;
	}

	/**
	 * @param array{order_id:int,order_item_id:int,product_id:int,operation:string} $context
	 */
	public static function is_allowed( array $context ): bool {
		return self::DECISION_ALLOW === self::evaluate( $context )['decision'];
	}

	/**
	 * Evaluate and optionally audit a denial on live schedule/send paths.
	 *
	 * @param array{order_id:int,order_item_id:int,product_id:int,operation:string} $context
	 * @return array{decision:string,reason_code?:string}
	 */
	public static function evaluate_and_audit( array $context, bool $audit_denials = true ): array {
		$decision = self::evaluate( $context );
		if ( $audit_denials && self::DECISION_ALLOW !== $decision['decision'] ) {
			self::maybe_audit_denied( $decision, $context );
		}
		return $decision;
	}

	/**
	 * @param array{decision:string,reason_code?:string} $decision
	 * @param array{order_id:int,order_item_id:int,product_id:int,operation:string} $context
	 */
	public static function maybe_audit_denied( array $decision, array $context ): void {
		$context  = self::normalise_context( $context );
		$epoch    = Options::invitation_controls_epoch();
		$item_id  = $context['order_item_id'];
		$op       = $context['operation'];
		$dec      = $decision['decision'];
		$dedupe   = sprintf( 'upr_auth_deny_%d_%s_%s_%d', $item_id, $op, $dec, $epoch );

		if ( get_transient( $dedupe ) ) {
			return;
		}
		set_transient( $dedupe, 1, DAY_IN_SECONDS );

		$payload = array(
			'decision'  => $dec,
			'operation' => $op,
		);
		if ( ! empty( $decision['reason_code'] ) ) {
			$payload['reason_code'] = $decision['reason_code'];
		}

		AuditLogger::log(
			'invite.authorisation_denied',
			'system',
			$context['order_id'] > 0 ? $context['order_id'] : null,
			$item_id > 0 ? $item_id : null,
			$payload
		);
	}

	/**
	 * @param array<string, mixed> $context
	 * @return array{order_id:int,order_item_id:int,product_id:int,operation:string}
	 */
	private static function normalise_context( array $context ): array {
		$operation = (string) ( $context['operation'] ?? '' );
		$allowed   = array( self::OP_SCHEDULE, self::OP_INITIAL_SEND, self::OP_REMINDER_SEND );
		if ( ! in_array( $operation, $allowed, true ) ) {
			$operation = self::OP_SCHEDULE;
		}

		return array(
			'order_id'      => (int) ( $context['order_id'] ?? 0 ),
			'order_item_id' => (int) ( $context['order_item_id'] ?? 0 ),
			'product_id'    => (int) ( $context['product_id'] ?? 0 ),
			'operation'     => $operation,
		);
	}
}
