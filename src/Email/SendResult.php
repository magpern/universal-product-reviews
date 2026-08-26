<?php
/**
 * Send result DTO.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Email;

defined( 'ABSPATH' ) || exit;

final class SendResult {

	public bool $success;
	public ?string $error;
	public ?string $provider_message_id;

	public function __construct( bool $success, ?string $error = null, ?string $provider_message_id = null ) {
		$this->success             = $success;
		$this->error               = $error;
		$this->provider_message_id = $provider_message_id;
	}
}
