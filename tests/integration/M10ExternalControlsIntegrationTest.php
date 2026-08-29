<?php
/**
 * M10 WP4 integration: enablement acks, re-analysis caps, test-connection quota.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Admin\SettingsPage;
use UniversalProductReviews\Ai\AssessmentClaimsRepository;
use UniversalProductReviews\Ai\AssessmentLifecycle;
use UniversalProductReviews\Ai\AssessmentRepository;
use UniversalProductReviews\Ai\ExternalQuotaRepository;
use UniversalProductReviews\Ai\ModerationOpsRepository;
use UniversalProductReviews\Ai\OpenAi\CredentialResolver;
use UniversalProductReviews\Ai\OpenAi\ExternalAiTestConnection;
use UniversalProductReviews\Ai\OpenAi\OpenAiConstants;
use UniversalProductReviews\Ai\OpenAi\OpenAiHttpResult;
use UniversalProductReviews\Ai\OpenAi\OpenAiTransport;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Schema;
use UniversalProductReviews\Plugin;
use WP_UnitTestCase;

final class M10ExternalControlsIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		delete_option( Options::AI_EXTERNAL_ENABLED );
		delete_option( Options::AI_PROVIDER );
		delete_option( Options::LOCAL_AI_SHADOW_ENABLED );
		CredentialResolver::set_test_credential( null );
		$_POST = array();
		Plugin::reset_for_tests();
		Plugin::init();
	}

	public function tear_down(): void {
		CredentialResolver::set_test_credential( null );
		$_POST = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	public function test_enable_external_requires_server_acks(): void {
		$_POST = array();
		$this->assertSame( 'no', SettingsPage::sanitize_ai_external( 'yes' ) );

		$_POST = array(
			SettingsPage::CONFIRM_ENABLE_AI_EXTERNAL => '1',
			SettingsPage::ACK_OPENAI_PRIVACY         => '1',
			SettingsPage::ACK_OPENAI_RETENTION       => '1',
			SettingsPage::ACK_REVIEW_MAY_PII         => '1',
		);
		$this->assertSame( 'yes', SettingsPage::sanitize_ai_external( 'yes' ) );
	}

	public function test_openai_reanalysis_denied_for_moderate_comments_only(): void {
		$product_id = $this->upr_create_product();
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Reviewer',
				'comment_author_email' => 'r@example.com',
				'comment_content'      => 'Held review needing reanalysis permission check.',
				'comment_type'         => 'review',
				'comment_approved'     => '0',
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $comment_id );

		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		update_option( Options::AI_PROVIDER, 'openai', false );
		update_option( Options::AI_EXTERNAL_ENABLED, 'yes', false );

		$user_id = $this->factory()->user->create( array( 'role' => 'editor' ) );
		$user    = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'moderate_comments' );
		$user->remove_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		$this->assertFalse( AssessmentLifecycle::request_reanalysis( (int) $comment_id ) );

		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );
		delete_transient( 'upr_reanalysis_' . $comment_id );
		$this->assertTrue( AssessmentLifecycle::request_reanalysis( (int) $comment_id ) );
	}

	public function test_openai_reanalysis_refused_when_external_disabled(): void {
		$product_id = $this->upr_create_product();
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Reviewer',
				'comment_author_email' => 'r@example.com',
				'comment_content'      => 'Held review with openai selected but external off.',
				'comment_type'         => 'review',
				'comment_approved'     => '0',
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $comment_id );

		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		update_option( Options::AI_PROVIDER, 'openai', false );
		update_option( Options::AI_EXTERNAL_ENABLED, 'no', false );

		$user_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$user->add_cap( 'manage_woocommerce' );
		$user->add_cap( 'moderate_comments' );
		wp_set_current_user( $user_id );

		$this->assertFalse( AssessmentLifecycle::request_reanalysis( (int) $comment_id ) );
		$this->assertFalse( (bool) get_transient( 'upr_reanalysis_' . $comment_id ) );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			)
		);
		$this->assertSame( 0, $count );

		$audit = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}upr_audit WHERE event_type = %s",
				'review.ai_reanalysis_requested'
			)
		);
		$this->assertSame( 0, $audit );
	}

	public function test_external_disable_clears_active_openai_claims(): void {
		update_option( Options::AI_PROVIDER, 'openai', false );
		update_option( Options::AI_EXTERNAL_ENABLED, 'yes', false );

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $this->upr_create_product(),
				'comment_author'       => 'Reviewer',
				'comment_author_email' => 'r@example.com',
				'comment_content'      => 'Held review with an active openai claim.',
				'comment_type'         => 'review',
				'comment_approved'     => '0',
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $comment_id );

		$token = AssessmentClaimsRepository::acquire( (int) $comment_id, PolicyAllowlist::POLICY_VERSION, 'openai' );
		$this->assertNotNull( $token );
		$row = AssessmentClaimsRepository::get_row( (int) $comment_id, PolicyAllowlist::POLICY_VERSION );
		$this->assertIsArray( $row );
		$this->assertSame( 'openai', $row['claim_provider_kind'] );
		$this->assertTrue(
			AssessmentClaimsRepository::has_active_claim( (int) $comment_id, PolicyAllowlist::POLICY_VERSION )
		);

		$returned = SettingsPage::sanitize_ai_external( 'no' );
		$this->assertSame( 'no', $returned );
		$this->assertFalse(
			AssessmentClaimsRepository::has_active_claim( (int) $comment_id, PolicyAllowlist::POLICY_VERSION )
		);
	}

	public function test_local_claim_survives_provider_change_and_external_disable(): void {
		update_option( Options::AI_PROVIDER, 'local', false );
		update_option( Options::AI_EXTERNAL_ENABLED, 'yes', false );

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $this->upr_create_product(),
				'comment_author'       => 'Reviewer',
				'comment_author_email' => 'r@example.com',
				'comment_content'      => 'Held review with an in-flight local claim.',
				'comment_type'         => 'review',
				'comment_approved'     => '0',
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $comment_id );

		$token = AssessmentClaimsRepository::acquire( (int) $comment_id, PolicyAllowlist::POLICY_VERSION, 'local' );
		$this->assertNotNull( $token );

		// Operator later selects openai, then disables external AI.
		update_option( Options::AI_PROVIDER, 'openai', false );
		$returned = SettingsPage::sanitize_ai_external( 'no' );
		$this->assertSame( 'no', $returned );

		$this->assertTrue(
			AssessmentClaimsRepository::has_active_claim( (int) $comment_id, PolicyAllowlist::POLICY_VERSION ),
			'In-flight local claim must survive provider change + external disable'
		);
		$row = AssessmentClaimsRepository::get_row( (int) $comment_id, PolicyAllowlist::POLICY_VERSION );
		$this->assertIsArray( $row );
		$this->assertSame( $token, $row['claim_token'] );
		$this->assertSame( 'local', $row['claim_provider_kind'] );
	}

	public function test_test_connection_consumes_external_quota_not_m9_rate(): void {
		global $wpdb;

		update_option( Options::AI_EXTERNAL_ENABLED, 'yes', false );
		CredentialResolver::set_test_credential( 'sk-test', CredentialResolver::SOURCE_CONSTANT );

		$ops_before = ModerationOpsRepository::summarize();
		$rate_before = (int) ( $ops_before['rate_count'] ?? 0 );

		$inner = wp_json_encode(
			array(
				'state'                    => 'indeterminate',
				'publication_safety_score' => 0,
				'confidence'               => 'low',
				'reason_codes'             => array( 'insufficient_signal' ),
			)
		);
		$transport = new class( (string) $inner ) implements OpenAiTransport {
			private string $inner;
			public string $sent = '';
			public function __construct( string $inner ) {
				$this->inner = $inner;
			}
			public function post( string $url, array $headers, string $json_body, int $timeout_seconds ): OpenAiHttpResult {
				$this->sent = $json_body;
				return new OpenAiHttpResult(
					200,
					wp_json_encode(
						array(
							'status'      => 'completed',
							'output_text' => $this->inner,
						)
					) ?: '{}'
				);
			}
		};

		$code = ExternalAiTestConnection::run( $transport );
		$this->assertSame( 'connection_ok', $code );

		$quota = ExternalQuotaRepository::summarize();
		$this->assertSame( 1, (int) $quota['day_count'] );

		$ops_after = ModerationOpsRepository::summarize();
		$this->assertSame( $rate_before, (int) ( $ops_after['rate_count'] ?? 0 ), 'M9 rate must be untouched' );

		$user_payload = json_decode( json_decode( $transport->sent, true )['input'][1]['content'], true );
		$this->assertSame( OpenAiConstants::TEST_CONNECTION_REVIEW_TEXT, $user_payload['review_text'] );
		$this->assertStringNotContainsString( 'sk-test', $transport->sent );

		unset( $wpdb );
	}
}
