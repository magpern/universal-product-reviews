<?php
/**
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit\Moderation;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Moderation\ReviewModeration;
use UniversalProductReviews\Moderation\ReviewScope;

final class ReviewScopeTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['upr_test_post_type'] );
		parent::tearDown();
	}

	public function test_product_review_in_scope(): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		$this->assertTrue(
			ReviewScope::is_product_review(
				array(
					'comment_post_ID' => 42,
					'comment_type'    => 'review',
				)
			)
		);
	}

	public function test_non_review_on_product_out_of_scope(): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		$this->assertFalse(
			ReviewScope::is_product_review(
				array(
					'comment_post_ID' => 42,
					'comment_type'    => 'comment',
				)
			)
		);
	}

	public function test_review_on_post_out_of_scope(): void {
		$GLOBALS['upr_test_post_type'] = 'post';
		$this->assertFalse(
			ReviewScope::is_product_review(
				array(
					'comment_post_ID' => 42,
					'comment_type'    => 'review',
				)
			)
		);
	}
}

final class ReviewModerationTest extends TestCase {

	public function test_holds_approved_product_review(): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		$result                        = ReviewModeration::hold_new_product_reviews(
			1,
			array(
				'comment_post_ID' => 10,
				'comment_type'    => 'review',
			)
		);
		$this->assertSame( 0, $result );
	}

	public function test_preserves_spam(): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		$this->assertSame(
			'spam',
			ReviewModeration::hold_new_product_reviews(
				'spam',
				array(
					'comment_post_ID' => 10,
					'comment_type'    => 'review',
				)
			)
		);
	}

	public function test_preserves_trash(): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		$this->assertSame(
			'trash',
			ReviewModeration::hold_new_product_reviews(
				'trash',
				array(
					'comment_post_ID' => 10,
					'comment_type'    => 'review',
				)
			)
		);
	}

	public function test_passes_through_blog_comment(): void {
		$GLOBALS['upr_test_post_type'] = 'post';
		$this->assertSame(
			1,
			ReviewModeration::hold_new_product_reviews(
				1,
				array(
					'comment_post_ID' => 10,
					'comment_type'    => 'comment',
				)
			)
		);
	}
}
