<?php
/**
 * M12 regression / privacy / forbidden-action guards.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Admin\SupportExport;
use UniversalProductReviews\Config\Options;

final class M12RegressionUnitTest extends TestCase {

	public function test_support_export_schema_unchanged(): void {
		$this->assertSame( 'upr-support-export/v1', SupportExport::SCHEMA_VERSION );
	}

	public function test_runtime_version_unchanged(): void {
		$header = file_get_contents( dirname( __DIR__, 2 ) . '/universal-product-reviews.php' );
		$this->assertIsString( $header );
		$this->assertMatchesRegularExpression( '/Version:\s*0\.9\.0-rc\.1/', $header );
	}

	public function test_ai_package_still_forbids_wp_set_comment_status(): void {
		$root = dirname( __DIR__, 2 ) . '/src/Ai';
		$hits = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$src = (string) file_get_contents( $file->getPathname() );
			if ( false !== strpos( $src, 'wp_set_comment_status(' ) ) {
				$hits[] = $file->getPathname();
			}
		}
		$this->assertSame( array(), $hits );
	}

	public function test_action_sources_forbid_approve_trash_delete_mail_provider(): void {
		$files = array(
			dirname( __DIR__, 2 ) . '/src/Ai/ActionWorker.php',
			dirname( __DIR__, 2 ) . '/src/Ai/ActionPolicy.php',
			dirname( __DIR__, 2 ) . '/src/Moderation/HoldToSpamCas.php',
		);
		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			$this->assertStringNotContainsString( 'wp_trash_comment', $src );
			$this->assertStringNotContainsString( 'wp_delete_comment', $src );
			$this->assertStringNotContainsString( 'wp_mail(', $src );
			$this->assertStringNotContainsString( 'wp_remote_', $src );
			$this->assertDoesNotMatchRegularExpression( "/comment_approved\s*=\s*'1'/", $src );
			$this->assertDoesNotMatchRegularExpression( "/comment_approved\s*=\s*'trash'/", $src );
		}
	}

	public function test_settings_ui_has_no_review_body_or_secrets(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/SettingsPage.php' );
		$this->assertStringNotContainsString( 'UPR_OPENAI_API_KEY=', $src );
		$this->assertStringNotContainsString( 'sk-', $src );
		$this->assertStringContainsString( 'AI_AUTO_SPAM_ENABLED', $src );
	}

	public function test_masters_absent_means_off(): void {
		$GLOBALS['upr_test_options'] = array();
		$this->assertFalse( Options::ai_auto_spam_enabled() );
		$this->assertFalse( Options::ai_auto_spam_policy_enabled() );
		$this->assertFalse( Options::ai_auto_spam_simulation_guard_enabled() );
	}
}
