<?php
/**
 * M13 operator AI command surface — unit coverage.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Admin\Diagnostics\DiagnosticsService;
use UniversalProductReviews\Admin\SupportExport;
use UniversalProductReviews\Ai\ActionPolicy;
use UniversalProductReviews\Ai\ProviderFingerprint;
use UniversalProductReviews\Ai\Recommendation;
use UniversalProductReviews\Ai\RecommendationPolicy;
use UniversalProductReviews\Ai\WouldActReport;
use UniversalProductReviews\CLI\Commands;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Database\Schema;

final class M13OperatorCommandSurfaceUnitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['upr_test_options']                 = array();
		$GLOBALS['upr_test_comments']                = array();
		$GLOBALS['upr_test_caps']                    = array();
		$GLOBALS['upr_test_assessment_comment_ids']  = array();
		unset( $GLOBALS['upr_test_retention_due_count'], $GLOBALS['upr_test_missing_tables'] );
	}

	protected function tearDown(): void {
		$GLOBALS['upr_test_options']                = array();
		$GLOBALS['upr_test_comments']               = array();
		$GLOBALS['upr_test_caps']                   = array();
		$GLOBALS['upr_test_assessment_comment_ids'] = array();
		unset( $GLOBALS['upr_test_retention_due_count'], $GLOBALS['upr_test_missing_tables'] );
		parent::tearDown();
	}

	public function test_masters_remain_default_off(): void {
		$this->assertFalse( Options::ai_auto_spam_enabled() );
		$this->assertFalse( Options::ai_auto_spam_policy_enabled() );
		$this->assertFalse( Options::ai_auto_spam_simulation_guard_enabled() );
	}

	public function test_content_eligible_ignores_masters_requires_boundary(): void {
		$boundary = time() - 60;
		$GLOBALS['upr_test_options'][ Options::AI_AUTO_ACTION_BOUNDARY_AT ] = (string) $boundary;
		$comment = $this->held_comment();
		$ok      = ActionPolicy::content_eligible_for_auto_spam( $this->completed_spam_assessment( $boundary + 10 ), $comment );
		$this->assertTrue( $ok['ok'], $ok['reason'] );

		unset( $GLOBALS['upr_test_options'][ Options::AI_AUTO_ACTION_BOUNDARY_AT ] );
		$unset = ActionPolicy::content_eligible_for_auto_spam( $this->completed_spam_assessment( time() ), $comment );
		$this->assertFalse( $unset['ok'] );
		$this->assertSame( 'boundary_unset', $unset['reason'] );
	}

	public function test_policy_match_pre_boundary_without_boundary(): void {
		$comment = $this->held_comment();
		$pre     = ActionPolicy::policy_match_pre_boundary( $this->completed_spam_assessment( time() ), $comment );
		$this->assertTrue( $pre['ok'], $pre['reason'] );
		$content = ActionPolicy::content_eligible_for_auto_spam( $this->completed_spam_assessment( time() ), $comment );
		$this->assertFalse( $content['ok'] );
	}

	public function test_would_act_zero_when_boundary_unset_pre_boundary_may_count(): void {
		$GLOBALS['upr_test_assessment_comment_ids'] = array( 42 );
		$GLOBALS['upr_test_comments'][42]           = $this->held_comment();
		// Force latest_for_comments via stub: empty map → abstention; use reflection-free path.
		// Empty latest assessments → content_abstentions no_assessment; would_act 0.
		$report = WouldActReport::build();
		$this->assertTrue( $report['ok'] );
		$this->assertSame( 0, $report['would_act_total'] );
		$this->assertSame( 'unset', $report['control_state']['boundary'] );
		$this->assertArrayHasKey( 'policy_match_pre_boundary_total', $report );
		$this->assertStringContainsString( 'not would-act', strtolower( (string) $report['copy']['pre_boundary'] ) );
		$this->assertStringNotContainsString( 'would act', strtolower( (string) $report['copy']['pre_boundary'] ) );
	}

	public function test_would_act_fail_closed_empty_maps(): void {
		$ref = new \ReflectionClass( WouldActReport::class );
		$m   = $ref->getMethod( 'empty_ok_false' );
		/** @var array<string, mixed> $out */
		$out = $m->invoke( null, 'query_failed' );
		$this->assertFalse( $out['ok'] );
		$this->assertSame( 'query_failed', $out['error_code'] );
		$this->assertSame( 0, $out['would_act_total'] );
		$this->assertSame( array(), $out['would_act_by_reason'] );
		$this->assertSame( 0, $out['policy_match_pre_boundary_total'] );
		$this->assertSame( array(), $out['policy_match_pre_boundary_by_reason'] );
	}

	public function test_recommendation_compiler_allowlist_and_injection_rejection(): void {
		foreach ( Recommendation::ACTIONS as $action ) {
			$compiled = RecommendationPolicy::compile_held_filter_sql( $action );
			$this->assertIsArray( $compiled, $action );
			$this->assertArrayHasKey( 'fragment', $compiled );
			$this->assertArrayHasKey( 'args', $compiled );
			$this->assertStringContainsString( 'EXISTS', $compiled['fragment'] );
			$this->assertStringNotContainsString( 'DROP TABLE', $compiled['fragment'] );
		}
		$this->assertNull( RecommendationPolicy::compile_held_filter_sql( 'likely_spam; DROP TABLE wp_comments;--' ) );
		$this->assertNull( RecommendationPolicy::compile_held_filter_sql( 'not_a_real_action' ) );
		$evil = RecommendationPolicy::compile_held_filter_sql( Recommendation::ACTION_LIKELY_SPAM );
		$this->assertIsArray( $evil );
		foreach ( $evil['args'] as $arg ) {
			$this->assertIsScalar( $arg );
			$this->assertStringNotContainsString( 'DROP', (string) $arg );
		}
	}

	public function test_d21_threshold_matrix(): void {
		$GLOBALS['upr_test_options'][ Migrator::OPTION_VERSION ] = Schema::DB_VERSION;
		unset( $GLOBALS['upr_test_missing_tables'] );

		$GLOBALS['upr_test_retention_due_count'] = 0;
		unset( $GLOBALS['upr_test_options'][ Options::ASSESSMENTS_LAST_PURGE_AT ] );
		$r = DiagnosticsService::check_d21();
		$this->assertSame( 'information', $r['status'] );

		$GLOBALS['upr_test_retention_due_count'] = 50;
		$r = DiagnosticsService::check_d21();
		$this->assertSame( 'warning', $r['status'] );

		$GLOBALS['upr_test_retention_due_count'] = 101;
		$r = DiagnosticsService::check_d21();
		$this->assertSame( 'critical', $r['status'] );

		$GLOBALS['upr_test_retention_due_count']                   = 0;
		$GLOBALS['upr_test_options'][ Options::ASSESSMENTS_LAST_PURGE_AT ] = (string) ( time() - ( 40 * HOUR_IN_SECONDS ) );
		$r = DiagnosticsService::check_d21();
		$this->assertSame( 'warning', $r['status'] );

		$GLOBALS['upr_test_options'][ Options::ASSESSMENTS_LAST_PURGE_AT ] = (string) ( time() - ( 80 * HOUR_IN_SECONDS ) );
		$r = DiagnosticsService::check_d21();
		$this->assertSame( 'critical', $r['status'] );

		$GLOBALS['upr_test_missing_tables'] = true;
		$r = DiagnosticsService::check_d21();
		$this->assertSame( 'unavailable', $r['status'] );
	}

	public function test_support_export_golden_v1_shape(): void {
		$GLOBALS['upr_test_options'][ Migrator::OPTION_VERSION ] = Schema::DB_VERSION;
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMAILS_ENABLED ]  = 'no';
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMERGENCY_PAUSE ] = 'no';
		unset( $GLOBALS['upr_test_missing_tables'] );

		$payload = SupportExport::build();
		$this->assertSame( 'upr-support-export/v1', $payload['schema_version'] );
		$this->assertSame( SupportExport::SCHEMA_VERSION, $payload['schema_version'] );

		$required = array(
			'schema_version',
			'plugin_version',
			'db_version_option',
			'schema_target',
			'invitation_emails_enabled',
			'emergency_pause',
			'scheduling_boundary_set',
			'window_days',
			'diagnostics',
			'aggregates',
			'last_reconcile',
		);
		foreach ( $required as $key ) {
			$this->assertArrayHasKey( $key, $payload, 'SupportExport v1 golden key missing: ' . $key );
		}
		$this->assertSame( 7, $payload['window_days'] );
		$this->assertIsArray( $payload['diagnostics'] );
		$ids = array_column( $payload['diagnostics'], 'id' );
		$this->assertContains( 'D21', $ids, 'SupportExport diagnostics drifted — expected D21 without SupportExport.php edits' );
		$this->assertCount( 21, $payload['diagnostics'], 'SupportExport diagnostics count drift (schema still v1)' );

		$json = wp_json_encode( $payload );
		$this->assertIsString( $json );
		foreach ( array( 'review body', 'api_key', 'sk-', 'lease_token', '@example.com', 'prompt', 'Bearer ' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}

		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/SupportExport.php' );
		$this->assertIsString( $src );
		$this->assertStringContainsString( 'upr-support-export/v1', $src );
		$this->assertStringNotContainsString( 'upr-support-export/v2', $src );
	}

	public function test_cli_require_user_fail_closed(): void {
		if ( ! class_exists( 'WP_CLI', false ) ) {
			eval( // phpcs:ignore Squiz.PHP.Eval.Discouraged
				'class WP_CLI {
					public static $errors = array();
					public static $logs = array();
					public static function error( $message, $exit = true ) {
						self::$errors[] = (string) $message;
						if ( $exit ) {
							throw new \\RuntimeException( "WP_CLI_ERROR:" . $message );
						}
					}
					public static function log( $message ) { self::$logs[] = (string) $message; }
					public static function success( $message ) {}
					public static function add_command( $name, $callable ) {}
				}'
			);
		}

		$ref = new \ReflectionClass( Commands::class );
		$m   = $ref->getMethod( 'require_manage_woocommerce_user' );

		try {
			$m->invoke( null, array() );
			$this->fail( 'expected missing --user error' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Missing required --user', $e->getMessage() );
		}

		try {
			$m->invoke( null, array( 'user' => '999999' ) );
			$this->fail( 'expected invalid user error' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Invalid --user', $e->getMessage() );
		}
	}

	public function test_privacy_forbidden_fields_absent_from_would_act_and_overview_sources(): void {
		foreach (
			array(
				dirname( __DIR__, 2 ) . '/src/Ai/WouldActReport.php',
				dirname( __DIR__, 2 ) . '/src/Admin/OverviewPage.php',
				dirname( __DIR__, 2 ) . '/src/CLI/Commands.php',
			) as $path
		) {
			$src = file_get_contents( $path );
			$this->assertIsString( $src );
			$this->assertStringNotContainsString( 'comment_content', $src );
			$this->assertStringNotContainsString( 'comment_author_email', $src );
			$this->assertStringNotContainsString( 'lease_token', $src );
		}
	}

	public function test_public_contracts_has_no_as_job_names(): void {
		$path = dirname( __DIR__, 2 ) . '/docs/integration/public-contracts.md';
		$src  = file_get_contents( $path );
		$this->assertIsString( $src );
		$this->assertStringNotContainsString( 'upr_auto_spam_action', $src );
		$this->assertStringNotContainsString( 'upr_purge_moderation_assessments', $src );
		$this->assertStringNotContainsString( 'upr_recover_auto_spam_crash', $src );
	}

	/**
	 * @return object
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
		$GLOBALS['upr_test_post_type']   = 'product';
		$GLOBALS['upr_test_comments'][42] = $c;
		return $c;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function completed_spam_assessment( int $completed_unix ): array {
		$tuple = ActionPolicy::SIMULATION_TUPLE;
		return array(
			'assessment_id'             => 1,
			'comment_id'                => 42,
			'state'                     => 'completed',
			'provider_kind'             => $tuple['provider_kind'],
			'policy_version'            => $tuple['assessment_policy_version'],
			'provider_fingerprint'      => ProviderFingerprint::for_builtin( $tuple['assessment_policy_version'] ),
			'confidence'                => 'high',
			'publication_safety_score'  => 90,
			'reason_codes'              => wp_json_encode( array( 'spam_pattern' ) ),
			'completed_at'              => gmdate( 'Y-m-d H:i:s', $completed_unix ),
		);
	}
}
