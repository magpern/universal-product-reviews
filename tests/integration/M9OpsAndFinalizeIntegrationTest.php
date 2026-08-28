<?php
/**
 * M9 integration: rate-window reset, failed-insert claim retention, claim-clear atomicity.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Ai\AssessmentClaimsRepository;
use UniversalProductReviews\Ai\AssessmentRepository;
use UniversalProductReviews\Ai\AssessmentWorker;
use UniversalProductReviews\Ai\ModerationOpsRepository;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Schema;
use UniversalProductReviews\Plugin;
use WP_UnitTestCase;

final class M9OpsAndFinalizeIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		delete_option( Options::LOCAL_AI_SHADOW_ENABLED );
		AssessmentRepository::set_force_insert_fail_for_tests( false );
		AssessmentWorker::set_force_claim_clear_fail_for_tests( false );
		Plugin::reset_for_tests();
		Plugin::init();
	}

	public function tear_down(): void {
		AssessmentRepository::set_force_insert_fail_for_tests( false );
		AssessmentWorker::set_force_claim_clear_fail_for_tests( false );
		delete_option( Options::LOCAL_AI_SHADOW_ENABLED );
		parent::tear_down();
	}

	public function test_expired_full_rate_window_resets_count_to_one(): void {
		global $wpdb;

		$table = ModerationOpsRepository::table();
		$old   = gmdate( 'Y-m-d H:i:s', time() - ( 2 * HOUR_IN_SECONDS ) );

		$wpdb->update(
			$table,
			array(
				'rate_window_started_at' => $old,
				'rate_count'             => ModerationOpsRepository::RATE_LIMIT_PER_HOUR,
				'circuit_open_until'     => null,
				'updated_at'             => $old,
			),
			array( 'id' => Schema::OPS_ROW_ID ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);

		$result = ModerationOpsRepository::try_consume_rate_and_check_circuit();
		$this->assertSame( 'ok', $result );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT rate_count, rate_window_started_at FROM {$table} WHERE id = %d", Schema::OPS_ROW_ID ),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		// Buggy left-to-right order would leave rate_count at 61; correct reset is 1.
		$this->assertSame( 1, (int) $row['rate_count'] );
		$this->assertGreaterThan( $old, (string) $row['rate_window_started_at'] );
	}

	public function test_failed_terminal_insert_keeps_owned_claim(): void {
		$product_id = $this->upr_create_product();
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Reviewer',
				'comment_author_email' => 'reviewer@example.com',
				'comment_content'      => 'Held review for failed-insert claim retention.',
				'comment_type'         => 'review',
				'comment_approved'     => '0',
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		AssessmentRepository::set_force_insert_fail_for_tests( true );

		AssessmentWorker::handle( $comment_id, PolicyAllowlist::POLICY_VERSION );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			)
		);
		$this->assertSame( 0, $count );
		$this->assertTrue(
			AssessmentClaimsRepository::has_active_claim( $comment_id, PolicyAllowlist::POLICY_VERSION )
		);
	}

	public function test_failed_claim_clear_rolls_back_terminal_insert(): void {
		$product_id = $this->upr_create_product();
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Reviewer',
				'comment_author_email' => 'reviewer@example.com',
				'comment_content'      => 'Held review for failed claim-clear atomicity.',
				'comment_type'         => 'review',
				'comment_approved'     => '0',
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, $comment_id );

		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		AssessmentWorker::set_force_claim_clear_fail_for_tests( true );

		AssessmentWorker::handle( $comment_id, PolicyAllowlist::POLICY_VERSION );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			)
		);
		$this->assertSame( 0, $count, 'Terminal insert must roll back when claim clear fails.' );
		$this->assertTrue(
			AssessmentClaimsRepository::has_active_claim( $comment_id, PolicyAllowlist::POLICY_VERSION ),
			'Owned claim must remain recoverable for retry.'
		);
	}
}
