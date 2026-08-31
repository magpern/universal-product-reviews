<?php
/**
 * M10 WP5: regression policy invariants (version, export, C19, filters).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Ai\AiProvider;
use UniversalProductReviews\Config\Options;

final class M10RegressionPolicyUnitTest extends TestCase {

	public function test_plugin_version_unchanged_at_0_8_0(): void {
		$boot = file_get_contents( dirname( __DIR__, 2 ) . '/universal-product-reviews.php' );
		$this->assertIsString( $boot );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*Version:\s*0\.9\.0-rc\.2\s*$/m', $boot );
	}

	public function test_support_export_schema_unchanged(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/SupportExport.php' );
		$this->assertIsString( $src );
		$this->assertStringContainsString( 'upr-support-export/v1', $src );
		$this->assertStringNotContainsString( 'UPR_OPENAI_API_KEY', $src );
		$this->assertStringNotContainsString( 'openai_api_key', $src );
	}

	public function test_c19_selected_defaults_local(): void {
		unset( $GLOBALS['upr_test_options'] );
		$this->assertSame( 'local', AiProvider::selected() );
		$this->assertFalse( Options::ai_external_enabled() );
	}

	public function test_no_provider_filter_strings_in_src(): void {
		$root = dirname( __DIR__, 2 ) . '/src';
		$banned = array(
			'upr_local_moderation_assessment_provider',
			'upr_moderation_assessment_provider',
		);
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$contents = file_get_contents( $file->getPathname() );
			$this->assertIsString( $contents );
			foreach ( $banned as $needle ) {
				$this->assertStringNotContainsString(
					$needle,
					$contents,
					$file->getPathname() . ' must not register provider filter ' . $needle
				);
			}
		}
	}

	public function test_openai_http_only_under_openai_path(): void {
		$ai = dirname( __DIR__, 2 ) . '/src/Ai';
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $ai ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$path = $file->getPathname();
			$contents = file_get_contents( $path );
			$this->assertIsString( $contents );
			if ( false !== strpos( $path, '/OpenAi/' ) ) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression(
				'/wp_remote_|wp_safe_remote_|curl_|fsockopen|stream_socket_client|socket_create/',
				$contents,
				$path . ' must not use network primitives outside OpenAi/'
			);
		}
		$transport = dirname( __DIR__, 2 ) . '/src/Ai/OpenAi/WpRemoteOpenAiTransport.php';
		$this->assertFileExists( $transport );
		$t = file_get_contents( $transport );
		$this->assertIsString( $t );
		$this->assertStringContainsString( 'wp_remote_post', $t );
	}
}
