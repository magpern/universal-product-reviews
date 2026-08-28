<?php
/**
 * Audit hooks for invitation email enable/disable.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Admin\AdminCache;
use UniversalProductReviews\Audit\AuditLogger;
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
			Options::refresh_scheduling_boundary();
		} else {
			Jobs::cancel_pending_invitation_sends();
		}

		$actor = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		AuditLogger::log(
			$enabled ? 'invite.emails_enabled' : 'invite.emails_disabled',
			'hook',
			null,
			null,
			array(
				'actor_id'                 => $actor,
				'controls_epoch'           => Options::invitation_controls_epoch(),
				'scheduling_boundary_unix' => Options::invitation_scheduling_boundary_unix(),
			)
		);

		AdminCache::invalidate();
	}
}
