<?php
/**
 * Production wp_mail transport (at-least-once).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Email;

defined( 'ABSPATH' ) || exit;

final class WpMailTransport implements MailTransport {

	public function send( EmailMessage $message ): SendResult {
		$headers   = $message->headers;
		$headers[] = 'Content-Type: text/plain; charset=UTF-8';
		$headers[] = 'X-UPR-Message-ID: ' . $message->message_id;

		$ok = wp_mail( $message->to, $message->subject, $message->body, $headers );
		return new SendResult( (bool) $ok, $ok ? null : 'wp_mail_failed', $message->message_id );
	}
}
