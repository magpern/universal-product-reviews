<?php
/**
 * M9 integration: deterministic latest assessment selection.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Ai\AssessmentRepository;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Ai\ProviderFingerprint;
use UniversalProductReviews\Moderation\CommentListEnhancements;
use UniversalProductReviews\Moderation\CommentListPrefetch;
use UniversalProductReviews\Plugin;
use WP_UnitTestCase;

final class M9LatestAssessmentIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		CommentListPrefetch::reset_for_tests();
		Plugin::reset_for_tests();
		Plugin::init();
	}

	public function tear_down(): void {
		CommentListPrefetch::reset_for_tests();
		parent::tear_down();
	}

	public function test_same_second_completed_at_returns_higher_assessment_id_only(): void {
		$product_id  = $this->upr_create_product();
		$comment_a   = $this->insert_held_review( $product_id, 'Review A secret-body-a@example.com token-a-xyz' );
		$comment_b   = $this->insert_held_review( $product_id, 'Review B secret-body-b@example.com token-b-xyz' );
		$completed   = '2026-08-28 12:00:00';

		$id_older = $this->insert_terminal_row( $comment_a, $completed, 'completed', 40, 'low' );
		$id_newer = $this->insert_terminal_row( $comment_a, $completed, 'completed', 90, 'high' );
		$id_other = $this->insert_terminal_row( $comment_b, $completed, 'completed', 55, 'medium' );

		$this->assertGreaterThan( $id_older, $id_newer );
		$this->assertSame( 2, $this->assessment_count_for_comment( $comment_a ) );

		$queries_before = $this->wpdb_query_count();
		$latest         = AssessmentRepository::latest_for_comments( array( $comment_a, $comment_b ) );
		$queries_after  = $this->wpdb_query_count();

		$this->assertSame( 1, $queries_after - $queries_before, 'Batch latest lookup must be a single query.' );
		$this->assertCount( 2, $latest );
		$this->assertArrayHasKey( $comment_a, $latest );
		$this->assertArrayHasKey( $comment_b, $latest );
		$this->assertSame( $id_newer, (int) $latest[ $comment_a ]['assessment_id'] );
		$this->assertSame( 90, (int) $latest[ $comment_a ]['publication_safety_score'] );
		$this->assertSame( $id_other, (int) $latest[ $comment_b ]['assessment_id'] );

		CommentListPrefetch::reset_for_tests();
		$q_before = CommentListPrefetch::query_count();
		CommentListPrefetch::hydrate( array( $comment_a, $comment_b ) );
		$q_delta = CommentListPrefetch::query_count() - $q_before;
		$this->assertLessThanOrEqual( 5, $q_delta, 'Prefetch batch remains bounded.' );

		$ctx = CommentListPrefetch::get( $comment_a );
		$this->assertIsArray( $ctx );
		$this->assertIsArray( $ctx['ai_assessment'] ?? null );
		$this->assertSame( $id_newer, (int) $ctx['ai_assessment']['assessment_id'] );

		$formatted = $this->format_ai_advisory_via_reflection( $ctx['ai_assessment'] );
		$this->assertStringContainsString( 'completed', $formatted );
		$this->assertStringContainsString( '90', $formatted );
		$this->assertStringNotContainsString( 'secret-body', $formatted );
		$this->assertStringNotContainsString( 'example.com', $formatted );
		$this->assertStringNotContainsString( 'token-a', $formatted );
		$this->assertStringNotContainsString( 'token-b', $formatted );
	}

	private function insert_held_review( int $product_id, string $content ): int {
		$id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Reviewer',
				'comment_author_email' => 'reviewer@example.com',
				'comment_content'      => $content,
				'comment_type'         => 'review',
				'comment_approved'     => '0',
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	private function insert_terminal_row(
		int $comment_id,
		string $completed_at,
		string $state,
		int $score,
		string $confidence
	): int {
		global $wpdb;

		$requested = $completed_at;
		$retention = gmdate( 'Y-m-d H:i:s', strtotime( $completed_at . ' UTC' ) + ( 180 * DAY_IN_SECONDS ) );

		$ok = $wpdb->insert(
			AssessmentRepository::table(),
			array(
				'schema_version'           => PolicyAllowlist::SCHEMA_VERSION,
				'comment_id'               => $comment_id,
				'mode'                     => 'shadow',
				'state'                    => $state,
				'publication_safety_score' => $score,
				'confidence'               => $confidence,
				'reason_codes'             => null,
				'policy_version'           => PolicyAllowlist::POLICY_VERSION,
				'provider_kind'            => 'local',
				'provider_fingerprint'     => ProviderFingerprint::for_builtin(),
				'failure_code'             => null,
				'requested_at'             => $requested,
				'completed_at'             => $completed_at,
				'retention_due_at'         => $retention,
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$this->assertNotFalse( $ok );
		$id = (int) $wpdb->insert_id;
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

	private function wpdb_query_count(): int {
		global $wpdb;
		return isset( $wpdb->num_queries ) ? (int) $wpdb->num_queries : 0;
	}

	/**
	 * @param array<string, mixed> $assessment Assessment row.
	 */
	private function format_ai_advisory_via_reflection( array $assessment ): string {
		$ref = new \ReflectionMethod( CommentListEnhancements::class, 'format_ai_advisory' );
		$ref->setAccessible( true );
		$result = $ref->invoke( null, $assessment );
		$this->assertIsString( $result );
		return $result;
	}
}
