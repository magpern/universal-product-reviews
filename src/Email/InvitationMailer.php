<?php
/**
 * Invitation email composition.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Email;

use UniversalProductReviews\Tokens\DefaultReviewLinkBuilder;
use UniversalProductReviews\Tokens\TokenService;

defined( 'ABSPATH' ) || exit;

final class InvitationMailer {

	/**
	 * @param list<array{order_item_id:int,product_id:int,product_name:string}> $lines
	 */
	public static function send_bundle( string $to, array $lines, string $message_id, bool $is_reminder = false ): SendResult {
		$builder = DefaultReviewLinkBuilder::resolve();
		$parts   = array();

		foreach ( $lines as $line ) {
			$issued = TokenService::issue_invite( (int) $line['order_item_id'], (int) $line['product_id'] );
			if ( ! $issued ) {
				continue;
			}
			$url     = $builder->invite_exchange_url( $issued['raw'] );
			$parts[] = sprintf( "%s\n%s", $line['product_name'], $url );
		}

		if ( ! $parts ) {
			return new SendResult( false, 'no_links' );
		}

		$subject = $is_reminder
			? __( 'Reminder: review your recent purchase', 'universal-product-reviews' )
			: __( 'How was your purchase? Leave a review', 'universal-product-reviews' );

		$body = implode( "\n\n", $parts );
		$body = (string) apply_filters( 'upr_invitation_email_body', $body, $lines, $is_reminder );
		$subject = (string) apply_filters( 'upr_invitation_email_subject', $subject, $lines, $is_reminder );
		$headers = (array) apply_filters( 'upr_invitation_email_headers', array(), $message_id );

		$message = new EmailMessage( $to, $subject, $body, $message_id, $headers );
		return MailTransportFactory::make()->send( $message );
	}
}
