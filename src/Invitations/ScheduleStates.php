<?php
/**
 * Invite schedule state constants.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

defined( 'ABSPATH' ) || exit;

final class ScheduleStates {

	public const PENDING_ELIGIBILITY = 'pending_eligibility';
	public const SCHEDULED           = 'scheduled';
	public const DELAYED             = 'delayed';
	public const INITIAL_SENDING     = 'initial_sending';
	public const INITIAL_SENT        = 'initial_sent';
	public const REMINDER_SENDING    = 'reminder_sending';
	public const REMINDER_SENT       = 'reminder_sent';
	public const SUBMITTING          = 'submitting';
	public const COMPLETED           = 'completed';
	public const SUPPRESSED          = 'suppressed';

	/**
	 * @return list<string>
	 */
	public static function sending_terminal(): array {
		return array( self::COMPLETED, self::SUPPRESSED );
	}

	public static function is_terminal( string $state ): bool {
		return in_array( $state, self::sending_terminal(), true );
	}
}
