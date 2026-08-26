<?php
/**
 * Email message DTO.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Email;

defined( 'ABSPATH' ) || exit;

final class EmailMessage {

	public string $to;
	public string $subject;
	public string $body;
	public string $message_id;

	/** @var array<string, string> */
	public array $headers;

	/**
	 * @param array<string, string> $headers
	 */
	public function __construct( string $to, string $subject, string $body, string $message_id, array $headers = array() ) {
		$this->to         = $to;
		$this->subject    = $subject;
		$this->body       = $body;
		$this->message_id = $message_id;
		$this->headers    = $headers;
	}
}
