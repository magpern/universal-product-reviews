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
use UniversalProductReviews\Ai\BuiltInLocalAssessor;
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
		BuiltInLocalAssessor::set_test_assessor( null );
		Plugin::reset_for_tests();
		Plugin::init();
	}

	public function tear_down(): void {
		CredentialResolver::set_test_credential( null );
		ProviderResolver::set_test_openai_provider( null );
		BuiltInLocalAssessor::set_test_assessor( null );
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

	public function test_openai_without_external_silent_no_row_or_audit(): void {
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
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			)
		);
		$this->assertSame( 0, $count, 'No terminal assessment when external AI disabled' );
		$this->assertFalse(
			AssessmentClaimsRepository::has_active_claim( $comment_id, PolicyAllowlist::POLICY_VERSION )
		);
		$this->assertSame( 0, $this->count_ai_audit_events() );
	}

	public function test_claim_then_external_disable_before_finalize_persists_nothing(): void {
		$comment_id = $this->held_review();
		$this->enable_openai_shadow();
		CredentialResolver::set_test_credential( 'sk-test', CredentialResolver::SOURCE_CONSTANT );

		ProviderResolver::set_test_openai_provider(
			new class() implements AssessmentProvider {
				public function assess( AssessmentRequest $request ): AssessmentResult {
					// Race: external AI disabled after claim / during provider work.
					update_option( Options::AI_EXTERNAL_ENABLED, 'no', false );
					return new AssessmentResult( 'completed', 66, 'high', array( 'spam_pattern' ), null );
				}
			}
		);

		AssessmentWorker::handle( $comment_id, PolicyAllowlist::POLICY_VERSION );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			)
		);
		$this->assertSame( 0, $count );
		$this->assertFalse(
			AssessmentClaimsRepository::has_active_claim( $comment_id, PolicyAllowlist::POLICY_VERSION )
		);
		$this->assertSame( 0, $this->count_ai_audit_events() );
	}

	/**
	 * @return int
	 */
	private function count_ai_audit_events(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'upr_audit';
		$n     = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE event_type LIKE %s",
				'review.ai_assessment_%'
			)
		);
		return (int) $n;
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

	public function test_worker_keeps_local_path_when_provider_option_changes_mid_assess(): void {
		$comment_id = $this->held_review( 'Nice enough review text for local path.' );
		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		update_option( Options::AI_PROVIDER, 'local', false );
		update_option( Options::AI_EXTERNAL_ENABLED, 'no', false );

		$openai = new class() implements AssessmentProvider {
			public bool $called = false;
			public function assess( AssessmentRequest $request ): AssessmentResult {
				$this->called = true;
				return new AssessmentResult( 'completed', 99, 'high', array( 'spam_pattern' ), null );
			}
		};
		ProviderResolver::set_test_openai_provider( $openai );

		BuiltInLocalAssessor::set_test_assessor(
			static function ( AssessmentRequest $request ): AssessmentResult {
				unset( $request );
				// Live option flips after the claim was stamped local.
				update_option( Options::AI_PROVIDER, 'openai', false );
				return new AssessmentResult( 'completed', 41, 'medium', array( 'spam_pattern' ), null );
			}
		);

		AssessmentWorker::handle( $comment_id, PolicyAllowlist::POLICY_VERSION );

		$this->assertFalse( $openai->called, 'OpenAI assessor must not run for a local-stamped claim' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT state, provider_kind, publication_safety_score FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'local', $row['provider_kind'] );
		$this->assertSame( 'completed', $row['state'] );
		$this->assertSame( 41, (int) $row['publication_safety_score'] );
		$this->assertFalse(
			AssessmentClaimsRepository::has_active_claim( $comment_id, PolicyAllowlist::POLICY_VERSION )
		);
	}

	public function test_worker_keeps_openai_path_when_provider_option_changes_to_local_mid_assess(): void {
		$comment_id = $this->held_review();
		$this->enable_openai_shadow();
		CredentialResolver::set_test_credential( 'sk-test', CredentialResolver::SOURCE_CONSTANT );

		$local_called = false;
		BuiltInLocalAssessor::set_test_assessor(
			static function ( AssessmentRequest $request ) use ( &$local_called ): AssessmentResult {
				unset( $request );
				$local_called = true;
				return new AssessmentResult( 'completed', 12, 'medium', array(), null );
			}
		);

		ProviderResolver::set_test_openai_provider(
			new class() implements AssessmentProvider {
				public function assess( AssessmentRequest $request ): AssessmentResult {
					unset( $request );
					update_option( Options::AI_PROVIDER, 'local', false );
					return new AssessmentResult( 'completed', 77, 'high', array( 'link_abuse' ), null );
				}
			}
		);

		AssessmentWorker::handle( $comment_id, PolicyAllowlist::POLICY_VERSION );

		$this->assertFalse( $local_called, 'Local assessor must not run for an openai-stamped claim' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT state, provider_kind, publication_safety_score FROM ' . AssessmentRepository::table() . ' WHERE comment_id = %d',
				$comment_id
			),
			ARRAY_A
		);
		$this->assertIsArray( $row );
		$this->assertSame( 'openai', $row['provider_kind'] );
		$this->assertSame( 'completed', $row['state'] );
		$this->assertSame( 77, (int) $row['publication_safety_score'] );
	}
}
