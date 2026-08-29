<?php
/**
 * Assessment provider contract (built-in or OpenAI).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

interface AssessmentProvider {

	/**
	 * @throws ProviderError Typed failure without secret-bearing messages.
	 */
	public function assess( AssessmentRequest $request ): AssessmentResult;
}
