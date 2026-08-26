<?php
/**
 * Mail transport interface.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Email;

defined( 'ABSPATH' ) || exit;

interface MailTransport {

	public function send( EmailMessage $message ): SendResult;
}
