<?php
/**
 * M4 diagnostics catalogue D1–D21 (no PII).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin\Diagnostics;

use UniversalProductReviews\Admin\AdminCache;
use UniversalProductReviews\Admin\OverviewRepository;
use UniversalProductReviews\Ai\AssessmentClaimsRepository;
use UniversalProductReviews\Ai\AssessmentRepository;
use UniversalProductReviews\Ai\ActionLedgerRepository;
use UniversalProductReviews\Ai\ExternalQuotaRepository;
use UniversalProductReviews\Ai\ModerationOpsRepository;
use UniversalProductReviews\Ai\OpenAi\CredentialResolver;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Database\Schema;
use UniversalProductReviews\Scheduling\ActionSchedulerStatus;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsService {

	/**
	 * Run D1–D21. Results are cached ≤ 60s via AdminCache.
	 *
	 * @return list<array{id:string,status:string,severity:string,message:string,evidence_code:string}>
	 */
	public static function run( bool $use_cache = true ): array {
		if ( $use_cache ) {
			$cached = AdminCache::get();
			if ( is_array( $cached ) && isset( $cached['results'] ) && is_array( $cached['results'] ) ) {
				/** @var list<array{id:string,status:string,severity:string,message:string,evidence_code:string}> $results */
				$results = $cached['results'];
				return $results;
			}
		}

		$results = array(
			self::check_d1(),
			self::check_d2(),
			self::check_d3(),
			self::check_d4(),
			self::check_d5(),
			self::check_d6(),
			self::check_d7(),
			self::check_d8(),
			self::check_d9(),
			self::check_d10(),
			self::check_d11(),
			self::check_d12(),
			self::check_d13(),
			self::check_d14(),
			self::check_d15(),
			self::check_d16(),
			self::check_d17(),
			self::check_d18(),
			self::check_d19(),
			self::check_d20(),
			self::check_d21(),
		);

		AdminCache::set(
			array(
				'generated_at' => time(),
				'results'      => $results,
			)
		);

		return $results;
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d1(): array {
		if ( ! Options::invitation_emails_enabled() ) {
			return self::result(
				'D1',
				'information',
				'Information',
				'Invitation emails are disabled (fail-closed default).',
				'emails_disabled'
			);
		}
		return self::result( 'D1', 'pass', 'Pass', 'Invitation emails are enabled.', 'emails_enabled' );
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d2(): array {
		if ( Options::invitation_emergency_pause() ) {
			return self::result(
				'D2',
				'warning',
				'Warning',
				'Emergency pause is active; invitation scheduling and sending are blocked.',
				'pause_active'
			);
		}
		return self::result( 'D2', 'pass', 'Pass', 'Emergency pause is off.', 'pause_inactive' );
	}

	/**
	 * Warning only when emails enabled and not paused and boundary unset.
	 *
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d3(): array {
		$enabled  = Options::invitation_emails_enabled();
		$paused   = Options::invitation_emergency_pause();
		$boundary = Options::invitation_scheduling_boundary_unix();

		if ( $enabled && ! $paused && $boundary <= 0 ) {
			return self::result(
				'D3',
				'warning',
				'Warning',
				'Scheduling boundary is unset while invitation emails are enabled.',
				'boundary_unset'
			);
		}

		return self::result(
			'D3',
			'pass',
			'Pass',
			$boundary > 0
				? 'Scheduling boundary is set.'
				: 'Scheduling boundary check skipped (emails disabled or paused).',
			$boundary > 0 ? 'boundary_set' : 'boundary_not_applicable'
		);
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d4(): array {
		if ( Migrator::needs_upgrade() ) {
			$installed = (string) get_option( Migrator::OPTION_VERSION, '' );
			$code      = ( $installed === Schema::DB_VERSION && ! Migrator::tables_exist() )
				? 'schema_tables_missing'
				: 'schema_behind';
			return self::result(
				'D4',
				'warning',
				'Warning',
				'schema_tables_missing' === $code
					? 'Database schema tables are missing despite a matching version option.'
					: 'Database schema version is behind the plugin target.',
				$code
			);
		}
		return self::result( 'D4', 'pass', 'Pass', 'Database schema version matches the plugin target and tables exist.', 'schema_current' );
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d5(): array {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			return self::result(
				'D5',
				'warning',
				'Warning',
				'Action Scheduler enqueue APIs are missing.',
				'as_enqueue_missing'
			);
		}
		return self::result( 'D5', 'pass', 'Pass', 'Action Scheduler enqueue APIs are present.', 'as_enqueue_present' );
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d6(): array {
		try {
			$as = ActionSchedulerStatus::summarize();
		} catch ( \Throwable $e ) {
			return self::result(
				'D6',
				'unavailable',
				'Unavailable',
				'Action Scheduler listing is unavailable.',
				'as_query_failed'
			);
		}

		if ( ! $as['listing_available'] ) {
			return self::result(
				'D6',
				'unavailable',
				'Unavailable',
				'Action Scheduler listing is unavailable.',
				$as['unavailable_reason'] ?? 'as_listing_unavailable'
			);
		}

		$failed  = (int) ( $as['failed_at_least'] ?? 0 );
		$pending = (int) ( $as['pending_at_least'] ?? 0 );
		$capped  = ! empty( $as['capped'] );
		$total   = $failed + $pending;

		if ( $failed > 0 || $pending > 0 ) {
			// Bounded AS sample — always present as "at least N" (never imply exhaustive inventory).
			$msg = sprintf( 'Failed or overdue pending UPR Action Scheduler work: at least %d.', $total );
			if ( $capped ) {
				$msg .= ' Sample was capped.';
			}
			return self::result( 'D6', 'warning', 'Warning', $msg, 'as_failed_or_overdue' );
		}

		return self::result(
			'D6',
			'pass',
			'Pass',
			'No failed or overdue pending UPR Action Scheduler work in the bounded sample.',
			'as_ok'
		);
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d7(): array {
		try {
			$data = OverviewRepository::stale_send_claim_count();
		} catch ( \Throwable $e ) {
			return self::result( 'D7', 'unavailable', 'Unavailable', 'Stale send-claim count unavailable.', 'query_failed' );
		}
		if ( ! $data['ok'] ) {
			return self::result( 'D7', 'unavailable', 'Unavailable', 'Stale send-claim count unavailable.', 'query_failed' );
		}
		if ( $data['count'] > 0 ) {
			return self::result(
				'D7',
				'warning',
				'Warning',
				sprintf( 'Stale send claims detected: %d.', $data['count'] ),
				'stale_send_claims'
			);
		}
		return self::result( 'D7', 'pass', 'Pass', 'No stale send claims.', 'no_stale_send_claims' );
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d8(): array {
		try {
			$data = OverviewRepository::expired_submit_claim_count();
		} catch ( \Throwable $e ) {
			return self::result( 'D8', 'unavailable', 'Unavailable', 'Expired submit-claim count unavailable.', 'query_failed' );
		}
		if ( ! $data['ok'] ) {
			return self::result( 'D8', 'unavailable', 'Unavailable', 'Expired submit-claim count unavailable.', 'query_failed' );
		}
		if ( $data['count'] > 0 ) {
			return self::result(
				'D8',
				'warning',
				'Warning',
				sprintf( 'Expired submit claims detected: %d.', $data['count'] ),
				'expired_submit_claims'
			);
		}
		return self::result( 'D8', 'pass', 'Pass', 'No expired submit claims.', 'no_expired_submit_claims' );
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d9(): array {
		try {
			$data = OverviewRepository::email_failed_count_24h();
		} catch ( \Throwable $e ) {
			return self::result( 'D9', 'unavailable', 'Unavailable', 'email.failed count unavailable.', 'query_failed' );
		}
		if ( ! $data['ok'] ) {
			return self::result( 'D9', 'unavailable', 'Unavailable', 'email.failed count unavailable.', 'query_failed' );
		}
		$count = $data['count'];
		if ( $count >= OverviewRepository::EMAIL_FAILED_WARN ) {
			return self::result(
				'D9',
				'warning',
				'Warning',
				sprintf( 'Elevated email.failed events in 24h: %d.', $count ),
				'email_failed_elevated'
			);
		}
		if ( $count > 0 ) {
			return self::result(
				'D9',
				'information',
				'Information',
				sprintf( 'email.failed events in 24h: %d (below warning threshold).', $count ),
				'email_failed_low'
			);
		}
		return self::result( 'D9', 'pass', 'Pass', 'No email.failed events in 24h.', 'email_failed_none' );
	}

	/**
	 * Only when emails on and not paused.
	 *
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d10(): array {
		if ( ! Options::invitation_emails_enabled() || Options::invitation_emergency_pause() ) {
			return self::result(
				'D10',
				'pass',
				'Pass',
				'Reconciliation age check skipped (emails disabled or paused).',
				'reconcile_not_applicable'
			);
		}

		try {
			$last = OverviewRepository::last_reconcile_completed();
		} catch ( \Throwable $e ) {
			return self::result( 'D10', 'unavailable', 'Unavailable', 'Last reconciliation lookup unavailable.', 'query_failed' );
		}

		if ( empty( $last['found'] ) ) {
			return self::result(
				'D10',
				'warning',
				'Warning',
				'No recorded reconcile.completed run while invitation emails are enabled.',
				'reconcile_no_recorded_run'
			);
		}

		$occurred = (string) ( $last['occurred_at'] ?? '' );
		$ts       = $occurred !== '' ? strtotime( $occurred . ' UTC' ) : false;
		if ( false === $ts ) {
			return self::result(
				'D10',
				'warning',
				'Warning',
				'Last reconciliation timestamp could not be parsed.',
				'reconcile_timestamp_invalid'
			);
		}

		$age = time() - $ts;
		if ( $age > OverviewRepository::RECONCILE_MAX_AGE ) {
			return self::result(
				'D10',
				'warning',
				'Warning',
				'Last non-dry-run reconciliation is older than 48 hours.',
				'reconcile_overdue'
			);
		}

		return self::result( 'D10', 'pass', 'Pass', 'Last reconciliation is within 48 hours.', 'reconcile_fresh' );
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d11(): array {
		try {
			$data = OverviewRepository::overdue_delayed_count();
		} catch ( \Throwable $e ) {
			return self::result( 'D11', 'unavailable', 'Unavailable', 'Overdue delayed count unavailable.', 'query_failed' );
		}
		if ( ! $data['ok'] ) {
			return self::result( 'D11', 'unavailable', 'Unavailable', 'Overdue delayed count unavailable.', 'query_failed' );
		}
		if ( $data['count'] > 0 ) {
			return self::result(
				'D11',
				'warning',
				'Warning',
				sprintf( 'Overdue delayed invitations past delay_until: %d.', $data['count'] ),
				'overdue_delayed'
			);
		}
		return self::result( 'D11', 'pass', 'Pass', 'No overdue delayed invitations.', 'no_overdue_delayed' );
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d12(): array {
		if ( Options::local_ai_shadow_enabled() ) {
			return self::result(
				'D12',
				'information',
				'Information',
				'Local AI shadow mode is enabled (advisory only).',
				'shadow_enabled'
			);
		}
		return self::result(
			'D12',
			'pass',
			'Pass',
			'Local AI shadow mode is disabled (fail-closed default).',
			'shadow_disabled'
		);
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d13(): array {
		if ( self::moderation_assessment_tables_exist() ) {
			return self::result(
				'D13',
				'pass',
				'Pass',
				'Moderation assessment schema tables exist.',
				'assessment_tables_present'
			);
		}
		return self::result(
			'D13',
			'warning',
			'Warning',
			'Moderation assessment schema tables are missing.',
			'assessment_tables_missing'
		);
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d14(): array {
		try {
			$ops = ModerationOpsRepository::summarize();
		} catch ( \Throwable $e ) {
			return self::result( 'D14', 'unavailable', 'Unavailable', 'Moderation ops status unavailable.', 'ops_query_failed' );
		}

		if ( empty( $ops['ok'] ) ) {
			return self::result( 'D14', 'unavailable', 'Unavailable', 'Moderation ops status unavailable.', 'ops_row_missing' );
		}

		if ( ! empty( $ops['circuit_open'] ) ) {
			return self::result(
				'D14',
				'warning',
				'Warning',
				'AI moderation circuit breaker is open.',
				'circuit_open'
			);
		}

		if ( ! empty( $ops['rate_limited'] ) ) {
			return self::result(
				'D14',
				'warning',
				'Warning',
				sprintf( 'AI moderation hourly rate limit reached (%d).', (int) $ops['rate_count'] ),
				'rate_limited'
			);
		}

		return self::result(
			'D14',
			'pass',
			'Pass',
			sprintf( 'AI moderation ops healthy (hourly count %d).', (int) $ops['rate_count'] ),
			'ops_ok'
		);
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d15(): array {
		try {
			$counts = AssessmentRepository::count_states_24h();
		} catch ( \Throwable $e ) {
			return self::result( 'D15', 'unavailable', 'Unavailable', 'Assessment 24h counts unavailable.', 'query_failed' );
		}

		if ( array() === $counts ) {
			return self::result(
				'D15',
				'pass',
				'Pass',
				'No terminal assessments in the last 24 hours.',
				'assessment_counts_none'
			);
		}

		$parts = array();
		foreach ( $counts as $state => $count ) {
			$parts[] = sanitize_key( (string) $state ) . '=' . (int) $count;
		}
		sort( $parts );

		return self::result(
			'D15',
			'information',
			'Information',
			'Terminal assessment counts (24h): ' . implode( ', ', $parts ) . '.',
			'assessment_counts_24h'
		);
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	public static function check_d16(): array {
		$external = Options::ai_external_enabled();
		$provider = Options::ai_provider();
		$code     = $external ? 'external_enabled_' . $provider : 'external_disabled_' . $provider;
		return self::result(
			'D16',
			$external ? 'information' : 'pass',
			$external ? 'Information' : 'Pass',
			sprintf(
				'External AI %s; provider=%s.',
				$external ? 'enabled' : 'disabled',
				sanitize_key( $provider )
			),
			sanitize_key( $code )
		);
	}

	/**
	 * @return array{id:string,status:string,headline:string,message:string,evidence_code:string}
	 */
	public static function check_d17(): array {
		$status  = CredentialResolver::status();
		$present = ! empty( $status['present'] );
		$source  = sanitize_key( (string) ( $status['source'] ?? 'missing' ) );
		return self::result(
			'D17',
			$present ? 'information' : 'pass',
			$present ? 'Information' : 'Pass',
			sprintf(
				'OpenAI credential %s (source=%s).',
				$present ? 'present' : 'missing',
				$source
			),
			$present ? 'credential_present_' . $source : 'credential_missing'
		);
	}

	/**
	 * @return array{id:string,status:string,headline:string,message:string,evidence_code:string}
	 */
	public static function check_d18(): array {
		try {
			$q = ExternalQuotaRepository::summarize();
		} catch ( \Throwable $e ) {
			return self::result( 'D18', 'unavailable', 'Unavailable', 'External quota unavailable.', 'quota_query_failed' );
		}
		if ( empty( $q['ok'] ) ) {
			return self::result( 'D18', 'unavailable', 'Unavailable', 'External quota row missing.', 'quota_row_missing' );
		}
		return self::result(
			'D18',
			'information',
			'Information',
			sprintf(
				'External quota day=%d month=%d (caps not secrets).',
				(int) $q['day_count'],
				(int) $q['month_count']
			),
			'quota_aggregate'
		);
	}

	/**
	 * M11 recommendation display + policy (informational).
	 *
	 * @return array{id:string,status:string,headline:string,message:string,evidence_code:string}
	 */
	public static function check_d19(): array {
		$display = Options::ai_recommendations_display_enabled();
		return self::result(
			'D19',
			'information',
			'Information',
			sprintf(
				'AI recommendations display %s; policy=%s; risk score: higher means greater publication risk; auto-action gated (M12 Simulation GO — masters default off; production needs Calibration GO).',
				$display ? 'enabled' : 'disabled',
				sanitize_key( \UniversalProductReviews\Ai\RecommendationPolicy::RECOMMENDATION_POLICY_VERSION )
			),
			$display ? 'recommendations_display_on' : 'recommendations_display_off'
		);
	}

	/**
	 * M12 action ledger aggregates (privacy-safe).
	 *
	 * @return array{id:string,status:string,headline:string,message:string,evidence_code:string}
	 */
	public static function check_d20(): array {
		$master = Options::ai_auto_spam_enabled();
		$policy = Options::ai_auto_spam_policy_enabled();
		$sim    = Options::ai_auto_spam_simulation_guard_enabled();
		$kill   = Options::ai_auto_spam_kill_switch();
		$dry    = Options::ai_auto_spam_dry_run();
		$counts = array(
			'processing'          => 0,
			'cas_succeeded'       => 0,
			'acted'               => 0,
			'abstained'           => 0,
			'observed'            => 0,
			'unknown_after_crash' => 0,
		);
		try {
			if ( class_exists( ActionLedgerRepository::class ) ) {
				$counts = ActionLedgerRepository::counts_by_state();
			}
		} catch ( \Throwable $e ) {
			return self::result( 'D20', 'unavailable', 'Unavailable', 'Action ledger unavailable.', 'ledger_unavailable' );
		}

		$unknown = (int) ( $counts['unknown_after_crash'] ?? 0 );
		if ( $unknown > 0 ) {
			return self::result(
				'D20',
				'critical',
				'Critical',
				sprintf(
					'Auto-spam ledger unknown_after_crash=%d (manual reconciliation; never replay WP transition hooks). Masters master=%s policy=%s sim=%s kill=%s dry=%s; acted=%d observed=%d abstained=%d.',
					$unknown,
					$master ? 'on' : 'off',
					$policy ? 'on' : 'off',
					$sim ? 'on' : 'off',
					$kill ? 'on' : 'off',
					$dry ? 'on' : 'off',
					(int) ( $counts['acted'] ?? 0 ),
					(int) ( $counts['observed'] ?? 0 ),
					(int) ( $counts['abstained'] ?? 0 )
				),
				'unknown_after_crash'
			);
		}

		return self::result(
			'D20',
			'information',
			'Information',
			sprintf(
				'Auto-spam masters default-off posture: master=%s policy=%s sim_guard=%s kill=%s dry=%s; ledger acted=%d observed=%d abstained=%d processing=%d. Production enablement requires Calibration GO.',
				$master ? 'on' : 'off',
				$policy ? 'on' : 'off',
				$sim ? 'on' : 'off',
				$kill ? 'on' : 'off',
				$dry ? 'on' : 'off',
				(int) ( $counts['acted'] ?? 0 ),
				(int) ( $counts['observed'] ?? 0 ),
				(int) ( $counts['abstained'] ?? 0 ),
				(int) ( $counts['processing'] ?? 0 )
			),
			$master || $policy || $sim ? 'auto_spam_partially_enabled' : 'auto_spam_masters_off'
		);
	}

	/**
	 * D21 assessment retention purge health (counts/ages only; no Internal\*).
	 *
	 * @return array{id:string,status:string,headline:string,message:string,evidence_code:string}
	 */
	public static function check_d21(): array {
		try {
			if ( ! self::moderation_assessment_tables_exist() ) {
				return self::result( 'D21', 'unavailable', 'Unavailable', 'Assessment retention table unavailable.', 'purge_schema_unavailable' );
			}
			$due = AssessmentRepository::count_retention_due();
		} catch ( \Throwable $e ) {
			return self::result( 'D21', 'unavailable', 'Unavailable', 'Assessment retention query failed.', 'purge_query_failed' );
		}

		$last_unix = Options::assessments_last_purge_unix();
		$age_h     = $last_unix > 0 ? ( time() - $last_unix ) / HOUR_IN_SECONDS : null;

		$purge_missing = false;
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			$purge_missing = ! as_has_scheduled_action( 'upr_purge_moderation_assessments', array(), \UniversalProductReviews\Scheduling\Jobs::GROUP );
		}

		if ( $due > 100 || ( null !== $age_h && $age_h > 72 ) || $purge_missing ) {
			return self::result(
				'D21',
				'critical',
				'Critical',
				sprintf(
					'Assessment retention purge health critical: due_count=%d; last_purge_age_h=%s; recurring_purge=%s.',
					$due,
					null === $age_h ? 'never' : (string) (int) round( $age_h ),
					$purge_missing ? 'missing' : 'present'
				),
				$purge_missing ? 'purge_job_missing' : ( $due > 100 ? 'purge_due_high' : 'purge_stale' )
			);
		}

		if ( ( $due > 0 && $due <= 100 ) || ( null !== $age_h && $age_h > 36 && $age_h <= 72 ) ) {
			return self::result(
				'D21',
				'warning',
				'Warning',
				sprintf(
					'Assessment retention purge warning: due_count=%d; last_purge_age_h=%s.',
					$due,
					null === $age_h ? 'never' : (string) (int) round( $age_h )
				),
				$due > 0 ? 'purge_due_pending' : 'purge_aging'
			);
		}

		return self::result(
			'D21',
			'information',
			'Information',
			sprintf(
				'Assessment retention purge healthy: due_count=%d; last_purge_age_h=%s.',
				$due,
				null === $age_h ? 'never' : (string) (int) round( $age_h )
			),
			'purge_healthy'
		);
	}

	private static function moderation_assessment_tables_exist(): bool {
		global $wpdb;

		foreach (
			array(
				AssessmentRepository::table(),
				AssessmentClaimsRepository::table(),
				ModerationOpsRepository::table(),
			) as $table
		) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( $found !== $table ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @return array{id:string,status:string,severity:string,message:string,evidence_code:string}
	 */
	private static function result( string $id, string $status, string $severity, string $message, string $evidence_code ): array {
		return array(
			'id'            => $id,
			'status'        => $status,
			'severity'      => $severity,
			'message'       => $message,
			'evidence_code' => $evidence_code,
		);
	}
}
