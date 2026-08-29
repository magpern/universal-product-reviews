<?php
/**
 * M11 recommendation value object.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

/**
 * Deterministic operator recommendation derived from a terminal assessment.
 */
final class Recommendation {

	public const ACTION_NEEDS_HUMAN        = 'needs_human';
	public const ACTION_LIKELY_PUBLISHABLE = 'likely_publishable';
	public const ACTION_LIKELY_SPAM        = 'likely_spam';
	public const ACTION_LIKELY_ABUSE       = 'likely_abuse';
	public const ACTION_MANDATORY_HUMAN    = 'mandatory_human';

	/** @var list<string> */
	public const ACTIONS = array(
		self::ACTION_NEEDS_HUMAN,
		self::ACTION_LIKELY_PUBLISHABLE,
		self::ACTION_LIKELY_SPAM,
		self::ACTION_LIKELY_ABUSE,
		self::ACTION_MANDATORY_HUMAN,
	);

	/**
	 * @param list<string> $explanation_codes Allowlisted reason codes used in explanation.
	 */
	public function __construct(
		public readonly string $action,
		public readonly string $policy_version,
		public readonly array $explanation_codes
	) {
	}

	public function is_actionable_attention(): bool {
		return in_array(
			$this->action,
			array(
				self::ACTION_LIKELY_SPAM,
				self::ACTION_LIKELY_ABUSE,
				self::ACTION_MANDATORY_HUMAN,
			),
			true
		);
	}
}
