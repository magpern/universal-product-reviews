<?php
/**
 * Action Scheduler worker for local AI shadow assessment (Point B).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

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
				false
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
				true
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
				true
			);
			return;
		}

		$comment = get_comment( $comment_id );
		$text    = ( $comment && isset( $comment->comment_content ) ) ? (string) $comment->comment_content : '';

		$t0 = microtime( true );
		try {
			$raw = BuiltInLocalAssessor::assess(
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
				true
			);
			if ( null !== $assessment_id ) {
				ModerationOpsRepository::record_failure();
			}
			return;
		}

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
				true
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
				true
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
			true
		);

		if ( null === $assessment_id ) {
			return;
		}

		ModerationOpsRepository::record_success();
		AssessmentAudit::completed( $comment_id, $assessment_id, $validated->state, $policy_version );
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
			false
		);

		AssessmentRepository::recompute_retention( $comment_id, $new_status );
	}

	/**
	 * @param list<string> $reason_codes
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
		bool $require_held
	): ?int {
		global $wpdb;

		$claims_table = AssessmentClaimsRepository::table();

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
				$comment_status
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
				AssessmentAudit::skipped( $comment_id, $assessment_id, $policy_version, $failure_code );
			} elseif ( 'failed' === $state && null !== $failure_code ) {
				AssessmentAudit::failed( $comment_id, $assessment_id, $policy_version, $failure_code );
			}

			return $assessment_id;
		} catch ( \Throwable $e ) {
			unset( $e );
			$wpdb->query( 'ROLLBACK' );
			return null;
		}
	}
}
