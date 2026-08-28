<?php
/**
 * M6 shipped example privacy — static assertions.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class M6ExamplePrivacyUnitTest extends TestCase {

	public function test_adapter_example_never_logs_persists_or_forwards(): void {
		$path = dirname( __DIR__, 2 ) . '/docs/integration/site-upr-adapters.php.example';
		$this->assertFileExists( $path );
		$src = (string) file_get_contents( $path );

		foreach ( array(
			'error_log',
			'update_option',
			'add_option',
			'set_transient',
			'wp_remote_',
			'wp_safe_remote_',
			'file_put_contents',
			'WC()->logger',
			'wc_get_logger',
		) as $needle ) {
			$this->assertStringNotContainsString(
				$needle,
				$src,
				"example must not contain {$needle}"
			);
		}

		$this->assertStringContainsString( 'upr_mail_transport', $src );
		$this->assertStringContainsString( 'Sensitive-data-bearing', $src );
		$this->assertStringContainsString( 'token-free', $src );
	}
}
