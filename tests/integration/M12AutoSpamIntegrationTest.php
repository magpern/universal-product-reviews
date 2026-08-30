<?php
/**
 * M12 auto-spam integration: CAS, ledger, dry-run, crash recovery.
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
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Moderation\AiActionOrigin;
use UniversalProductReviews\Moderation\HoldToSpamCas;
use UniversalProductReviews\Moderation\ModerationAudit;
use UniversalProductReviews\Plugin;
use WP_UnitTestCase;

final class M12AutoSpamIntegrationTest extends WP_UnitTestCase {
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
		Plugin::reset_for_tests();
		Plugin::init();
	}

	public function tear_down(): void {
		parent::tear_down();
	}

	public function test_defaults_off_and_confirm_required(): void {
		$this->assertFalse( Options::ai_auto_spam_enabled() );
		$_POST = array();
		$this->assertSame( 'no', \UniversalProductReviews\Admin\SettingsPage::sanitize_auto_spam_master( 'yes' ) );
		$_POST = array( \UniversalProductReviews\Admin\SettingsPage::CONFIRM_ENABLE_AUTO_SPAM => '1' );
		$this->assertSame( 'yes', \UniversalProductReviews\Admin\SettingsPage::sanitize_auto_spam_master( 'yes' ) );
		$this->assertGreaterThan( 0, Options::ai_auto_action_boundary_unix() );
	}

	public function test_happy_path_cas_audit_and_no_historical_backfill(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$boundary   = time() - 30;
		ActionControls::set_policy_enabled( true );
		ActionControls::set_simulation_guard( true );
		ActionControls::set_master_enabled( true );
		update_option( Options::AI_AUTO_ACTION_BOUNDARY_AT, (string) $boundary, false );

		$old_id = $this->insert_assessment( $comment_id, $boundary - 10 );
		ActionWorker::handle( $comment_id, $old_id );
		$row = ActionLedgerRepository::get_row( $comment_id, $old_id, ActionPolicy::ACTION_POLICY_VERSION );
		$this->assertSame( ActionLedgerRepository::STATE_ABSTAINED, (string) ( $row['state'] ?? '' ) );
		$this->assertSame( '0', (string) get_comment( $comment_id )->comment_approved );

		$assessment_id = $this->insert_assessment( $comment_id, $boundary + 5 );
		$hook_count    = 0;
		$audit_events  = array();
		add_action(
			'wp_set_comment_status',
			static function () use ( &$hook_count ): void {
				++$hook_count;
			}
		);
		add_action(
			'upr_test_capture_audit',
			static function ( $event ) use ( &$audit_events ): void {
				$audit_events[] = $event;
			}
		);

		ActionWorker::handle( $comment_id, $assessment_id );
		$this->assertSame( 'spam', (string) get_comment( $comment_id )->comment_approved );
		$this->assertSame( 1, $hook_count );
		$row = ActionLedgerRepository::get_row( $comment_id, $assessment_id, ActionPolicy::ACTION_POLICY_VERSION );
		$this->assertSame( ActionLedgerRepository::STATE_ACTED, (string) ( $row['state'] ?? '' ) );
		$this->assertNotEmpty( $row['ai_cas_committed_at'] ?? null );
	}

	public function test_dry_run_observed_never_cas(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$boundary   = time() - 20;
		ActionControls::set_policy_enabled( true );
		ActionControls::set_simulation_guard( true );
		ActionControls::set_dry_run( true );
		update_option( Options::AI_AUTO_ACTION_BOUNDARY_AT, (string) $boundary, false );

		$id = $this->insert_assessment( $comment_id, $boundary + 5 );
		ActionWorker::handle( $comment_id, $id );
		$row = ActionLedgerRepository::get_row( $comment_id, $id, ActionPolicy::ACTION_POLICY_VERSION );
		$this->assertSame( ActionLedgerRepository::STATE_OBSERVED, (string) ( $row['state'] ?? '' ) );
		$this->assertSame( '1', (string) ( $row['dry_run'] ?? '0' ) );
		$this->assertSame( '0', (string) get_comment( $comment_id )->comment_approved );

		ActionControls::set_master_enabled( true );
		ActionControls::set_dry_run( false );
		ActionWorker::handle( $comment_id, $id );
		$row2 = ActionLedgerRepository::get_row( $comment_id, $id, ActionPolicy::ACTION_POLICY_VERSION );
		$this->assertSame( ActionLedgerRepository::STATE_OBSERVED, (string) ( $row2['state'] ?? '' ) );
		$this->assertSame( '0', (string) get_comment( $comment_id )->comment_approved );
	}

	public function test_ledger_blocks_restore_replay_same_assessment(): void {
		$product_id    = $this->upr_create_product();
		$comment_id    = $this->insert_held_review( $product_id );
		$boundary      = time() - 20;
		ActionControls::set_policy_enabled( true );
		ActionControls::set_simulation_guard( true );
		ActionControls::set_master_enabled( true );
		update_option( Options::AI_AUTO_ACTION_BOUNDARY_AT, (string) $boundary, false );
		$id = $this->insert_assessment( $comment_id, $boundary + 5 );
		ActionWorker::handle( $comment_id, $id );
		$this->assertSame( 'spam', (string) get_comment( $comment_id )->comment_approved );

		global $wpdb;
		$wpdb->update( $wpdb->comments, array( 'comment_approved' => '0' ), array( 'comment_ID' => $comment_id ) );
		clean_comment_cache( $comment_id );
		ActionWorker::handle( $comment_id, $id );
		$this->assertSame( '0', (string) get_comment( $comment_id )->comment_approved );
		$row = ActionLedgerRepository::get_row( $comment_id, $id, ActionPolicy::ACTION_POLICY_VERSION );
		$this->assertSame( ActionLedgerRepository::STATE_ACTED, (string) ( $row['state'] ?? '' ) );
	}

	public function test_human_status_change_wins_before_cas(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$boundary   = time() - 20;
		ActionControls::set_policy_enabled( true );
		ActionControls::set_simulation_guard( true );
		ActionControls::set_master_enabled( true );
		update_option( Options::AI_AUTO_ACTION_BOUNDARY_AT, (string) $boundary, false );
		$id = $this->insert_assessment( $comment_id, $boundary + 5 );

		global $wpdb;
		$wpdb->update( $wpdb->comments, array( 'comment_approved' => '1' ), array( 'comment_ID' => $comment_id ) );
		clean_comment_cache( $comment_id );

		ActionWorker::handle( $comment_id, $id );
		$row = ActionLedgerRepository::get_row( $comment_id, $id, ActionPolicy::ACTION_POLICY_VERSION );
		$this->assertSame( ActionLedgerRepository::STATE_ABSTAINED, (string) ( $row['state'] ?? '' ) );
		$this->assertContains( (string) ( $row['abstain_reason'] ?? '' ), array( 'not_hold', 'ineligible_comment', 'status_changed' ) );
		$this->assertSame( '1', (string) get_comment( $comment_id )->comment_approved );
	}

	public function test_concurrent_lease_and_stale_token(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$id         = $this->insert_assessment( $comment_id, time() );
		$policy     = ActionPolicy::ACTION_POLICY_VERSION;
		$t1         = ActionLedgerRepository::acquire_processing( $comment_id, $id, $policy, 'w1' );
		$t2         = ActionLedgerRepository::acquire_processing( $comment_id, $id, $policy, 'w2' );
		$this->assertNotNull( $t1 );
		$this->assertNull( $t2 );

		$ok = ActionLedgerRepository::transition_token_matched(
			$comment_id,
			$id,
			$policy,
			'wrong-token',
			ActionLedgerRepository::STATE_PROCESSING,
			ActionLedgerRepository::STATE_ABSTAINED,
			'x'
		);
		$this->assertFalse( $ok );
		$row = ActionLedgerRepository::get_row( $comment_id, $id, $policy );
		$this->assertSame( ActionLedgerRepository::STATE_PROCESSING, (string) ( $row['state'] ?? '' ) );
	}

	public function test_crash_after_cas_unknown_never_replays_hooks(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$boundary   = time() - 20;
		ActionControls::set_policy_enabled( true );
		ActionControls::set_simulation_guard( true );
		ActionControls::set_master_enabled( true );
		update_option( Options::AI_AUTO_ACTION_BOUNDARY_AT, (string) $boundary, false );
		$id = $this->insert_assessment( $comment_id, $boundary + 5 );

		$policy = ActionPolicy::ACTION_POLICY_VERSION;
		$token  = ActionLedgerRepository::acquire_processing( $comment_id, $id, $policy, 'crash-sim' );
		$this->assertNotNull( $token );
		$affected = HoldToSpamCas::cas_write( $comment_id );
		$this->assertSame( 1, $affected );
		clean_comment_cache( $comment_id );
		$this->assertSame( 'spam', (string) get_comment( $comment_id )->comment_approved );
		global $wpdb;
		$table = ActionLedgerRepository::table();
		$now   = current_time( 'mysql', true );
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET state = %s, ai_cas_committed_at = %s, updated_at = %s WHERE comment_id = %d AND assessment_id = %d AND action_policy_version = %s",
				ActionLedgerRepository::STATE_CAS_SUCCEEDED,
				$now,
				$now,
				$comment_id,
				$id,
				$policy
			)
		);

		$hooks = 0;
		add_action(
			'wp_set_comment_status',
			static function () use ( &$hooks ): void {
				++$hooks;
			}
		);
		ActionWorker::recover_unknown_after_crash();
		$row = ActionLedgerRepository::get_row( $comment_id, $id, $policy );
		$this->assertSame( ActionLedgerRepository::STATE_UNKNOWN_AFTER_CRASH, (string) ( $row['state'] ?? '' ) );
		$this->assertSame( 0, $hooks );
		clean_comment_cache( $comment_id );
		$this->assertSame( 'spam', (string) get_comment( $comment_id )->comment_approved );

		ActionWorker::handle( $comment_id, $id );
		$this->assertSame( 0, $hooks );
		clean_comment_cache( $comment_id );
		$this->assertSame( 'spam', (string) get_comment( $comment_id )->comment_approved );
	}

	public function test_disable_mid_claim_clears_without_terminal(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$id         = $this->insert_assessment( $comment_id, time() );
		$policy     = ActionPolicy::ACTION_POLICY_VERSION;
		$token      = ActionLedgerRepository::acquire_processing( $comment_id, $id, $policy, 'x' );
		$this->assertNotNull( $token );
		ActionControls::set_master_enabled( false );
		$row = ActionLedgerRepository::get_row( $comment_id, $id, $policy );
		$this->assertTrue( null === ( $row['state'] ?? null ) || '' === (string) ( $row['state'] ?? '' ) );
		$this->assertEmpty( $row['lease_token'] ?? null );
	}

	public function test_cas_zero_rows_leaves_no_acted(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$n          = HoldToSpamCas::cas_write( $comment_id );
		$this->assertSame( 1, $n );
		$n2 = HoldToSpamCas::cas_write( $comment_id );
		$this->assertSame( 0, $n2 );
	}

	public function test_ai_action_origin_audit_event(): void {
		$this->assertSame( 'review.ai_auto_spam', ModerationAudit::EVENT_AI_AUTO_SPAM );
		$event = null;
		AiActionOrigin::run(
			static function () use ( &$event ): void {
				$event = ModerationAudit::classify_event( 'spam' );
			}
		);
		$this->assertSame( ModerationAudit::EVENT_AI_AUTO_SPAM, $event );
		$this->assertNotSame( ModerationAudit::EVENT_AI_AUTO_SPAM, ModerationAudit::classify_event( 'spam' ) );
	}

	private function insert_held_review( int $product_id ): int {
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Synthetic Reviewer',
				'comment_author_email' => 'synthetic@example.test',
				'comment_content'      => 'Synthetic held review for M12 tests only.',
				'comment_type'         => 'review',
				'comment_approved'     => '0',
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $comment_id );
		return (int) $comment_id;
	}

	private function insert_assessment( int $comment_id, int $completed_unix ): int {
		global $wpdb;
		$completed = gmdate( 'Y-m-d H:i:s', $completed_unix );
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
				'policy_version'           => PolicyAllowlist::POLICY_VERSION,
				'provider_kind'            => 'local',
				'provider_fingerprint'     => ProviderFingerprint::for_builtin(),
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
}
