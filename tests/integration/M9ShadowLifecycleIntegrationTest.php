<?php
/**
 * M9 integration: disable+transition interleave and enabled revoke path.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Ai\AssessmentClaimsRepository;
use UniversalProductReviews\Ai\AssessmentRepository;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Plugin;
use WP_UnitTestCase;

final class M9ShadowLifecycleIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	/** @var int */
	private $moderator_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		delete_option( Options::LOCAL_AI_SHADOW_ENABLED );
		Plugin::reset_for_tests();
		Plugin::init();
		$this->moderator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->grant_moderation_caps( $this->moderator_id );
		wp_set_current_user( $this->moderator_id );
	}

	public function tear_down(): void {
		delete_option( Options::LOCAL_AI_SHADOW_ENABLED );
		parent::tear_down();
	}

	public function test_disable_then_transition_clears_claim_without_row_or_audit(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_product_review( $product_id );

		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		$token = AssessmentClaimsRepository::acquire( $comment_id, PolicyAllowlist::POLICY_VERSION );
		$this->assertNotNull( $token );
		$this->assertTrue( AssessmentClaimsRepository::has_active_claim( $comment_id, PolicyAllowlist::POLICY_VERSION ) );

		$assessment_count = $this->assessment_count_for_comment( $comment_id );
		$audit_before     = $this->max_ai_audit_id_for_comment( $comment_id );

		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'no', false );
		wp_set_comment_status( $comment_id, 'approve' );

		$this->assertSame( $assessment_count, $this->assessment_count_for_comment( $comment_id ) );
		$this->assertSame( $audit_before, $this->max_ai_audit_id_for_comment( $comment_id ) );

		$row = AssessmentClaimsRepository::get_row( $comment_id, PolicyAllowlist::POLICY_VERSION );
		$this->assertIsArray( $row );
		$this->assertNull( $row['claim_token'] );
	}

	public function test_enabled_transition_with_active_claim_inserts_skipped_ineligible(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_product_review( $product_id );

		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		$token = AssessmentClaimsRepository::acquire( $comment_id, PolicyAllowlist::POLICY_VERSION );
		$this->assertNotNull( $token );

		wp_set_comment_status( $comment_id, 'spam' );

		$this->assertSame( 1, $this->assessment_count_for_comment( $comment_id ) );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT state, failure_code FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d LIMIT 1',
				$comment_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'skipped', $row['state'] );
		$this->assertSame( 'ineligible_comment', $row['failure_code'] );

		$claim = AssessmentClaimsRepository::get_row( $comment_id, PolicyAllowlist::POLICY_VERSION );
		$this->assertIsArray( $claim );
		$this->assertNull( $claim['claim_token'] );

		$skipped = $this->audit_events_of_type( 'review.ai_assessment_skipped' );
		$this->assertNotEmpty( $skipped );
	}

	private function insert_held_product_review( int $product_id ): int {
		$id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Reviewer',
				'comment_author_email' => 'reviewer@example.com',
				'comment_content'      => 'Held review for shadow lifecycle test.',
				'comment_type'         => 'review',
				'comment_approved'     => '0',
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	private function assessment_count_for_comment( int $comment_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			)
		);
	}

	private function max_ai_audit_id_for_comment( int $comment_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'upr_audit';
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(MAX(id), 0) FROM {$table} WHERE event_type LIKE %s AND payload_json LIKE %s",
				'review.ai_%',
				'%"comment_id":' . $comment_id . '%'
			)
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function audit_events_of_type( string $type ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'upr_audit';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s ORDER BY id ASC", $type ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	private function grant_moderation_caps( int $user_id ): void {
		$user = new \WP_User( $user_id );
		$user->add_cap( 'moderate_comments' );
	}
}
