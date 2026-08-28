<?php
/**
 * Unit tests for Plugins-screen action links.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Admin\PluginActionLinks;
use UniversalProductReviews\Admin\SettingsPage;
use UniversalProductReviews\Moderation\CommentListEnhancements;

final class PluginActionLinksUnitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['upr_test_caps'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['upr_test_caps'] = array();
		parent::tearDown();
	}

	public function test_both_links_for_privileged_user(): void {
		$GLOBALS['upr_test_caps'] = array(
			'manage_woocommerce' => true,
			'moderate_comments'  => true,
		);

		$out = PluginActionLinks::filter_action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );

		$this->assertArrayHasKey( 'upr_settings', $out );
		$this->assertArrayHasKey( 'upr_product_reviews', $out );
		$this->assertStringContainsString( 'Settings', $out['upr_settings'] );
		$this->assertStringContainsString( 'Product reviews', $out['upr_product_reviews'] );

		$this->assertStringContainsString( 'page=' . SettingsPage::MENU_SLUG, $out['upr_settings'] );
		$this->assertStringContainsString( 'admin.php', $out['upr_settings'] );

		$this->assertStringContainsString( 'edit-comments.php', $out['upr_product_reviews'] );
		$this->assertStringContainsString( 'upr_view=' . CommentListEnhancements::VIEW_PRODUCT_REVIEWS, $out['upr_product_reviews'] );

		$this->assertArrayHasKey( 'deactivate', $out );
		$keys = array_keys( $out );
		$this->assertSame( 'upr_settings', $keys[0] );
	}

	public function test_settings_omitted_without_manage_woocommerce(): void {
		$GLOBALS['upr_test_caps'] = array(
			'moderate_comments' => true,
		);

		$out = PluginActionLinks::filter_action_links( array() );

		$this->assertArrayNotHasKey( 'upr_settings', $out );
		$this->assertArrayHasKey( 'upr_product_reviews', $out );
	}

	public function test_product_reviews_omitted_without_moderate_comments(): void {
		$GLOBALS['upr_test_caps'] = array(
			'manage_woocommerce' => true,
		);

		$out = PluginActionLinks::filter_action_links( array() );

		$this->assertArrayHasKey( 'upr_settings', $out );
		$this->assertArrayNotHasKey( 'upr_product_reviews', $out );
	}

	public function test_no_links_without_capabilities(): void {
		$GLOBALS['upr_test_caps'] = array();

		$out = PluginActionLinks::filter_action_links( array( 'deactivate' => '<a>Deactivate</a>' ) );

		$this->assertSame( array( 'deactivate' => '<a>Deactivate</a>' ), $out );
	}

	public function test_target_urls_match_registered_surfaces(): void {
		$GLOBALS['upr_test_caps'] = array(
			'manage_woocommerce' => true,
			'moderate_comments'  => true,
		);

		$out = PluginActionLinks::filter_action_links( array() );

		$settings_href = $this->href_from_anchor( $out['upr_settings'] );
		$reviews_href  = $this->href_from_anchor( $out['upr_product_reviews'] );

		$this->assertSame(
			\add_query_arg( array( 'page' => SettingsPage::MENU_SLUG ), \admin_url( 'admin.php' ) ),
			$settings_href
		);
		$this->assertSame(
			\add_query_arg(
				array( 'upr_view' => CommentListEnhancements::VIEW_PRODUCT_REVIEWS ),
				\admin_url( 'edit-comments.php' )
			),
			$reviews_href
		);
	}

	private function href_from_anchor( string $html ): string {
		if ( ! preg_match( '/href="([^"]+)"/', $html, $m ) ) {
			$this->fail( 'anchor href missing' );
		}
		return $m[1];
	}
}
