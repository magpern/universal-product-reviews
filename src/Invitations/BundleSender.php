<?php
/**
 * Initial invitation bundle sender.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Email\InvitationMailer;
use UniversalProductReviews\Scheduling\Jobs;

defined( 'ABSPATH' ) || exit;

final class BundleSender {

	public static function send_for_order( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$due = InviteRepository::find_due_for_initial( $order_id );
		if ( ! $due ) {
			return;
		}

		$ids   = array();
		$lines = array();
		foreach ( $due as $row ) {
			$item_id = (int) $row['order_item_id'];
			$eval    = Eligibility::evaluate_item( $order, $order->get_item( $item_id ) );
			if ( ! $eval['eligible'] ) {
				SuppressionService::suppress_item( $item_id, (string) ( $eval['reason'] ?? 'ineligible' ), $order_id );
				continue;
			}
			$ids[]   = $item_id;
			$product = wc_get_product( (int) $row['product_id'] );
			$lines[] = array(
				'order_item_id' => $item_id,
				'product_id'    => (int) $row['product_id'],
				'product_name'  => $product ? $product->get_name() : ( 'Product #' . $row['product_id'] ),
			);
		}

		if ( ! $ids ) {
			return;
		}

		$claim = SendClaimService::claim_initial_bundle( $ids );
		if ( ! $claim['claimed'] ) {
			return;
		}

		$claimed_lines = array_values(
			array_filter(
				$lines,
				static fn( array $l ): bool => in_array( (int) $l['order_item_id'], $claim['claimed'], true )
			)
		);

		$to     = $order->get_billing_email();
		$result = InvitationMailer::send_bundle( (string) $to, $claimed_lines, $claim['message_id'], false );

		foreach ( $claim['claimed'] as $item_id ) {
			if ( $result->success ) {
				SendClaimService::mark_initial_sent( $item_id );
				AuditLogger::log( 'email.sent', 'system', $order_id, $item_id, array( 'kind' => 'initial', 'message_id' => $claim['message_id'] ) );
				Jobs::schedule_reminder( $item_id, Options::reminder_days_after_initial() );
			} else {
				SendClaimService::fail_initial( $item_id, (string) ( $result->error ?? 'send_failed' ) );
				AuditLogger::log( 'email.failed', 'system', $order_id, $item_id, array( 'kind' => 'initial', 'error' => $result->error ) );
			}
		}
	}
}
