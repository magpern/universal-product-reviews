<?php
/**
 * M10 WP3: worker OpenAI fail-closed path, quotas, no local fallback.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Ai\AssessmentClaimsRepository;
use UniversalProductReviews\Ai\AssessmentProvider;
use UniversalProductReviews\Ai\AssessmentRepository;
use UniversalProductReviews\Ai\AssessmentRequest;
use UniversalProductReviews\Ai\AssessmentResult;
use UniversalProductReviews\Ai\AssessmentWorker;
use UniversalProductReviews\Ai\ExternalQuotaRepository;
use UniversalProductReviews\Ai\OpenAi\CredentialResolver;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Ai\ProviderError;
use UniversalProductReviews\Ai\ProviderResolver;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Schema;
use UniversalProductReviews\Plugin;
use WP_UnitTestCase;

final class M10WorkerOpenAiIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		delete_option( Options::LOCAL_AI_SHADOW_ENABLED );
		delete_option( Options::AI_EXTERNAL_ENABLED );
		delete_option( Options::AI_PROVIDER );
		delete_option( Options::OPENAI_DAILY_REQUEST_CAP );
		delete_option( Options::OPENAI_MONTHLY_REQUEST_CAP );
		CredentialResolver::set_test_credential( null );
		ProviderResolver::set_test_openai_provider( null );
		AssessmentRepository::set_force_insert_fail_for_tests( false );
		AssessmentWorker::set_force_claim_clear_fail_for_tests( false );
		Plugin::reset_for_tests();
		Plugin::init();
	}

	public function tear_down(): void {
		CredentialResolver::set_test_credential( null );
		ProviderResolver::set_test_openai_provider( null );
		delete_option( Options::LOCAL_AI_SHADOW_ENABLED );
		delete_option( Options::AI_EXTERNAL_ENABLED );
		delete_option( Options::AI_PROVIDER );
		parent::tear_down();
	}

	private function held_review( string $content = 'Held product review with enough text.' ): int {
		$product_id = $this->upr_create_product();
		$comment_id = wp_insert_comment(
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
		$this->assertIsInt( $comment_id );
		return (int) $comment_id;
	}

	private function enable_openai_shadow(): void {
		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		update_option( Options::AI_PROVIDER, 'openai', false );
		update_option( Options::AI_EXTERNAL_ENABLED, 'yes', false );
	}

	public function test_openai_without_external_fails_closed_no_local_fallback(): void {
		$comment_id = $this->held_review( 'See http://spam.example for deals' );
		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		update_option( Options::AI_PROVIDER, 'openai', false );
		update_option( Options::AI_EXTERNAL_ENABLED, 'no', false );
		CredentialResolver::set_test_credential( 'sk-test', CredentialResolver::SOURCE_CONSTANT );

		$probe = new class() implements AssessmentProvider {
			public bool $called = false;
			public function assess( AssessmentRequest $request ): AssessmentResult {
				$this->called = true;
				return new AssessmentResult( 'completed', 12, 'medium', array(), null );
			}
		};
		ProviderResolver::set_test_openai_provider( $probe );

		AssessmentWorker::handle( $comment_id, PolicyAllowlist::POLICY_VERSION );

		$this->assertFalse( $probe->called, 'OpenAI assessor must not run when external disabled' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT state, failure_code, provider_kind, publication_safety_score FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'failed', $row['state'] );
		$this->assertSame( 'provider_unavailable', $row['failure_code'] );
		$this->assertSame( 'openai', $row['provider_kind'] );
		$this->assertNull( $row['publication_safety_score'] );
	}

	public function test_openai_missing_credential_fail_closed(): void {
		$comment_id = $this->held_review();
		$this->enable_openai_shadow();
		CredentialResolver::set_test_credential( '', CredentialResolver::SOURCE_MISSING );

		AssessmentWorker::handle( $comment_id, PolicyAllowlist::POLICY_VERSION );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT state, failure_code, provider_kind FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'failed', $row['state'] );
		$this->assertSame( 'credential_missing', $row['failure_code'] );
		$this->assertSame( 'openai', $row['provider_kind'] );
	}

	public function test_openai_budget_exceeded_skipped_clears_claim(): void {
		global $wpdb;
		$comment_id = $this->held_review();
		$this->enable_openai_shadow();
		CredentialResolver::set_test_credential( 'sk-test', CredentialResolver::SOURCE_CONSTANT );
		update_option( Options::OPENAI_DAILY_REQUEST_CAP, 1, false );
		update_option( Options::OPENAI_MONTHLY_REQUEST_CAP, 100, false );

		// Exhaust daily quota.
		$this->assertSame( 'ok', ExternalQuotaRepository::try_consume( 1, 100 ) );

		ProviderResolver::set_test_openai_provider(
			new class() implements AssessmentProvider {
				public function assess( AssessmentRequest $request ): AssessmentResult {
					throw new \RuntimeException( 'should_not_run' );
				}
			}
		);

		AssessmentWorker::handle( $comment_id, PolicyAllowlist::POLICY_VERSION );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT state, failure_code, provider_kind FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'skipped', $row['state'] );
		$this->assertSame( 'budget_exceeded', $row['failure_code'] );
		$this->assertFalse(
			AssessmentClaimsRepository::has_active_claim( $comment_id, PolicyAllowlist::POLICY_VERSION )
		);
	}

	public function test_openai_success_persists_provider_kind(): void {
		$comment_id = $this->held_review();
		$this->enable_openai_shadow();
		CredentialResolver::set_test_credential( 'sk-test', CredentialResolver::SOURCE_CONSTANT );

		ProviderResolver::set_test_openai_provider(
			new class() implements AssessmentProvider {
				public function assess( AssessmentRequest $request ): AssessmentResult {
					return new AssessmentResult( 'completed', 66, 'high', array( 'spam_pattern' ), null );
				}
			}
		);

		AssessmentWorker::handle( $comment_id, PolicyAllowlist::POLICY_VERSION );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT state, publication_safety_score, provider_kind, failure_code FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'completed', $row['state'] );
		$this->assertSame( 66, (int) $row['publication_safety_score'] );
		$this->assertSame( 'openai', $row['provider_kind'] );
		$this->assertNull( $row['failure_code'] );
	}

	public function test_openai_provider_error_maps_typed_without_secret_leak(): void {
		$comment_id = $this->held_review();
		$this->enable_openai_shadow();
		CredentialResolver::set_test_credential( 'sk-super-secret', CredentialResolver::SOURCE_CONSTANT );

		ProviderResolver::set_test_openai_provider(
			new class() implements AssessmentProvider {
				public function assess( AssessmentRequest $request ): AssessmentResult {
					throw ProviderError::provider_incomplete();
				}
			}
		);

		AssessmentWorker::handle( $comment_id, PolicyAllowlist::POLICY_VERSION );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT state, failure_code FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'failed', $row['state'] );
		$this->assertSame( 'provider_incomplete', $row['failure_code'] );
		$this->assertStringNotContainsString( 'sk-super-secret', wp_json_encode( $row ) ?: '' );
	}

	public function test_local_provider_unchanged_when_openai_not_selected(): void {
		$comment_id = $this->held_review( 'Nice enough review text here.' );
		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		update_option( Options::AI_PROVIDER, 'local', false );

		AssessmentWorker::handle( $comment_id, PolicyAllowlist::POLICY_VERSION );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT state, provider_kind FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'local', $row['provider_kind'] );
		$this->assertContains( $row['state'], array( 'completed', 'indeterminate' ) );
	}
}
