<?php
/**
 * Built-in assessor as AssessmentProvider.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

final class BuiltInAssessmentProvider implements AssessmentProvider {

	public function assess( AssessmentRequest $request ): AssessmentResult {
		return BuiltInLocalAssessor::assess( $request );
	}
}
