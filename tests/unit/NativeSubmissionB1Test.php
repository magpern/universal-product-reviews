<?php
/**
 * B1 native submission guard and PDP display helper — unit tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit\Submission;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Submission\NativePdpForm;
use UniversalProductReviews\Submission\NativeSubmissionGuard;

final class NativeSubmissionGuardTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$GLOBALS['upr_test_post_type'],
			$GLOBALS['upr_test_logged_in'],
			$GLOBALS['upr_test_user_id'],
			$GLOBALS['upr_test_options'],
			$GLOBALS['upr_test_verified_purchase'],
			$GLOBALS['upr_test_users'],
			$GLOBALS['upr_test_posts'],
			$GLOBALS['upr_test_wc_products']
		);
		parent::tearDown();
	}

	private function stub_visible_product( int $product_id = 10 ): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		$GLOBALS['upr_test_posts'][ $product_id ] = (object) array(
			'ID'          => $product_id,
			'post_type'   => 'product',
			'post_status' => 'publish',
		);
		$GLOBALS['upr_test_wc_products'][ $product_id ] = new class() {
			public function get_catalog_visibility(): string {
				return 'visible';
			}
		};
		$GLOBALS['upr_test_options'] = array(
			'woocommerce_enable_reviews'                      => 'yes',
			'woocommerce_review_rating_verification_required' => 'yes',
		);
	}

	public function test_rejects_logged_in_non_purchaser(): void {
		$this->stub_visible_product( 10 );
		$GLOBALS['upr_test_logged_in']         = true;
		$GLOBALS['upr_test_user_id']           = 7;
		$GLOBALS['upr_test_verified_purchase'] = false;
		$GLOBALS['upr_test_users'][7]          = (object) array(
			'ID'         => 7,
			'user_email' => 'nonbuyer@example.com',
		);

		$this->expectException( \RuntimeException::class );
		NativeSubmissionGuard::reject_unavailable_product_reviews(
			array(
				'comment_post_ID' => 10,
				'comment_type'    => 'review',
			)
		);
	}

	public function test_allows_logged_in_verified_purchaser(): void {
		$this->stub_visible_product( 10 );
		$GLOBALS['upr_test_logged_in']         = true;
		$GLOBALS['upr_test_user_id']           = 8;
		$GLOBALS['upr_test_verified_purchase'] = true;
		$GLOBALS['upr_test_users'][8]          = (object) array(
			'ID'         => 8,
			'user_email' => 'buyer@example.com',
		);

		$result = NativeSubmissionGuard::reject_unavailable_product_reviews(
			array(
				'comment_post_ID' => 10,
				'comment_type'    => 'review',
			)
		);
		$this->assertSame( 'review', $result['comment_type'] );
	}

	public function test_ignores_non_product_posts(): void {
		$GLOBALS['upr_test_post_type'] = 'post';
		$GLOBALS['upr_test_logged_in'] = true;
		$GLOBALS['upr_test_user_id']   = 1;

		$result = NativeSubmissionGuard::reject_unavailable_product_reviews(
			array(
				'comment_post_ID' => 99,
				'comment_type'    => 'review',
			)
		);
		$this->assertSame( 99, $result['comment_post_ID'] );
	}
}

final class NativePdpFormTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$GLOBALS['upr_test_logged_in'],
			$GLOBALS['upr_test_user_id'],
			$GLOBALS['upr_test_options'],
			$GLOBALS['upr_test_verified_purchase'],
			$GLOBALS['upr_test_users'],
			$GLOBALS['upr_test_posts'],
			$GLOBALS['upr_test_wc_products']
		);
		parent::tearDown();
	}

	private function stub_visible_product( int $product_id = 10 ): void {
		$GLOBALS['upr_test_posts'][ $product_id ] = (object) array(
			'ID'          => $product_id,
			'post_type'   => 'product',
			'post_status' => 'publish',
		);
		$GLOBALS['upr_test_wc_products'][ $product_id ] = new class() {
			public function get_catalog_visibility(): string {
				return 'visible';
			}
		};
		$GLOBALS['upr_test_options'] = array(
			'woocommerce_enable_reviews'                      => 'yes',
			'woocommerce_review_rating_verification_required' => 'yes',
		);
	}

	public function test_false_for_guest(): void {
		$this->stub_visible_product( 10 );
		$GLOBALS['upr_test_user_id'] = 0;
		$this->assertFalse( NativePdpForm::should_render( 10 ) );
	}

	public function test_false_for_non_purchaser(): void {
		$this->stub_visible_product( 10 );
		$GLOBALS['upr_test_user_id']           = 7;
		$GLOBALS['upr_test_verified_purchase'] = false;
		$GLOBALS['upr_test_users'][7]          = (object) array(
			'ID'         => 7,
			'user_email' => 'nonbuyer@example.com',
		);
		$this->assertFalse( NativePdpForm::should_render( 10 ) );
	}

	public function test_true_for_verified_purchaser(): void {
		$this->stub_visible_product( 10 );
		$GLOBALS['upr_test_user_id']           = 8;
		$GLOBALS['upr_test_verified_purchase'] = true;
		$GLOBALS['upr_test_users'][8]          = (object) array(
			'ID'         => 8,
			'user_email' => 'buyer@example.com',
		);
		$this->assertTrue( NativePdpForm::should_render( 10 ) );
	}

	public function test_false_for_catalog_hidden(): void {
		$GLOBALS['upr_test_options'] = array(
			'woocommerce_enable_reviews'                      => 'yes',
			'woocommerce_review_rating_verification_required' => 'yes',
		);
		$GLOBALS['upr_test_posts'][21] = (object) array(
			'ID'          => 21,
			'post_type'   => 'product',
			'post_status' => 'publish',
		);
		$GLOBALS['upr_test_wc_products'][21] = new class() {
			public function get_catalog_visibility(): string {
				return 'hidden';
			}
		};
		$GLOBALS['upr_test_user_id']           = 8;
		$GLOBALS['upr_test_verified_purchase'] = true;
		$GLOBALS['upr_test_users'][8]          = (object) array(
			'ID'         => 8,
			'user_email' => 'buyer@example.com',
		);
		$this->assertFalse( NativePdpForm::should_render( 21 ) );
	}
}
