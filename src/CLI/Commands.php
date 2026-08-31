<?php
/**
 * WP-CLI commands.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CLI;

use UniversalProductReviews\Ai\ActionLedgerRepository;
use UniversalProductReviews\Ai\ActionPolicy;
use UniversalProductReviews\Ai\AssessmentRepository;
use UniversalProductReviews\Ai\WouldActReport;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Invitations\EmergencyPause;
use UniversalProductReviews\Invitations\ReconciliationService;

defined( 'ABSPATH' ) || exit;

final class Commands {

	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}
		\WP_CLI::add_command( 'upr reconcile-invitations', array( self::class, 'reconcile' ) );
		\WP_CLI::add_command( 'upr db-upgrade', array( self::class, 'db_upgrade' ) );
		\WP_CLI::add_command( 'upr invitation-controls', array( self::class, 'invitation_controls' ) );
		\WP_CLI::add_command( 'upr ai-status', array( self::class, 'ai_status' ) );
		\WP_CLI::add_command( 'upr would-act', array( self::class, 'would_act' ) );
		\WP_CLI::add_command( 'upr ledger-summary', array( self::class, 'ledger_summary' ) );
	}

	/**
	 * Reconcile invitation schedules.
	 *
	 * ## OPTIONS
	 *
	 * [--lookback-days=<days>]
	 * : Lookback window. Default 90.
	 *
	 * [--dry-run]
	 * : Print planned actions with zero writes (including no audit).
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function reconcile( array $args, array $assoc ): void {
		unset( $args );
		$lookback = isset( $assoc['lookback-days'] ) ? (int) $assoc['lookback-days'] : 90;
		$dry_run  = isset( $assoc['dry-run'] );
		$summary  = ReconciliationService::run( $lookback, $dry_run );
		\WP_CLI::log( wp_json_encode( $summary, JSON_PRETTY_PRINT ) );
		\WP_CLI::success( $dry_run ? 'Dry-run complete (zero writes).' : 'Reconcile complete.' );
	}

	public static function db_upgrade(): void {
		$ok = Migrator::upgrade_now();
		\UniversalProductReviews\Http\RewriteRules::flush_controlled();
		if ( $ok ) {
			\WP_CLI::success( 'Database schema is up to date.' );
		} else {
			\WP_CLI::error( 'Database upgrade did not complete.' );
		}
	}

	/**
	 * Show invitation email control status (no PII).
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function invitation_controls( array $args, array $assoc ): void {
		unset( $args, $assoc );
		$meta = EmergencyPause::meta();
		\WP_CLI::log(
			wp_json_encode(
				array(
					'invitation_emails_enabled'  => Options::invitation_emails_enabled(),
					'invitation_emergency_pause' => Options::invitation_emergency_pause(),
					'controls_epoch'             => Options::invitation_controls_epoch(),
					'scheduling_boundary_unix'   => Options::invitation_scheduling_boundary_unix(),
					'pause_meta'                 => array(
						'reason'     => $meta['reason'],
						'actor_id'   => $meta['actor_id'],
						'changed_at' => $meta['changed_at'],
					),
				),
				JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * Privacy-safe AI moderation posture (read-only).
	 *
	 * ## OPTIONS
	 *
	 * --user=<id|login>
	 * : WordPress user that must have manage_woocommerce.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function ai_status( array $args, array $assoc ): void {
		unset( $args );
		self::require_manage_woocommerce_user( $assoc );

		$ledger = array();
		try {
			$ledger = ActionLedgerRepository::counts_by_state();
		} catch ( \Throwable $e ) {
			$ledger = array( 'error' => 'unavailable' );
		}

		$assess_24 = array();
		try {
			$assess_24 = AssessmentRepository::count_states_24h();
		} catch ( \Throwable $e ) {
			$assess_24 = array( 'error' => 'unavailable' );
		}

		\WP_CLI::log(
			wp_json_encode(
				array(
					'master'           => Options::ai_auto_spam_enabled(),
					'policy'           => Options::ai_auto_spam_policy_enabled(),
					'simulation'       => Options::ai_auto_spam_simulation_guard_enabled(),
					'kill'             => Options::ai_auto_spam_kill_switch(),
					'dry_run'          => Options::ai_auto_spam_dry_run(),
					'boundary'         => Options::ai_auto_action_boundary_unix() > 0 ? 'set' : 'unset',
					'tuple_fingerprint'=> substr( ActionPolicy::active_tuple_fingerprint(), 0, 16 ),
					'ledger'           => $ledger,
					'assessments_24h'  => $assess_24,
				),
				JSON_PRETTY_PRINT
			)
		);
	}

	/**
	 * Zero-write would-act aggregate report.
	 *
	 * ## OPTIONS
	 *
	 * --user=<id|login>
	 * : WordPress user that must have manage_woocommerce.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function would_act( array $args, array $assoc ): void {
		unset( $args );
		self::require_manage_woocommerce_user( $assoc );
		$report = WouldActReport::build();
		\WP_CLI::log( wp_json_encode( $report, JSON_PRETTY_PRINT ) );
		if ( empty( $report['ok'] ) ) {
			\WP_CLI::error( 'Would-act failed closed: ' . sanitize_key( (string) ( $report['error_code'] ?? 'unexpected' ) ) );
		}
	}

	/**
	 * Action ledger state counts (read-only).
	 *
	 * ## OPTIONS
	 *
	 * --user=<id|login>
	 * : WordPress user that must have manage_woocommerce.
	 *
	 * @param array<int, string>    $args
	 * @param array<string, string> $assoc
	 */
	public static function ledger_summary( array $args, array $assoc ): void {
		unset( $args );
		self::require_manage_woocommerce_user( $assoc );
		try {
			$counts = ActionLedgerRepository::counts_by_state();
		} catch ( \Throwable $e ) {
			\WP_CLI::error( 'Ledger summary unavailable.' );
			return;
		}
		\WP_CLI::log( wp_json_encode( $counts, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Require --user= and manage_woocommerce. No shell/root trust bypass.
	 *
	 * @param array<string, string> $assoc Assoc args.
	 */
	private static function require_manage_woocommerce_user( array $assoc ): void {
		$raw = '';
		if ( isset( $assoc['user'] ) && '' !== (string) $assoc['user'] ) {
			$raw = (string) $assoc['user'];
		} elseif ( function_exists( 'get_current_user_id' ) && get_current_user_id() > 0 ) {
			// WP-CLI global --user is not passed in $assoc when it shares the flag name.
			$raw = (string) get_current_user_id();
		}

		if ( '' === $raw ) {
			\WP_CLI::error( 'Missing required --user=<id|login> with manage_woocommerce.' );
		}
		$user = ctype_digit( $raw ) ? get_user_by( 'id', (int) $raw ) : get_user_by( 'login', $raw );
		if ( ! $user || ! ( $user instanceof \WP_User ) ) {
			\WP_CLI::error( 'Invalid --user; cannot resolve WordPress user.' );
		}

		wp_set_current_user( (int) $user->ID );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			\WP_CLI::error( 'User lacks manage_woocommerce; refusing without partial output.' );
		}
	}
}
