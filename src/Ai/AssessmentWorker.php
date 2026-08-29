<?php
/**
 * Action Scheduler worker for AI shadow assessment (Point B).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Ai\OpenAi\CredentialResolver;
use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

final class AssessmentWorker {

	public const ASSESS_DEADLINE_SECONDS = 15;

	/** @var bool Test seam: pretend claim-clear UPDATE affected 0 rows. */
	private static bool $force_claim_clear_fail_for_tests = false;

	/**
	 * Test seam only — force finalize claim-clear to fail after a successful insert.
	 */
	public static function set_force_claim_clear_fail_for_tests( bool $force ): void {
		self::$force_claim_clear_fail_for_tests = $force;
	}

	/**
	 * Point B — claim-before-rate worker with one-txn completion.
	 */
	public static function handle( int $comment_id, string $policy_version ): void {
		if ( ! Options::local_ai_shadow_enabled() ) {
			return;
		}

		$claim_token = AssessmentClaimsRepository::acquire( $comment_id, $policy_version );
		if ( null === $claim_token ) {
			return;
		}

		if ( ! Options::local_ai_shadow_enabled() ) {
			AssessmentClaimsRepository::clear_owned( $comment_id, $policy_version, $claim_token );
			return;
		}

		$claim_row = AssessmentClaimsRepository::get_row( $comment_id, $policy_version );
		$requested = is_array( $claim_row ) ? (string) ( $claim_row['requested_at'] ?? current_time( 'mysql', true ) ) : current_time( 'mysql', true );

		if ( ! Eligibility::is_ai_assessable( $comment_id ) ) {
			self::finalize_terminal(
				$comment_id,
				$policy_version,
				$claim_token,
				'skipped',
				null,
				null,
				array(),
				'ineligible_comment',
				$requested,
				false,
				'local',
				ProviderFingerprint::for_builtin( $policy_version )
			);
			return;
		}

		$ops = ModerationOpsRepository::try_consume_rate_and_check_circuit();
		if ( 'circuit_open' === $ops ) {
			self::finalize_terminal(
				$comment_id,
				$policy_version,
				$claim_token,
				'skipped',
				null,
				null,
				array(),
				'circuit_open',
				$requested,
				true,
				'local',
				ProviderFingerprint::for_builtin( $policy_version )
			);
			return;
		}
		if ( 'rate_limited' === $ops ) {
			self::finalize_terminal(
				$comment_id,
				$policy_version,
				$claim_token,
				'skipped',
				null,
				null,
				array(),
				'rate_limited',
				$requested,
				true,
				'local',
				ProviderFingerprint::for_builtin( $policy_version )
			);
			return;
		}

		$provider_kind = ProviderResolver::kind();
		$fingerprint   = ProviderResolver::fingerprint( $provider_kind, $policy_version );

		if ( 'openai' === $provider_kind ) {
			self::handle_openai(
				$comment_id,
				$policy_version,
				$claim_token,
				$requested,
				$fingerprint
			);
			return;
		}

		self::handle_local(
			$comment_id,
			$policy_version,
			$claim_token,
			$requested,
			$fingerprint
		);
	}

	/**
	 * Local built-in path (unchanged M9 semantics).
	 */
	private static function handle_local(
		int $comment_id,
		string $policy_version,
		string $claim_token,
		string $requested,
		string $fingerprint
	): void {
		$comment = get_comment( $comment_id );
		$text    = ( $comment && isset( $comment->comment_content ) ) ? (string) $comment->comment_content : '';

		$t0 = microtime( true );
		try {
			$raw = ProviderResolver::resolve( 'local' )->assess(
				new AssessmentRequest( $text, $policy_version )
			);
		} catch ( \Throwable $e ) {
			unset( $e );
			$assessment_id = self::finalize_terminal(
				$comment_id,
				$policy_version,
				$claim_token,
				'failed',
				null,
				null,
				array(),
				'provider_unavailable',
				$requested,
				true,
				'local',
				$fingerprint
			);
			if ( null !== $assessment_id ) {
				ModerationOpsRepository::record_failure();
			}
			return;
		}

		self::complete_after_assess(
			$comment_id,
			$policy_version,
			$claim_token,
			$requested,
			$t0,
			$raw,
			'local',
			$fingerprint
		);
	}

	/**
	 * OpenAI path — fail closed; never silent-fallback to local.
	 */
	private static function handle_openai(
		int $comment_id,
		string $policy_version,
		string $claim_token,
		string $requested,
		string $fingerprint
	): void {
		if ( ! Options::ai_external_enabled() ) {
			$assessment_id = self::finalize_terminal(
				$comment_id,
				$policy_version,
				$claim_token,
				'failed',
				null,
				null,
				array(),
				'provider_unavailable',
				$requested,
				true,
				'openai',
				$fingerprint
			);
			if ( null !== $assessment_id ) {
				ModerationOpsRepository::record_failure();
			}
			return;
		}

		$cred = CredentialResolver::status();
		if ( ! $cred['present'] ) {
			$assessment_id = self::finalize_terminal(
				$comment_id,
				$policy_version,
				$claim_token,
				'failed',
				null,
				null,
				array(),
				'credential_missing',
				$requested,
				true,
				'openai',
				$fingerprint
			);
			if ( null !== $assessment_id ) {
				ModerationOpsRepository::record_failure();
			}
			return;
		}

		$quota = ExternalQuotaRepository::try_consume(
			Options::openai_daily_request_cap(),
			Options::openai_monthly_request_cap()
		);
		if ( 'budget_exceeded' === $quota ) {
			self::finalize_terminal(
				$comment_id,
				$policy_version,
				$claim_token,
				'skipped',
				null,
				null,
				array(),
				'budget_exceeded',
				$requested,
				true,
				'openai',
				$fingerprint
			);
			return;
		}

		$comment = get_comment( $comment_id );
		$text    = ( $comment && isset( $comment->comment_content ) ) ? (string) $comment->comment_content : '';

		$t0 = microtime( true );
		try {
			$raw = ProviderResolver::resolve( 'openai' )->assess(
				new AssessmentRequest( $text, $policy_version )
			);
		} catch ( ProviderError $e ) {
			$code  = $e->failure_code();
			$state = ( ProviderError::BUDGET_EXCEEDED === $code ) ? 'skipped' : 'failed';
			$assessment_id = self::finalize_terminal(
				$comment_id,
				$policy_version,
				$claim_token,
				$state,
				null,
				null,
				array(),
				$code,
				$requested,
				true,
				'openai',
				$fingerprint
			);
			if ( null !== $assessment_id && 'failed' === $state ) {
				ModerationOpsRepository::record_failure();
			}
			return;
		} catch ( \Throwable $e ) {
			unset( $e );
			$assessment_id = self::finalize_terminal(
				$comment_id,
				$policy_version,
				$claim_token,
				'failed',
				null,
				null,
				array(),
				'provider_unavailable',
				$requested,
				true,
				'openai',
				$fingerprint
			);
			if ( null !== $assessment_id ) {
				ModerationOpsRepository::record_failure();
			}
			return;
		}

		self::complete_after_assess(
			$comment_id,
			$policy_version,
			$claim_token,
			$requested,
			$t0,
			$raw,
			'openai',
			$fingerprint
		);
	}

	/**
	 * @param 'local'|'openai' $provider_kind
	 */
	private static function complete_after_assess(
		int $comment_id,
		string $policy_version,
		string $claim_token,
		string $requested,
		float $t0,
		AssessmentResult $raw,
		string $provider_kind,
		string $fingerprint
	): void {
		if ( ( microtime( true ) - $t0 ) > self::ASSESS_DEADLINE_SECONDS ) {
			$assessment_id = self::finalize_terminal(
				$comment_id,
				$policy_version,
				$claim_token,
				'failed',
				null,
				null,
				array(),
				'deadline_exceeded',
				$requested,
				true,
				$provider_kind,
				$fingerprint
			);
			if ( null !== $assessment_id ) {
				ModerationOpsRepository::record_failure();
			}
			return;
		}

		$validated = AssessmentValidator::validate( $raw );
		if ( is_string( $validated ) ) {
			$assessment_id = self::finalize_terminal(
				$comment_id,
				$policy_version,
				$claim_token,
				'failed',
				null,
				null,
				array(),
				$validated,
				$requested,
				true,
				$provider_kind,
				$fingerprint
			);
			if ( null !== $assessment_id ) {
				ModerationOpsRepository::record_failure();
			}
			return;
		}

		$assessment_id = self::finalize_terminal(
			$comment_id,
			$policy_version,
			$claim_token,
			$validated->state,
			$validated->publication_safety_score,
			$validated->confidence,
			$validated->reason_codes,
			null,
			$requested,
			true,
			$provider_kind,
			$fingerprint
		);

		if ( null === $assessment_id ) {
			return;
		}

		ModerationOpsRepository::record_success();
		AssessmentAudit::completed( $comment_id, $assessment_id, $validated->state, $policy_version, $provider_kind );
	}

	/**
	 * Non-held transition while enabled with an active claim (Point B revoke).
	 */
	public static function revoke_on_non_held_transition( int $comment_id, string $new_status ): void {
		$policy = PolicyAllowlist::POLICY_VERSION;

		if ( ! Options::local_ai_shadow_enabled() ) {
			AssessmentClaimsRepository::clear_any_active( $comment_id, $policy );
			AssessmentRepository::recompute_retention( $comment_id, $new_status );
			return;
		}

		$row = AssessmentClaimsRepository::get_row( $comment_id, $policy );
		if ( ! is_array( $row ) || empty( $row['claim_token'] ) || ! AssessmentClaimsRepository::has_active_claim( $comment_id, $policy ) ) {
			AssessmentRepository::recompute_retention( $comment_id, $new_status );
			return;
		}

		$token     = (string) $row['claim_token'];
		$requested = (string) ( $row['requested_at'] ?? current_time( 'mysql', true ) );
		$kind      = ProviderResolver::kind();

		self::finalize_terminal(
			$comment_id,
			$policy,
			$token,
			'skipped',
			null,
			null,
			array(),
			'ineligible_comment',
			$requested,
			false,
			$kind,
			ProviderResolver::fingerprint( $kind, $policy )
		);

		AssessmentRepository::recompute_retention( $comment_id, $new_status );
	}

	/**
	 * @param list<string>     $reason_codes
	 * @param 'local'|'openai' $provider_kind
	 */
	private static function finalize_terminal(
		int $comment_id,
		string $policy_version,
		string $claim_token,
		string $state,
		?int $score,
		?string $confidence,
		array $reason_codes,
		?string $failure_code,
		string $requested_at,
		bool $require_held,
		string $provider_kind = 'local',
		?string $provider_fingerprint = null
	): ?int {
		global $wpdb;

		$claims_table = AssessmentClaimsRepository::table();
		$provider_kind = 'openai' === $provider_kind ? 'openai' : 'local';
		$fingerprint   = $provider_fingerprint ?? ProviderResolver::fingerprint( $provider_kind, $policy_version );

		$wpdb->query( 'START TRANSACTION' );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
			$claim = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$claims_table} WHERE comment_id = %d AND policy_version = %s FOR UPDATE",
					$comment_id,
					$policy_version
				),
				ARRAY_A
			);

			if ( ! is_array( $claim ) || (string) ( $claim['claim_token'] ?? '' ) !== $claim_token ) {
				$wpdb->query( 'ROLLBACK' );
				return null;
			}

			if ( ! Options::local_ai_shadow_enabled() ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$claims_table} SET claim_token = NULL, claim_expires_at = NULL, updated_at = %s
						WHERE comment_id = %d AND policy_version = %s AND claim_token = %s",
						current_time( 'mysql', true ),
						$comment_id,
						$policy_version,
						$claim_token
					)
				);
				$wpdb->query( 'COMMIT' );
				return null;
			}

			if ( $require_held && ! Eligibility::is_ai_assessable( $comment_id ) ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$claims_table} SET claim_token = NULL, claim_expires_at = NULL, updated_at = %s
						WHERE comment_id = %d AND policy_version = %s AND claim_token = %s",
						current_time( 'mysql', true ),
						$comment_id,
						$policy_version,
						$claim_token
					)
				);
				$wpdb->query( 'COMMIT' );
				return null;
			}

			$comment        = get_comment( $comment_id );
			$comment_status = $comment ? (string) $comment->comment_approved : '0';

			$assessment_id = AssessmentRepository::insert_terminal(
				$comment_id,
				$state,
				$score,
				$confidence,
				$reason_codes,
				$policy_version,
				$failure_code,
				$requested_at,
				$comment_status,
				$provider_kind,
				$fingerprint
			);

			if ( $assessment_id <= 0 ) {
				// Keep the owned claim so a later worker can retry (at-least-once advisory).
				$wpdb->query( 'ROLLBACK' );
				return null;
			}

			if ( self::$force_claim_clear_fail_for_tests ) {
				$cleared = 0;
			} else {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				$cleared = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$claims_table} SET claim_token = NULL, claim_expires_at = NULL, updated_at = %s
						WHERE comment_id = %d AND policy_version = %s AND claim_token = %s",
						current_time( 'mysql', true ),
						$comment_id,
						$policy_version,
						$claim_token
					)
				);
			}

			// Terminal row + claim clear must succeed together; otherwise roll back the
			// insert so a later retry cannot produce a second terminal assessment.
			if ( 1 !== (int) $cleared ) {
				$wpdb->query( 'ROLLBACK' );
				return null;
			}

			$wpdb->query( 'COMMIT' );

			if ( 'skipped' === $state && null !== $failure_code ) {
				AssessmentAudit::skipped( $comment_id, $assessment_id, $policy_version, $failure_code, $provider_kind );
			} elseif ( 'failed' === $state && null !== $failure_code ) {
				AssessmentAudit::failed( $comment_id, $assessment_id, $policy_version, $failure_code, $provider_kind );
			}

			return $assessment_id;
		} catch ( \Throwable $e ) {
			unset( $e );
			$wpdb->query( 'ROLLBACK' );
			return null;
		}
	}
}
