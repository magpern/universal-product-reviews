<?php
/**
 * M10 WP3 unit: ProviderResolver enum and failure mapping helpers.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Ai\BuiltInAssessmentProvider;
use UniversalProductReviews\Ai\OpenAi\CredentialResolver;
use UniversalProductReviews\Ai\OpenAi\OpenAiAdvisoryAssessor;
use UniversalProductReviews\Ai\ProviderError;
use UniversalProductReviews\Ai\ProviderResolver;
use UniversalProductReviews\Config\Options;

final class M10ProviderResolverUnitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		CredentialResolver::set_test_credential( null );
		ProviderResolver::set_test_openai_provider( null );
		unset( $GLOBALS['upr_test_options'] );
	}

	protected function tearDown(): void {
		CredentialResolver::set_test_credential( null );
		ProviderResolver::set_test_openai_provider( null );
		unset( $GLOBALS['upr_test_options'] );
		parent::tearDown();
	}

	public function test_kind_defaults_local(): void {
		$this->assertSame( 'local', ProviderResolver::kind() );
		$this->assertInstanceOf( BuiltInAssessmentProvider::class, ProviderResolver::resolve( 'local' ) );
	}

	public function test_openai_kind_uses_advisory_assessor_without_filter_hook(): void {
		$GLOBALS['upr_test_options'][ Options::AI_PROVIDER ] = 'openai';
		$this->assertSame( 'openai', ProviderResolver::kind() );
		$this->assertInstanceOf( OpenAiAdvisoryAssessor::class, ProviderResolver::resolve( 'openai' ) );
	}

	public function test_budget_code_is_allowlisted(): void {
		$this->assertSame( 'budget_exceeded', ProviderError::budget_exceeded()->failure_code() );
	}
}
