<?php
/**
 * Paid synthetic OpenAI test-connection (external quota only; no M9 rate/circuit).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

use UniversalProductReviews\Ai\AssessmentValidator;
use UniversalProductReviews\Ai\ExternalQuotaRepository;
use UniversalProductReviews\Ai\ProviderError;
use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

final class ExternalAiTestConnection {

	/**
	 * @return string Allowlisted evidence / failure code (never secrets or bodies).
	 */
	public static function run( ?OpenAiTransport $transport = null ): string {
		if ( ! Options::ai_external_enabled() ) {
			return 'external_disabled';
		}

		$status = CredentialResolver::status();
		if ( ! $status['present'] ) {
			return ProviderError::CREDENTIAL_MISSING;
		}

		$quota = ExternalQuotaRepository::try_consume(
			Options::openai_daily_request_cap(),
			Options::openai_monthly_request_cap()
		);
		if ( 'budget_exceeded' === $quota ) {
			return ProviderError::BUDGET_EXCEEDED;
		}

		try {
			$assessor = new OpenAiAdvisoryAssessor( $transport );
			$result   = $assessor->test_connection();
		} catch ( ProviderError $e ) {
			return $e->failure_code();
		} catch ( \Throwable $e ) {
			unset( $e );
			return ProviderError::PROVIDER_UNAVAILABLE;
		}

		$validated = AssessmentValidator::validate( $result );
		if ( is_string( $validated ) ) {
			return $validated;
		}

		return 'connection_ok';
	}
}
