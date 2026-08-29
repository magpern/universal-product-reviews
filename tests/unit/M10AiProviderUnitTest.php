<?php
/**
 * M10 unit: AiProvider, ProviderError, Options sanitizers.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Ai\AiProvider;
use UniversalProductReviews\Ai\ProviderError;
use UniversalProductReviews\Config\Options;

final class M10AiProviderUnitTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['upr_test_options'] );
		parent::tearDown();
	}

	public function test_selected_defaults_to_local(): void {
		$this->assertSame( 'local', AiProvider::selected() );
		$this->assertFalse( AiProvider::is_openai() );
	}

	public function test_sanitize_provider(): void {
		$this->assertSame( 'openai', Options::sanitize_provider( 'openai' ) );
		$this->assertSame( 'local', Options::sanitize_provider( 'bogus' ) );
	}

	public function test_sanitize_model_manual(): void {
		$this->assertSame( 'gpt-4o-mini', Options::sanitize_model_manual( 'gpt-4o-mini' ) );
		$this->assertSame( '', Options::sanitize_model_manual( 'bad model' ) );
		$this->assertSame( '', Options::sanitize_model_manual( 'http://evil.example/x' ) );
	}

	public function test_provider_error_codes_are_typed(): void {
		$e = ProviderError::credential_missing();
		$this->assertSame( 'credential_missing', $e->failure_code() );
		$this->assertSame( 'credential_missing', $e->getMessage() );
		$this->assertSame( 'budget_exceeded', ProviderError::budget_exceeded()->failure_code() );
	}

	public function test_phrase_list_bounds(): void {
		$long = str_repeat( 'a', 100 );
		$list = Options::sanitize_phrase_list( array( $long, 'ok', '', 1 ) );
		$this->assertSame( Options::PHRASE_MAX_CHARS, strlen( $list[0] ) );
		$this->assertContains( 'ok', $list );
	}
}
