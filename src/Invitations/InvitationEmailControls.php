<?php
/**
 * Master invitation-email enable/disable transitions.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Scheduling\Jobs;

defined( 'ABSPATH' ) || exit;

final class InvitationEmailControls {

	/**
	 * Set master enable flag. Enabling refreshes the no-retro-send scheduling boundary.
	 */
	public static function set_emails_enabled( bool $enabled ): void {
		$was = Options::invitation_emails_enabled();
		update_option( Options::INVITATION_EMAILS_ENABLED, $enabled ? 'yes' : 'no', false );

		if ( $enabled === $was ) {
			return;
		}

		Options::bump_controls_epoch();

		if ( $enabled ) {
			// Only source events at/after this instant may schedule/send via normal paths.
			Options::refresh_scheduling_boundary();
		} else {
			Jobs::cancel_pending_invitation_sends();
		}
	}
}
