<?php
/**
 * M13 operator command surface integration tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Ai\ActionControls;
use UniversalProductReviews\Ai\ActionLedgerRepository;
use UniversalProductReviews\Ai\ActionPolicy;
use UniversalProductReviews\Ai\ActionWorker;
use UniversalProductReviews\Ai\AssessmentRepository;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Ai\ProviderFingerprint;
use UniversalProductReviews\Ai\Recommendation;
use UniversalProductReviews\Ai\RecommendationPolicy;
use UniversalProductReviews\Ai\WouldActReport;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Moderation\CommentListEnhancements;
use UniversalProductReviews\Moderation\CommentListPrefetch;
use UniversalProductReviews\Plugin;
use WP_UnitTestCase;

final class M13OperatorCommandSurfaceIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		delete_option( Options::AI_AUTO_SPAM_ENABLED );
		delete_option( Options::AI_AUTO_SPAM_POLICY_ENABLED );
		delete_option( Options::AI_AUTO_SPAM_SIMULATION_GUARD );
		delete_option( Options::AI_AUTO_SPAM_DRY_RUN );
		delete_option( Options::AI_AUTO_SPAM_KILL_SWITCH );
		delete_option( Options::AI_AUTO_ACTION_BOUNDARY_AT );
		delete_option( Options::ASSESSMENTS_LAST_PURGE_AT );
		CommentListPrefetch::reset_for_tests();
		CommentListEnhancements::reset_for_tests();
		Plugin::reset_for_tests();
		Plugin::init();
	}

	public function test_later_failed_supersedes_older_completed_no_fallback(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$old        = $this->insert_assessment( $comment_id, time() - 10 );
		$this->insert_failed_assessment( $comment_id );

		$resolved = AssessmentRepository::resolve_actionable_assessment( $comment_id );
		$this->assertNull( $resolved['assessment'] );
		$this->assertSame( 'superseded_by_non_completed', $resolved['reason'] );
		$this->assertNull( AssessmentRepository::latest_actionable_assessment_for_comment( $comment_id ) );
		unset( $old );
	}

	public function test_stale_worker_assessment_abstains_without_cas(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$boundary   = time() - 30;
		ActionControls::set_policy_enabled( true );
		ActionControls::set_simulation_guard( true );
		ActionControls::set_master_enabled( true );
		update_option( Options::AI_AUTO_ACTION_BOUNDARY_AT, (string) $boundary, false );

		$old_id = $this->insert_assessment( $comment_id, $boundary + 5 );
		$this->insert_failed_assessment( $comment_id );

		$hooks = 0;
		add_action(
			'wp_set_comment_status',
			static function () use ( &$hooks ): void {
				++$hooks;
			}
		);

		ActionWorker::handle( $comment_id, $old_id );
		$this->assertSame( '0', (string) get_comment( $comment_id )->comment_approved );
		$this->assertSame( 0, $hooks );
		$row = ActionLedgerRepository::get_row( $comment_id, $old_id, ActionPolicy::ACTION_POLICY_VERSION );
		$this->assertSame( ActionLedgerRepository::STATE_ABSTAINED, (string) ( $row['state'] ?? '' ) );
		$this->assertContains(
			(string) ( $row['abstain_reason'] ?? '' ),
			array( 'superseded', 'superseded_by_non_completed' )
		);
	}

	public function test_would_act_parity_and_zero_writes(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$boundary   = time() - 20;
		update_option( Options::AI_AUTO_ACTION_BOUNDARY_AT, (string) $boundary, false );
		$aid = $this->insert_assessment( $comment_id, $boundary + 5 );

		global $wpdb;
		$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}upr_audit" );
		$opt_before   = get_option( Options::ASSESSMENTS_LAST_PURGE_AT, null );

		$report = WouldActReport::build();
		$this->assertTrue( $report['ok'] );
		$this->assertSame( 1, $report['sampled_comments'] );
		$this->assertSame( 1, $report['would_act_total'] );
		$this->assertSame( 1, $report['policy_match_pre_boundary_total'] );

		$canonical = AssessmentRepository::latest_actionable_assessment_for_comment( $comment_id );
		$this->assertNotNull( $canonical );
		$this->assertSame( $aid, (int) $canonical['assessment_id'] );
		$content = ActionPolicy::content_eligible_for_auto_spam( $canonical, get_comment( $comment_id ) );
		$this->assertTrue( $content['ok'] );

		$audit_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}upr_audit" );
		$this->assertSame( $audit_before, $audit_after );
		$this->assertSame( $opt_before, get_option( Options::ASSESSMENTS_LAST_PURGE_AT, null ) );
		$this->assertSame( '0', (string) get_comment( $comment_id )->comment_approved );
	}

	public function test_would_act_boundary_unset_zero_with_pre_boundary(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$this->insert_assessment( $comment_id, time() );
		delete_option( Options::AI_AUTO_ACTION_BOUNDARY_AT );

		$report = WouldActReport::build();
		$this->assertTrue( $report['ok'] );
		$this->assertSame( 0, $report['would_act_total'] );
		$this->assertSame( 'unset', $report['control_state']['boundary'] );
		$this->assertGreaterThan( 0, $report['policy_match_pre_boundary_total'] );
		$this->assertArrayHasKey( 'boundary_unset', $report['would_act_by_reason'] );
	}

	public function test_recommendation_filters_five_actions_and_isolation(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'edit-comments' );
		$GLOBALS['pagenow'] = 'edit-comments.php';
		CommentListEnhancements::on_current_screen( get_current_screen() );

		$product_id = $this->upr_create_product();
		$map        = array(
			Recommendation::ACTION_LIKELY_SPAM        => $this->insert_held_review( $product_id ),
			Recommendation::ACTION_LIKELY_ABUSE       => $this->insert_held_review( $product_id ),
			Recommendation::ACTION_LIKELY_PUBLISHABLE => $this->insert_held_review( $product_id ),
			Recommendation::ACTION_MANDATORY_HUMAN    => $this->insert_held_review( $product_id ),
			Recommendation::ACTION_NEEDS_HUMAN        => $this->insert_held_review( $product_id ),
		);

		$this->insert_assessment_for_action( $map[ Recommendation::ACTION_LIKELY_SPAM ], 'spam' );
		$this->insert_assessment_for_action( $map[ Recommendation::ACTION_LIKELY_ABUSE ], 'abuse' );
		$this->insert_assessment_for_action( $map[ Recommendation::ACTION_LIKELY_PUBLISHABLE ], 'publishable' );
		$this->insert_assessment_for_action( $map[ Recommendation::ACTION_MANDATORY_HUMAN ], 'mandatory' );
		$this->insert_assessment_for_action( $map[ Recommendation::ACTION_NEEDS_HUMAN ], 'needs' );

		foreach ( $map as $action => $cid ) {
			$_GET['upr_recommendation']     = $action;
			$_REQUEST['upr_recommendation'] = $action;
			$comments                       = get_comments(
				array(
					'type'      => 'review',
					'post_type' => 'product',
					'status'    => 'hold',
					'number'    => 50,
					'orderby'   => 'comment_ID',
					'order'     => 'ASC',
				)
			);
			$ids = array_map( static fn( $c ) => (int) $c->comment_ID, $comments );
			$this->assertContains( $cid, $ids, 'filter ' . $action );
			foreach ( $map as $other_action => $other_cid ) {
				if ( $other_action === $action ) {
					continue;
				}
				$this->assertNotContains( $other_cid, $ids, $action . ' must not include ' . $other_action );
			}
		}

		unset( $_GET['upr_recommendation'], $_REQUEST['upr_recommendation'] );
		$all = get_comments(
			array(
				'type'      => 'review',
				'post_type' => 'product',
				'status'    => 'hold',
				'number'    => 50,
				'post_id'   => $product_id,
			)
		);
		$all_ids = array_map( static fn( $c ) => (int) $c->comment_ID, $all );
		foreach ( $map as $cid ) {
			$this->assertContains( $cid, $all_ids );
		}

		$secondary = new \WP_Comment_Query( array( 'comment__in' => array( $map[ Recommendation::ACTION_LIKELY_SPAM ] ) ) );
		$this->assertFalse( CommentListEnhancements::is_comments_list_query( $secondary ) );
	}

	public function test_prefetch_no_recursion_with_recommendation_filter(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );
		set_current_screen( 'edit-comments' );
		$GLOBALS['pagenow']         = 'edit-comments.php';
		$_GET['upr_recommendation'] = Recommendation::ACTION_LIKELY_SPAM;
		CommentListEnhancements::on_current_screen( get_current_screen() );

		$product_id = $this->upr_create_product();
		$cid        = $this->insert_held_review( $product_id );
		$this->insert_assessment_for_action( $cid, 'spam' );

		$the_comments_calls = 0;
		add_filter(
			'the_comments',
			static function ( $comments ) use ( &$the_comments_calls ) {
				++$the_comments_calls;
				if ( $the_comments_calls > 3 ) {
					throw new \RuntimeException( 'the_comments recursion detected' );
				}
				return $comments;
			},
			1,
			1
		);

		CommentListPrefetch::reset_for_tests();
		get_comments(
			array(
				'type'               => 'review',
				'post_type'          => 'product',
				'status'             => 'hold',
				'number'             => 10,
				'upr_recommendation' => Recommendation::ACTION_LIKELY_SPAM,
			)
		);
		$this->assertLessThanOrEqual( 3, $the_comments_calls );
		$this->assertLessThanOrEqual( 5, CommentListPrefetch::query_count() );
	}

	public function test_purge_stamps_last_purge_option(): void {
		AssessmentRepository::purge_due( 1 );
		$this->assertGreaterThan( 0, Options::assessments_last_purge_unix() );
	}

	private function insert_held_review( int $product_id ): int {
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Synthetic Reviewer',
				'comment_author_email' => 'synthetic@example.test',
				'comment_content'      => 'Synthetic held review for M13 tests only.',
				'comment_type'         => 'review',
				'comment_approved'     => '0',
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $comment_id );
		return $comment_id;
	}

	private function insert_assessment( int $comment_id, int $completed_unix ): int {
		global $wpdb;
		$completed = gmdate( 'Y-m-d H:i:s', $completed_unix );
		$tuple     = ActionPolicy::SIMULATION_TUPLE;
		$ok        = $wpdb->insert(
			AssessmentRepository::table(),
			array(
				'schema_version'           => PolicyAllowlist::SCHEMA_VERSION,
				'comment_id'               => $comment_id,
				'mode'                     => 'shadow',
				'state'                    => 'completed',
				'publication_safety_score' => 90,
				'confidence'               => 'high',
				'reason_codes'             => wp_json_encode( array( 'spam_pattern' ) ),
				'policy_version'           => $tuple['assessment_policy_version'],
				'provider_kind'            => $tuple['provider_kind'],
				'provider_fingerprint'     => ProviderFingerprint::for_builtin( $tuple['assessment_policy_version'] ),
				'failure_code'             => null,
				'requested_at'             => $completed,
				'completed_at'             => $completed,
				'retention_due_at'         => gmdate( 'Y-m-d H:i:s', $completed_unix + ( 180 * DAY_IN_SECONDS ) ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$this->assertNotFalse( $ok );
		return (int) $wpdb->insert_id;
	}

	private function insert_failed_assessment( int $comment_id ): int {
		global $wpdb;
		$completed = gmdate( 'Y-m-d H:i:s' );
		$ok        = $wpdb->insert(
			AssessmentRepository::table(),
			array(
				'schema_version'           => PolicyAllowlist::SCHEMA_VERSION,
				'comment_id'               => $comment_id,
				'mode'                     => 'shadow',
				'state'                    => 'failed',
				'publication_safety_score' => null,
				'confidence'               => 'low',
				'reason_codes'             => null,
				'policy_version'           => PolicyAllowlist::POLICY_VERSION,
				'provider_kind'            => 'local',
				'provider_fingerprint'     => ProviderFingerprint::for_builtin( PolicyAllowlist::POLICY_VERSION ),
				'failure_code'             => 'provider_error',
				'requested_at'             => $completed,
				'completed_at'             => $completed,
				'retention_due_at'         => gmdate( 'Y-m-d H:i:s', time() + ( 180 * DAY_IN_SECONDS ) ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$this->assertNotFalse( $ok );
		return (int) $wpdb->insert_id;
	}

	private function insert_assessment_for_action( int $comment_id, string $kind ): void {
		global $wpdb;
		$completed = gmdate( 'Y-m-d H:i:s' );
		$tuple     = ActionPolicy::SIMULATION_TUPLE;
		$row       = array(
			'schema_version'           => PolicyAllowlist::SCHEMA_VERSION,
			'comment_id'               => $comment_id,
			'mode'                     => 'shadow',
			'state'                    => 'completed',
			'publication_safety_score' => 85,
			'confidence'               => 'high',
			'reason_codes'             => wp_json_encode( array( 'spam_pattern' ) ),
			'policy_version'           => $tuple['assessment_policy_version'],
			'provider_kind'            => $tuple['provider_kind'],
			'provider_fingerprint'     => ProviderFingerprint::for_builtin( $tuple['assessment_policy_version'] ),
			'failure_code'             => null,
			'requested_at'             => $completed,
			'completed_at'             => $completed,
			'retention_due_at'         => gmdate( 'Y-m-d H:i:s', time() + ( 180 * DAY_IN_SECONDS ) ),
		);
		switch ( $kind ) {
			case 'abuse':
				$row['reason_codes'] = wp_json_encode( array( 'threat_suspected' ) );
				break;
			case 'publishable':
				$row['confidence']               = 'medium';
				$row['publication_safety_score'] = 20;
				$row['reason_codes']             = null;
				break;
			case 'mandatory':
				$row['reason_codes'] = wp_json_encode( array( 'pii_suspected' ) );
				break;
			case 'needs':
				$row['confidence']               = 'medium';
				$row['publication_safety_score'] = 55;
				$row['reason_codes']             = null;
				break;
		}
		$ok = $wpdb->insert(
			AssessmentRepository::table(),
			$row,
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$this->assertNotFalse( $ok );
		$latest = AssessmentRepository::latest_for_comments( array( $comment_id ) );
		$rec    = RecommendationPolicy::suggest( $latest[ $comment_id ] ?? null );
		$expect = array(
			'spam'        => Recommendation::ACTION_LIKELY_SPAM,
			'abuse'       => Recommendation::ACTION_LIKELY_ABUSE,
			'publishable' => Recommendation::ACTION_LIKELY_PUBLISHABLE,
			'mandatory'   => Recommendation::ACTION_MANDATORY_HUMAN,
			'needs'       => Recommendation::ACTION_NEEDS_HUMAN,
		);
		$this->assertSame( $expect[ $kind ], $rec->action, $kind );
	}
}
