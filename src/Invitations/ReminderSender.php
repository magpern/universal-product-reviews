<?php
/**
 * Reminder sender (fresh token).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Email\InvitationMailer;

defined( 'ABSPATH' ) || exit;

final class ReminderSender {

	public static function send_for_item( int $order_item_id ): void {
		$row = InviteRepository::find( $order_item_id );
		if ( ! $row || ScheduleStates::INITIAL_SENT !== $row['schedule_state'] ) {
			return;
		}
		if ( ! empty( $row['reminder_sent_at'] ) || ! empty( $row['review_completed_at'] ) ) {
			return;
		}

		$order = wc_get_order( (int) $row['order_id'] );
		if ( ! $order ) {
			return;
		}

		$item = $order->get_item( $order_item_id );
		$eval = Eligibility::evaluate_item( $order, $item );
		if ( ! $eval['eligible'] ) {
			SuppressionService::suppress_item( $order_item_id, (string) ( $eval['reason'] ?? 'ineligible' ), (int) $row['order_id'] );
			return;
		}

		$context = array(
			'order_id'      => (int) $row['order_id'],
			'order_item_id' => $order_item_id,
			'product_id'    => (int) $row['product_id'],
			'operation'     => InvitationAuthorisation::OP_REMINDER_SEND,
		);
		$auth = InvitationAuthorisation::evaluate_and_audit( $context );
		if ( InvitationAuthorisation::DECISION_ALLOW !== $auth['decision'] ) {
			return;
		}

		$claim = SendClaimService::claim_reminder( $order_item_id );
		if ( ! $claim ) {
			return;
		}

		$auth = InvitationAuthorisation::evaluate( $context );
		if ( InvitationAuthorisation::DECISION_ALLOW !== $auth['decision'] ) {
			SendClaimService::fail_reminder( $order_item_id, 'authorisation_denied' );
			InvitationAuthorisation::maybe_audit_denied( $auth, $context );
			return;
		}

		$product = wc_get_product( (int) $row['product_id'] );
		$lines   = array(
			array(
				'order_item_id' => $order_item_id,
				'product_id'    => (int) $row['product_id'],
				'product_name'  => $product ? $product->get_name() : ( 'Product #' . $row['product_id'] ),
			),
		);

		$result = InvitationMailer::send_bundle( (string) $order->get_billing_email(), $lines, $claim['message_id'], true );
		if ( $result->success ) {
			SendClaimService::mark_reminder_sent( $order_item_id );
			AuditLogger::log( 'email.sent', 'system', (int) $row['order_id'], $order_item_id, array( 'kind' => 'reminder', 'message_id' => $claim['message_id'] ) );
		} else {
			SendClaimService::fail_reminder( $order_item_id, (string) ( $result->error ?? 'send_failed' ) );
			AuditLogger::log( 'email.failed', 'system', (int) $row['order_id'], $order_item_id, array( 'kind' => 'reminder', 'error' => $result->error ) );
		}
	}
}
