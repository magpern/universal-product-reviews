<?php
/**
 * Validated assessment result DTO (internal).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

final class AssessmentResult {

	/**
	 * @param list<string> $reason_codes
	 */
	public function __construct(
		public readonly string $state,
		public readonly ?int $publication_safety_score,
		public readonly ?string $confidence,
		public readonly array $reason_codes,
		public readonly ?string $failure_code
	) {
	}
}
