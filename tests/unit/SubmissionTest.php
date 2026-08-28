<?php
/**
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit\Submission;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Submission\GuestSubmissionGuard;
use UniversalProductReviews\Submission\ReviewAvailability;

final class GuestSubmissionGuardTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['upr_test_post_type'], $GLOBALS['upr_test_logged_in'] );
		parent::tearDown();
	}

	public function test_blocks_guest_product_review(): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		$GLOBALS['upr_test_logged_in'] = false;

		$this->expectException( \RuntimeException::class );
		GuestSubmissionGuard::block_guest_product_reviews(
			array(
				'comment_post_ID' => 5,
				'comment_type'    => 'review',
			)
		);
	}

	public function test_allows_guest_non_review_on_product(): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		$GLOBALS['upr_test_logged_in'] = false;

		$data = GuestSubmissionGuard::block_guest_product_reviews(
			array(
				'comment_post_ID' => 5,
				'comment_type'    => 'comment',
			)
		);
		$this->assertSame( 'comment', $data['comment_type'] );
	}
}

	final class ReviewAvailabilityTest extends TestCase {

	private function stub_reviewable_product( int $product_id = 1 ): void {
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
	}

	protected function tearDown(): void {
		unset( $GLOBALS['upr_test_options'], $GLOBALS['upr_test_verified_purchase'], $GLOBALS['upr_test_users'], $GLOBALS['upr_test_posts'], $GLOBALS['upr_test_wc_products'], $GLOBALS['upr_test_filters'] );
		parent::tearDown();
	}

	public function test_guest_reason_code(): void {
		$this->stub_reviewable_product( 1 );
		$GLOBALS['upr_test_options'] = array(
			'woocommerce_enable_reviews' => 'yes',
		);
		$result                      = ReviewAvailability::default_availability( array(), 1, 0 );
		$this->assertFalse( $result['can_submit'] );
		$this->assertSame( 'guest_requires_invitation', $result['reason_code'] );
	}

	public function test_not_verified_purchaser_reason_code(): void {
		$this->stub_reviewable_product( 1 );
		$GLOBALS['upr_test_options']            = array(
			'woocommerce_enable_reviews'                        => 'yes',
			'woocommerce_review_rating_verification_required'     => 'yes',
		);
		$GLOBALS['upr_test_verified_purchase']  = false;
		$result                                 = ReviewAvailability::default_availability( array(), 1, 7 );
		$this->assertFalse( $result['can_submit'] );
		$this->assertSame( 'not_verified_purchaser', $result['reason_code'] );
	}

	public function test_reviews_disabled_reason_code(): void {
		$GLOBALS['upr_test_options'] = array(
			'woocommerce_enable_reviews' => 'no',
		);
		$result                      = ReviewAvailability::default_availability( array(), 1, 7 );
		$this->assertSame( 'reviews_disabled', $result['reason_code'] );
	}

	public function test_product_not_reviewable_reason_code(): void {
		$GLOBALS['upr_test_options'] = array(
			'woocommerce_enable_reviews' => 'yes',
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
		$result = ReviewAvailability::default_availability( array(), 21, 7 );
		$this->assertFalse( $result['can_submit'] );
		$this->assertSame( 'product_not_reviewable', $result['reason_code'] );
	}

	public function test_verified_purchaser_can_submit(): void {
		$this->stub_reviewable_product( 1 );
		$GLOBALS['upr_test_users'][7]        = (object) array( 'user_email' => 'buyer@example.com' );
		$GLOBALS['upr_test_options']           = array(
			'woocommerce_enable_reviews'                      => 'yes',
			'woocommerce_review_rating_verification_required' => 'yes',
		);
		$GLOBALS['upr_test_verified_purchase'] = true;
		$result                                = ReviewAvailability::default_availability( array(), 1, 7 );
		$this->assertTrue( $result['can_submit'] );
		$this->assertNull( $result['reason_code'] );
	}

	public function test_resolve_fail_closed_on_malformed_filter(): void {
		$this->stub_reviewable_product( 1 );
		$GLOBALS['upr_test_filters']['upr_product_review_availability'] = array(
			static fn() => null,
		);
		$result = ReviewAvailability::resolve( 1, 7 );
		$this->assertFalse( $result['can_submit'] );
		unset( $GLOBALS['upr_test_filters'] );
	}
}
