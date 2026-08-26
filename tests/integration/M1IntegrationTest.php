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
		$product_id = $this->factory->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		add_filter(
			'pre_comment_approved',
			static fn() => 'spam',
			5,
			2
		);

		$approved = apply_filters(
			'pre_comment_approved',
			1,
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Spammer',
				'comment_author_email' => 'spam@example.com',
				'comment_content'      => 'Spam review.',
				'comment_type'         => 'review',
			)
		);

		$this->assertSame( 'spam', $approved );
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
		$before_approved   = get_option( 'comment_previously_approved' );

		// Frozen M1 policy protects comment_whitelist. WP 5.5+ aliases that key to
		// comment_previously_approved and emits a get_option deprecation notice.
		$this->setExpectedDeprecated( 'get_option' );
		$before_whitelist = get_option( 'comment_whitelist' );

		$product_id = $this->factory->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		apply_filters(
			'pre_comment_approved',
			1,
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Tester',
				'comment_author_email' => 'tester2@example.com',
				'comment_content'      => 'Another review.',
				'comment_type'         => 'review',
			)
		);

		$this->assertSame( $before_moderation, get_option( 'comment_moderation' ) );
		$this->assertSame( $before_whitelist, get_option( 'comment_whitelist' ) );
		$this->assertSame( $before_approved, get_option( 'comment_previously_approved' ) );
	}
}

final class GuestSubmissionGuardIntegrationTest extends WP_UnitTestCase {

	/**
	 * Guest product-review submission via the WordPress comment pipeline:
	 * WC_Comments::update_comment_type normalises default types to `review`,
	 * then UPR rejects the unauthenticated review before persistence.
	 */
	public function test_guest_product_review_blocked_via_comment_pipeline(): void {
		wp_set_current_user( 0 );

		$product_id = $this->factory->post->create(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'comment_status' => 'open',
			)
		);

		$before_count = (int) get_comments(
			array(
				'post_id' => $product_id,
				'count'   => true,
			)
		);

		$type_after_wc = null;
		add_filter(
			'preprocess_comment',
			static function ( array $commentdata ) use ( &$type_after_wc ): array {
				$type_after_wc = $commentdata['comment_type'] ?? null;
				return $commentdata;
			},
			2
		);

		// Mimic front-end product review POST so WC normalises comment_type.
		$_POST['comment_post_ID'] = (string) $product_id;
		$_POST['rating']          = '5';
		$_POST['comment']         = 'Guest review attempt via pipeline.';

		$exception = null;
		try {
			wp_new_comment(
				array(
					'comment_post_ID'      => $product_id,
					'comment_author'       => 'Guest',
					'comment_author_email' => 'guest@example.com',
					'comment_author_url'   => '',
					'comment_content'      => 'Guest review attempt via pipeline.',
					'comment_type'         => 'comment',
					'user_ID'              => 0,
				),
				true
			);
		} catch ( \WPDieException $e ) {
			$exception = $e;
		} finally {
			unset( $_POST['comment_post_ID'], $_POST['rating'], $_POST['comment'] );
		}

		$this->assertInstanceOf( \WPDieException::class, $exception );
		$this->assertMatchesRegularExpression( '/logged-in/i', $exception->getMessage() );
		$this->assertSame(
			'review',
			$type_after_wc,
			'WC_Comments::update_comment_type must normalise product comments to review before UPR.'
		);
		$this->assertSame(
			$before_count,
			(int) get_comments(
				array(
					'post_id' => $product_id,
					'count'   => true,
				)
			),
			'Guest product review must not be persisted.'
		);
	}

	/**
	 * Non-review comments on products must pass UPR unchanged via the pipeline.
	 * WooCommerce only rewrites default types when $_POST['comment_post_ID'] is set;
	 * without that front-end marker, comment_type stays `comment` and UPR must not block.
	 */
	public function test_guest_non_review_product_comment_passes_pipeline(): void {
		wp_set_current_user( 0 );

		$product_id = $this->factory->post->create(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'comment_status' => 'open',
			)
		);

		unset( $_POST['comment_post_ID'], $_POST['rating'], $_POST['comment'] );

		$comment_id = wp_new_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Guest',
				'comment_author_email' => 'guest-comment@example.com',
				'comment_author_url'   => '',
				'comment_content'      => 'Non-review product comment.',
				'comment_type'         => 'comment',
				'user_ID'              => 0,
				'comment_approved'     => 1,
			),
			true
		);

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		$comment = get_comment( $comment_id );
		$this->assertNotFalse( $comment );
		$this->assertSame( 'comment', $comment->comment_type );
		$this->assertSame( (string) $product_id, $comment->comment_post_ID );
	}

	public function test_guest_blog_comment_passes_pipeline(): void {
		wp_set_current_user( 0 );

		$post_id = $this->factory->post->create(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'comment_status' => 'open',
			)
		);

		$comment_id = wp_new_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Reader',
				'comment_author_email' => 'reader-pipeline@example.com',
				'comment_author_url'   => '',
				'comment_content'      => 'Ordinary blog comment via pipeline.',
				'comment_type'         => 'comment',
				'user_ID'              => 0,
				'comment_approved'     => 1,
			),
			true
		);

		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		$comment = get_comment( $comment_id );
		$this->assertNotFalse( $comment );
		$this->assertSame( 'comment', $comment->comment_type );
		$this->assertSame( (string) $post_id, $comment->comment_post_ID );
	}

	public function test_wc_update_comment_type_registered_before_upr_guard(): void {
		$wc_priority = has_filter( 'preprocess_comment', array( 'WC_Comments', 'update_comment_type' ) );
		$upr_priority = has_filter(
			'preprocess_comment',
			array( GuestSubmissionGuard::class, 'block_guest_product_reviews' )
		);

		$this->assertNotFalse(
			$wc_priority,
			'Expected WC_Comments::update_comment_type on preprocess_comment (WC 11.0.1 registers at priority 1 via add_action).'
		);
		$this->assertSame( 1, (int) $wc_priority );
		$this->assertSame( GuestSubmissionGuard::FILTER_PRIORITY, (int) $upr_priority );
		$this->assertLessThan( (int) $upr_priority, (int) $wc_priority );
	}
}

final class HposCompatibilityIntegrationTest extends WP_UnitTestCase {

	public function test_hpos_compatibility_declared(): void {
		if ( ! class_exists( FeaturesUtil::class ) ) {
			$this->markTestSkipped( 'FeaturesUtil unavailable.' );
		}

		$plugin_id = 'universal-product-reviews/universal-product-reviews.php';
		$features  = FeaturesUtil::get_compatible_features_for_plugin( $plugin_id );

		$compatible = is_array( $features ) && isset( $features['compatible'] ) && is_array( $features['compatible'] )
			? $features['compatible']
			: (array) $features;

		$this->assertContains( 'custom_order_tables', $compatible );
	}
}

final class PluginBootstrapIntegrationTest extends WP_UnitTestCase {

	public function test_m1_hooks_registered_when_wc_active(): void {
		$this->assertNotFalse( has_filter( 'pre_comment_approved', array( ReviewModeration::class, 'hold_new_product_reviews' ) ) );
		$this->assertNotFalse( has_filter( 'preprocess_comment', array( GuestSubmissionGuard::class, 'block_guest_product_reviews' ) ) );
	}
}
