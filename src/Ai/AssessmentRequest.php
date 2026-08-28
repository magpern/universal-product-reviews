<?php
/**
 * Built-in local assessment request (internal; not a public contract).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

final class AssessmentRequest {

	public function __construct(
		public readonly string $review_text,
		public readonly string $policy_version,
		public readonly ?string $detected_language = null
	) {
	}
}
