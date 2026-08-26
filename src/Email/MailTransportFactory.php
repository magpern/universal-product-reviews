<?php
/**
 * Resolve mail transport by environment.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Email;

defined( 'ABSPATH' ) || exit;

final class MailTransportFactory {

	public static function make(): MailTransport {
		$filtered = apply_filters( 'upr_mail_transport', null );
		if ( $filtered instanceof MailTransport ) {
			return $filtered;
		}

		$env = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';
		if ( 'production' === $env ) {
			return new WpMailTransport();
		}

		return new LoggingMailTransport();
	}
}
