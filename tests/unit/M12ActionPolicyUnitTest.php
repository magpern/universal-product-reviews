<?php
/**
 * M12 ActionPolicy / Options / controls unit coverage.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Ai\ActionControls;
use UniversalProductReviews\Ai\ActionPolicy;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Ai\ProviderFingerprint;
use UniversalProductReviews\Config\Options;

final class M12ActionPolicyUnitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['upr_test_options'] = array();
		$GLOBALS['upr_test_comments'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['upr_test_options']  = array();
		$GLOBALS['upr_test_comments'] = array();
		parent::tearDown();
	}

	public function test_masters_default_off(): void {
		$this->assertFalse( Options::ai_auto_spam_enabled() );
		$this->assertFalse( Options::ai_auto_spam_policy_enabled() );
		$this->assertFalse( Options::ai_auto_spam_simulation_guard_enabled() );
		$this->assertFalse( Options::ai_auto_spam_dry_run() );
		$this->assertFalse( Options::ai_auto_spam_kill_switch() );
		$this->assertSame( 0, Options::ai_auto_action_boundary_unix() );
		$this->assertFalse( Options::is_assessment_strictly_after_auto_action_boundary( time() ) );
	}

	public function test_boundary_equality_abstains_and_missing_fails_closed(): void {
		$now = 1_700_000_000;
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_ACTION_BOUNDARY_AT ] = (string) $now;
		$this->assertFalse( Options::is_assessment_strictly_after_auto_action_boundary( $now ) );
		$this->assertTrue( Options::is_assessment_strictly_after_auto_action_boundary( $now + 1 ) );
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_ACTION_BOUNDARY_AT ] = '0';
		$this->assertFalse( Options::is_assessment_strictly_after_auto_action_boundary( $now + 100 ) );
	}

	public function test_master_off_to_on_refreshes_boundary(): void {
		$before = time() - 100;
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_ACTION_BOUNDARY_AT ] = (string) $before;
		ActionControls::set_master_enabled( true );
		$this->assertTrue( Options::ai_auto_spam_enabled() );
		$this->assertGreaterThan( $before, Options::ai_auto_action_boundary_unix() );
	}

	public function test_eligible_fails_closed_on_masters(): void {
		$assessment = $this->completed_spam_assessment( time() + 10 );
		$r          = ActionPolicy::eligible( $assessment, $this->held_comment() );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'master_off', $r['reason'] );
	}

	public function test_eligible_conjunction_and_exclusions(): void {
		$this->enable_masters();
		$boundary = time() - 60;
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_ACTION_BOUNDARY_AT ] = (string) $boundary;
		$comment = $this->held_comment();

		$ok = ActionPolicy::eligible( $this->completed_spam_assessment( $boundary + 10 ), $comment );
		$this->assertTrue( $ok['ok'], $ok['reason'] );

		$eq = ActionPolicy::eligible( $this->completed_spam_assessment( $boundary ), $comment );
		$this->assertFalse( $eq['ok'] );
		$this->assertSame( 'boundary', $eq['reason'] );

		$abuse = $this->completed_spam_assessment( $boundary + 10 );
		$abuse['reason_codes'] = wp_json_encode( array( 'threat_suspected' ) );
		$r = ActionPolicy::eligible( $abuse, $comment );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'not_likely_spam', $r['reason'] );

		$mand = $this->completed_spam_assessment( $boundary + 10 );
		$mand['reason_codes'] = wp_json_encode( array( 'spam_pattern', 'pii_suspected' ) );
		$r = ActionPolicy::eligible( $mand, $comment );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'mandatory_human', $r['reason'] );

		$low = $this->completed_spam_assessment( $boundary + 10 );
		$low['publication_safety_score'] = 50;
		$r = ActionPolicy::eligible( $low, $comment );
		$this->assertFalse( $r['ok'] );

		$tuple = $this->completed_spam_assessment( $boundary + 10 );
		$tuple['provider_kind'] = 'openai';
		$r = ActionPolicy::eligible( $tuple, $comment );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'tuple_mismatch', $r['reason'] );
	}

	public function test_dry_run_may_skip_master_only(): void {
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_SPAM_POLICY_ENABLED ]   = 'yes';
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_SPAM_SIMULATION_GUARD ] = 'yes';
		$boundary = time() - 10;
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_ACTION_BOUNDARY_AT ] = (string) $boundary;
		$r = ActionPolicy::eligible( $this->completed_spam_assessment( $boundary + 5 ), $this->held_comment(), true );
		$this->assertTrue( $r['ok'], $r['reason'] );
		$r2 = ActionPolicy::eligible( $this->completed_spam_assessment( $boundary + 5 ), $this->held_comment(), false );
		$this->assertFalse( $r2['ok'] );
		$this->assertSame( 'master_off', $r2['reason'] );
	}

	public function test_contract_is_auto_spam_held_technical_only(): void {
		$this->assertSame( 'auto_spam_held_technical', ActionPolicy::CONTRACT_ID );
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/Ai/ActionWorker.php' );
		$this->assertIsString( $src );
		$this->assertStringNotContainsString( "comment_approved = '1'", $src );
		$this->assertStringNotContainsString( 'wp_trash_comment', $src );
		$this->assertStringNotContainsString( 'wp_delete_comment', $src );
		$this->assertStringNotContainsString( 'wp_mail', $src );
		$this->assertStringNotContainsString( 'wp_remote_', $src );
	}

	public function test_hold_to_spam_cas_source_has_no_wp_set_comment_status_call(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/Moderation/HoldToSpamCas.php' );
		$this->assertIsString( $src );
		$this->assertStringNotContainsString( 'wp_set_comment_status()', $src );
		$this->assertStringNotContainsString( 'wp_set_comment_status (', $src );
		$this->assertStringContainsString( "comment_approved = %s WHERE comment_ID = %d AND comment_approved = %s", $src );
		$this->assertStringContainsString( "'spam'", $src );
		$this->assertStringContainsString( "'0'", $src );
		$this->assertStringContainsString( "do_action( 'wp_set_comment_status'", $src );
	}

	/**
	 * @return object Held in-scope comment stub.
	 */
	private function held_comment(): object {
		$c = (object) array(
			'comment_ID'       => 42,
			'comment_post_ID'  => 7,
			'comment_parent'   => 0,
			'comment_type'     => 'review',
			'comment_approved' => '0',
			'user_id'          => 0,
		);
		$GLOBALS['upr_test_post_type'] = 'product';
		$GLOBALS['upr_test_comments'][42] = $c;
		return $c;
	}

	private function enable_masters(): void {
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_SPAM_ENABLED ]          = 'yes';
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_SPAM_POLICY_ENABLED ]   = 'yes';
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_SPAM_SIMULATION_GUARD ] = 'yes';
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_SPAM_KILL_SWITCH ]      = 'no';
	}

	/**
	 * @return array<string, mixed>
	 */
	private function completed_spam_assessment( int $completed_unix ): array {
		return array(
			'comment_id'               => 42,
			'state'                    => 'completed',
			'confidence'               => 'high',
			'publication_safety_score' => 85,
			'reason_codes'             => wp_json_encode( array( 'spam_pattern' ) ),
			'policy_version'           => PolicyAllowlist::POLICY_VERSION,
			'provider_kind'            => 'local',
			'provider_fingerprint'     => ProviderFingerprint::for_builtin( PolicyAllowlist::POLICY_VERSION ),
			'completed_at'             => gmdate( 'Y-m-d H:i:s', $completed_unix ),
		);
	}
}
