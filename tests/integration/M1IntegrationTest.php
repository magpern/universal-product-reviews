<?php
/**
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use UniversalProductReviews\Moderation\ReviewModeration;
use UniversalProductReviews\Submission\GuestSubmissionGuard;
use WP_UnitTestCase;

final class ModerationIntegrationTest extends WP_UnitTestCase {

	public function test_verified_product_review_becomes_pending(): void {
		$product_id = $this->factory->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		$approved = apply_filters(
			'pre_comment_approved',
			1,
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Tester',
				'comment_author_email' => 'tester@example.com',
				'comment_content'      => 'Great product.',
				'comment_type'         => 'review',
			)
		);

		$this->assertSame( 0, $approved );

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Tester',
				'comment_author_email' => 'tester@example.com',
				'comment_content'      => 'Great product.',
				'comment_type'         => 'review',
				'comment_approved'     => $approved,
			)
		);

		$comment = get_comment( $comment_id );
		$this->assertNotFalse( $comment );
		$this->assertSame( '0', $comment->comment_approved );
	}

	public function test_spam_decision_preserved(): void {
		add_filter(
			'pre_comment_approved',
			static fn() => 'spam',
			5,
			2
		);

		$product_id = $this->factory->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Spammer',
				'comment_author_email' => 'spam@example.com',
				'comment_content'      => 'Spam review.',
				'comment_type'         => 'review',
				'comment_approved'     => 1,
			)
		);

		$comment = get_comment( $comment_id );
		$this->assertSame( 'spam', $comment->comment_approved );
	}

	public function test_blog_comment_unchanged(): void {
		$post_id = $this->factory->post->create(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
			)
		);

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Reader',
				'comment_author_email' => 'reader@example.com',
				'comment_content'      => 'Nice post.',
				'comment_type'         => 'comment',
				'comment_approved'     => 1,
			)
		);

		$comment = get_comment( $comment_id );
		$this->assertSame( '1', $comment->comment_approved );
	}

	public function test_global_comment_options_unchanged(): void {
		$before_moderation = get_option( 'comment_moderation' );
		$before_whitelist  = get_option( 'comment_whitelist' );

		$product_id = $this->factory->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Tester',
				'comment_author_email' => 'tester2@example.com',
				'comment_content'      => 'Another review.',
				'comment_type'         => 'review',
				'comment_approved'     => 1,
			)
		);

		$this->assertSame( $before_moderation, get_option( 'comment_moderation' ) );
		$this->assertSame( $before_whitelist, get_option( 'comment_whitelist' ) );
	}
}

final class GuestSubmissionGuardIntegrationTest extends WP_UnitTestCase {

	public function test_guest_product_review_blocked(): void {
		wp_set_current_user( 0 );

		$product_id = $this->factory->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		$before = get_comments( array( 'count' => true ) );

		try {
			GuestSubmissionGuard::block_guest_product_reviews(
				array(
					'comment_post_ID'      => $product_id,
					'comment_type'         => 'review',
					'comment_author'       => 'Guest',
					'comment_author_email' => 'guest@example.com',
					'comment_content'      => 'Guest review attempt.',
				)
			);
			$this->fail( 'Expected wp_die for guest product review.' );
		} catch ( \WPDieException $e ) {
			$this->assertStringContainsString( 'logged-in', strtolower( $e->getMessage() ) );
		}

		$this->assertSame( $before, get_comments( array( 'count' => true ) ) );
	}

	public function test_non_review_product_comment_not_blocked_for_guest(): void {
		wp_set_current_user( 0 );

		$product_id = $this->factory->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		$result = GuestSubmissionGuard::block_guest_product_reviews(
			array(
				'comment_post_ID'      => $product_id,
				'comment_type'         => 'comment',
				'comment_author'       => 'Guest',
				'comment_author_email' => 'guest2@example.com',
				'comment_content'      => 'General comment.',
			)
		);

		$this->assertSame( 'comment', $result['comment_type'] );
	}

	public function test_upr_preprocess_runs_after_wc_type_normalisation(): void {
		global $wp_filter;

		$this->assertArrayHasKey( 'preprocess_comment', $wp_filter );
		$callbacks = $wp_filter['preprocess_comment']->callbacks;

		$upr_priority = GuestSubmissionGuard::FILTER_PRIORITY;
		$this->assertArrayHasKey( $upr_priority, $callbacks );

		$has_earlier = false;
		foreach ( array_keys( $callbacks ) as $priority ) {
			if ( (int) $priority < $upr_priority ) {
				$has_earlier = true;
				break;
			}
		}

		$this->assertTrue( $has_earlier, 'Expected an earlier preprocess_comment callback (e.g. WooCommerce type normalisation).' );
	}
}

final class HposCompatibilityIntegrationTest extends WP_UnitTestCase {

	public function test_hpos_compatibility_declared(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			$this->markTestSkipped( 'FeaturesUtil unavailable.' );
		}

		$plugin_id = plugin_basename( UPR_PLUGIN_FILE );
		$features  = FeaturesUtil::get_compatible_features_for_plugin( $plugin_id );
		$this->assertContains( 'custom_order_tables', $features );
	}
}

final class PluginBootstrapIntegrationTest extends WP_UnitTestCase {

	public function test_m1_hooks_registered_when_wc_active(): void {
		$this->assertNotFalse( has_filter( 'pre_comment_approved', array( ReviewModeration::class, 'hold_new_product_reviews' ) ) );
		$this->assertNotFalse( has_filter( 'preprocess_comment', array( GuestSubmissionGuard::class, 'block_guest_product_reviews' ) ) );
	}
}
