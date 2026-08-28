<?php
/**
 * Assessment retention due dates by comment status.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Moderation\ModerationAudit;

defined( 'ABSPATH' ) || exit;

final class AssessmentRetention {

	public const DAYS_HOLD   = 180;
	public const DAYS_APPROVE = 90;
	public const DAYS_SPAM    = 30;

	/**
	 * UTC MySQL datetime when retention expires for a comment status.
	 */
	public static function due_at_for_status( string $status, int $from_unix ): string {
		$normalised = ModerationAudit::normalise_status( $status );
		$days       = match ( $normalised ) {
			'hold'    => self::DAYS_HOLD,
			'approve' => self::DAYS_APPROVE,
			'spam', 'trash' => self::DAYS_SPAM,
			default => self::DAYS_HOLD,
		};
		return gmdate( 'Y-m-d H:i:s', $from_unix + ( $days * DAY_IN_SECONDS ) );
	}
}
