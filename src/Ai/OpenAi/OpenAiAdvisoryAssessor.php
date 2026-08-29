<?php
/**
 * OpenAI advisory assessor (Responses API; store:false; typed failures).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

use UniversalProductReviews\Ai\AssessmentProvider;
use UniversalProductReviews\Ai\AssessmentRequest;
use UniversalProductReviews\Ai\AssessmentResult;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Ai\ProviderError;
use UniversalProductReviews\Ai\ProviderFingerprint;
use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

final class OpenAiAdvisoryAssessor implements AssessmentProvider {

	private OpenAiTransport $transport;

	public function __construct( ?OpenAiTransport $transport = null ) {
		$this->transport = $transport ?? new WpRemoteOpenAiTransport();
	}

	public function assess( AssessmentRequest $request ): AssessmentResult {
		return $this->assess_text(
			$request->review_text,
			$request->policy_version,
			Options::openai_model(),
			Options::openai_max_output_tokens(),
			Options::ai_operator_guidance(),
			Options::ai_allowed_phrases(),
			Options::ai_disallowed_phrases()
		);
	}

	/**
	 * Test-connection path: fixed synthetic review; same request contract.
	 *
	 * @throws ProviderError
	 */
	public function test_connection(): AssessmentResult {
		return $this->assess_text(
			OpenAiConstants::TEST_CONNECTION_REVIEW_TEXT,
			PolicyAllowlist::POLICY_VERSION,
			Options::openai_model(),
			Options::openai_max_output_tokens(),
			'',
			array(),
			array()
		);
	}

	/**
	 * Non-secret fingerprint for persistence callers.
	 */
	public static function fingerprint_for_current_options( string $policy_version ): string {
		$guidance = Options::ai_operator_guidance();
		$phrases  = array_merge( Options::ai_allowed_phrases(), Options::ai_disallowed_phrases() );
		return ProviderFingerprint::for_openai(
			Options::openai_model(),
			Options::openai_max_output_tokens(),
			hash( 'sha256', $guidance ),
			hash( 'sha256', wp_json_encode( $phrases ) ?: '' ),
			$policy_version
		);
	}

	/**
	 * @param list<string> $allowed
	 * @param list<string> $disallowed
	 * @throws ProviderError
	 */
	private function assess_text(
		string $review_text,
		string $policy_version,
		string $model,
		int $max_tokens,
		string $guidance,
		array $allowed,
		array $disallowed
	): AssessmentResult {
		$secret = CredentialResolver::require_secret();
		$built  = OpenAiRequestBuilder::build(
			$secret,
			$review_text,
			$model,
			$max_tokens,
			$guidance,
			$allowed,
			$disallowed,
			$policy_version
		);

		$http = $this->transport->post(
			$built['url'],
			$built['headers'],
			$built['body'],
			OpenAiConstants::HTTP_TIMEOUT_SECONDS
		);

		// Best-effort scrub of local secret reference; never log $built['headers'].
		unset( $secret, $built );

		return OpenAiResponseParser::parse( $http );
	}
}
