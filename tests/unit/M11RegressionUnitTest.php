<?php
/**
 * M11 regression guards.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Admin\SupportExport;
use UniversalProductReviews\Config\Options;

final class M11RegressionUnitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['upr_test_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['upr_test_options'] = array();
		parent::tearDown();
	}

	public function test_display_option_absent_means_enabled(): void {
		unset( $GLOBALS['upr_test_options'][ Options::AI_RECOMMENDATIONS_DISPLAY ] );
		$this->assertTrue( Options::ai_recommendations_display_enabled() );
		$GLOBALS['upr_test_options'][ Options::AI_RECOMMENDATIONS_DISPLAY ] = 'no';
		$this->assertFalse( Options::ai_recommendations_display_enabled() );
		$GLOBALS['upr_test_options'][ Options::AI_RECOMMENDATIONS_DISPLAY ] = 'yes';
		$this->assertTrue( Options::ai_recommendations_display_enabled() );
	}

	public function test_display_independent_of_shadow(): void {
		unset( $GLOBALS['upr_test_options'][ Options::AI_RECOMMENDATIONS_DISPLAY ] );
		$GLOBALS['upr_test_options'][ Options::LOCAL_AI_SHADOW_ENABLED ] = 'no';
		$this->assertTrue( Options::ai_recommendations_display_enabled() );
		$this->assertFalse( Options::local_ai_shadow_enabled() );
	}

	public function test_ai_sources_do_not_mutate_comment_status(): void {
		$root = dirname( __DIR__, 2 ) . '/src/Ai';
		$hits = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$src = file_get_contents( $file->getPathname() );
			if ( false !== $src && false !== strpos( $src, 'wp_set_comment_status' ) ) {
				$hits[] = $file->getPathname();
			}
		}
		$this->assertSame( array(), $hits, 'AI package must not call wp_set_comment_status' );
	}

	public function test_no_attention_view_constants_in_comment_enhancements(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/Moderation/CommentListEnhancements.php' );
		$this->assertIsString( $src );
		$this->assertStringNotContainsString( 'AI_ATTENTION', $src );
		$this->assertStringNotContainsString( 'upr_ai_attention', $src );
	}

	public function test_support_export_schema_unchanged(): void {
		$this->assertSame( 'upr-support-export/v1', SupportExport::SCHEMA_VERSION );
	}
}
