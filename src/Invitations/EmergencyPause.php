<?php
/**
 * Emergency pause toggle, revocation, and action cancellation.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Scheduling\Jobs;
use UniversalProductReviews\Tokens\TokenRepository;

defined( 'ABSPATH' ) || exit;

final class EmergencyPause {

	/**
	 * Apply pause or unpause from admin/CLI.
	 *
	 * @param string $reason Short sanitized reason for audit.
	 */
	public static function set_paused( bool $paused, string $reason = '', ?int $actor_id = null ): void {
		$was = Options::invitation_emergency_pause();
		if ( $paused === $was ) {
			// Refresh audit meta only when a new reason is supplied while remaining paused.
			if ( $paused && '' !== self::sanitize_reason( $reason ) ) {
				self::store_meta( true, self::sanitize_reason( $reason ), $actor_id ?? (int) get_current_user_id() );
			}
			return;
		}

		$actor_id = $actor_id ?? (int) get_current_user_id();
		$reason   = self::sanitize_reason( $reason );

		update_option( Options::INVITATION_EMERGENCY_PAUSE, $paused ? 'yes' : 'no', false );
		Options::bump_controls_epoch();
		self::store_meta( $paused, $reason, $actor_id );

		$payload = array(
			'actor_id'  => $actor_id,
			'reason'    => $reason,
			'timestamp' => time(),
		);

		if ( $paused ) {
			TokenRepository::revoke_all_outstanding();
			Jobs::cancel_pending_invitation_sends();
			AuditLogger::log( 'invite.emergency_pause', 'hook', null, null, $payload );
		} else {
			AuditLogger::log( 'invite.emergency_unpause', 'hook', null, null, $payload );
		}
	}

	/**
	 * @return array{paused:bool,reason:string,actor_id:int,changed_at:int}
	 */
	public static function meta(): array {
		$raw = get_option( Options::INVITATION_EMERGENCY_PAUSE_META, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'paused'     => Options::invitation_emergency_pause(),
			'reason'     => isset( $raw['reason'] ) ? (string) $raw['reason'] : '',
			'actor_id'   => isset( $raw['actor_id'] ) ? (int) $raw['actor_id'] : 0,
			'changed_at' => isset( $raw['changed_at'] ) ? (int) $raw['changed_at'] : 0,
		);
	}

	private static function store_meta( bool $paused, string $reason, ?int $actor_id ): void {
		update_option(
			Options::INVITATION_EMERGENCY_PAUSE_META,
			array(
				'paused'     => $paused,
				'reason'     => $reason,
				'actor_id'   => (int) ( $actor_id ?? 0 ),
				'changed_at' => time(),
			),
			false
		);
	}

	private static function sanitize_reason( string $reason ): string {
		$reason = sanitize_text_field( $reason );
		return substr( $reason, 0, 191 );
	}
}
