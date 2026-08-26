<?php
/**
 * Logging / fake transport for non-production.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Email;

defined( 'ABSPATH' ) || exit;

final class LoggingMailTransport implements MailTransport {

	/** @var list<EmailMessage> */
	public static array $sent = array();

	public function send( EmailMessage $message ): SendResult {
		self::$sent[] = $message;
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->info(
				'UPR invitation email (logging transport)',
				array(
					'source'     => 'universal-product-reviews',
					'message_id' => $message->message_id,
					'to_hash'    => hash( 'sha256', $message->to ),
				)
			);
		}
		return new SendResult( true, null, $message->message_id );
	}

	public static function reset(): void {
		self::$sent = array();
	}
}
