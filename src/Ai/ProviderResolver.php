<?php
/**
 * Fixed enum provider resolution (local | openai). No filters.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Ai\OpenAi\OpenAiAdvisoryAssessor;
use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

final class ProviderResolver {

	/** @var AssessmentProvider|null Test seam for OpenAI path only. */
	private static ?AssessmentProvider $test_openai_provider = null;

	/**
	 * Test seam only.
	 */
	public static function set_test_openai_provider( ?AssessmentProvider $provider ): void {
		self::$test_openai_provider = $provider;
	}

	/**
	 * @return 'local'|'openai'
	 */
	public static function kind(): string {
		return Options::ai_provider();
	}

	public static function resolve( string $kind ): AssessmentProvider {
		if ( 'openai' === $kind ) {
			if ( null !== self::$test_openai_provider ) {
				return self::$test_openai_provider;
			}
			return new OpenAiAdvisoryAssessor();
		}

		return new BuiltInAssessmentProvider();
	}

	/**
	 * Fingerprint for the active provider configuration (never includes secrets).
	 */
	public static function fingerprint( string $kind, string $policy_version ): string {
		if ( 'openai' === $kind ) {
			return OpenAiAdvisoryAssessor::fingerprint_for_current_options( $policy_version );
		}
		return ProviderFingerprint::for_builtin( $policy_version );
	}
}
