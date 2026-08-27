<?php
/**
 * M4 diagnostics catalogue D1–D11 (no PII).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin\Diagnostics;

use UniversalProductReviews\Admin\AdminCache;
use UniversalProductReviews\Admin\OverviewRepository;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Database\Schema;
use UniversalProductReviews\Scheduling\ActionSchedulerStatus;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsService {

	/**
	 * Run D1–D11. Results are cached ≤ 60s via AdminCache.
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
		$installed = (string) get_option( Migrator::OPTION_VERSION, '' );
		if ( $installed !== Schema::DB_VERSION ) {
			return self::result(
				'D4',
				'warning',
				'Warning',
				'Database schema version is behind the plugin target.',
				'schema_behind'
			);
		}
		return self::result( 'D4', 'pass', 'Pass', 'Database schema version matches the plugin target.', 'schema_current' );
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
