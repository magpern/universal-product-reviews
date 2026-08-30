<?php
/**
 * admin-post handlers for M4 operator actions.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

use UniversalProductReviews\Ai\AssessmentLifecycle;
use UniversalProductReviews\Ai\OpenAi\ExternalAiTestConnection;
use UniversalProductReviews\Ai\ProviderResolver;
use UniversalProductReviews\Ai\WouldActReport;
use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Http\RewriteRules;
use UniversalProductReviews\Invitations\ReconciliationService;

defined( 'ABSPATH' ) || exit;

final class AdminActions {

	public static function register(): void {
		add_action( 'admin_post_upr_reconcile_dry_run', array( self::class, 'handle_reconcile_dry_run' ) );
		add_action( 'admin_post_upr_reconcile_apply', array( self::class, 'handle_reconcile_apply' ) );
		add_action( 'admin_post_upr_db_upgrade', array( self::class, 'handle_db_upgrade' ) );
		add_action( 'admin_post_upr_support_export', array( self::class, 'handle_support_export' ) );
		add_action( 'admin_post_upr_ai_reanalyze', array( self::class, 'handle_ai_reanalyze' ) );
		add_action( 'admin_post_upr_ai_test_connection', array( self::class, 'handle_ai_test_connection' ) );
		add_action( 'admin_post_upr_would_act_report', array( self::class, 'handle_would_act_report' ) );
		add_action( 'admin_notices', array( self::class, 'render_edit_comments_notices' ) );
		add_action( 'admin_notices', array( self::class, 'render_would_act_notice' ) );
	}

	public static function handle_reconcile_dry_run(): void {
		self::assert_cap();
		check_admin_referer( 'upr_reconcile_dry_run' );

		$lookback = isset( $_POST['upr_lookback_days'] ) ? max( 1, min( 365, (int) $_POST['upr_lookback_days'] ) ) : 90; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$summary  = ReconciliationService::run( $lookback, true );

		$args = array(
			'page'               => SettingsPage::MENU_SLUG,
			'tab'                => 'controls',
			'upr_notice'         => 'reconcile_dry_run',
			'upr_orders_scanned' => (int) ( $summary['orders_scanned'] ?? 0 ),
			'upr_rows_upserted'  => (int) ( $summary['rows_upserted'] ?? 0 ),
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_reconcile_apply(): void {
		self::assert_cap();
		check_admin_referer( 'upr_reconcile_apply' );

		if ( empty( $_POST['upr_confirm'] ) || '1' !== (string) wp_unslash( $_POST['upr_confirm'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => SettingsPage::MENU_SLUG,
						'tab'        => 'controls',
						'upr_notice' => 'confirm_required',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$lookback = isset( $_POST['upr_lookback_days'] ) ? max( 1, min( 365, (int) $_POST['upr_lookback_days'] ) ) : 90; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$summary  = ReconciliationService::run( $lookback, false );
		AdminCache::invalidate();

		$args = array(
			'page'               => SettingsPage::MENU_SLUG,
			'tab'                => 'controls',
			'upr_notice'         => 'reconcile_applied',
			'upr_orders_scanned' => (int) ( $summary['orders_scanned'] ?? 0 ),
			'upr_rows_upserted'  => (int) ( $summary['rows_upserted'] ?? 0 ),
		);
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_db_upgrade(): void {
		self::assert_cap();
		check_admin_referer( 'upr_db_upgrade' );

		if ( empty( $_POST['upr_confirm'] ) || '1' !== (string) wp_unslash( $_POST['upr_confirm'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => SettingsPage::MENU_SLUG,
						'tab'        => 'controls',
						'upr_notice' => 'confirm_required',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$from = (string) get_option( Migrator::OPTION_VERSION, '' );
		$ok   = Migrator::upgrade_now();
		$to   = (string) get_option( Migrator::OPTION_VERSION, '' );
		// Success = upgrade_now reported ok and schema is current (version + tables).
		$current = $ok && ! Migrator::needs_upgrade();
		$actor   = (int) get_current_user_id();

		if ( $current ) {
			// Flush only after verified success, and only when rewrite version actually lags.
			if ( RewriteRules::needs_flush() ) {
				RewriteRules::flush_controlled();
			}
			AuditLogger::log(
				'schema.upgraded',
				'hook',
				null,
				null,
				array(
					'actor_id' => $actor,
					'from'     => $from,
					'to'       => $to,
				)
			);
			AdminCache::invalidate();
			$notice = 'schema_upgraded';
		} else {
			AuditLogger::log(
				'schema.upgrade_failed',
				'hook',
				null,
				null,
				array(
					'actor_id' => $actor,
					'from'     => $from,
					'to'       => $to,
				)
			);
			$notice = 'schema_upgrade_failed';
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => SettingsPage::MENU_SLUG,
					'tab'        => 'controls',
					'upr_notice' => $notice,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public static function handle_support_export(): void {
		self::assert_cap();
		check_admin_referer( 'upr_support_export' );

		$payload = SupportExport::build();
		SupportExport::download_headers();
		SupportExport::output_json( $payload );
		exit;
	}

	public static function handle_ai_reanalyze(): void {
		$is_openai = 'openai' === ProviderResolver::kind();
		if ( $is_openai ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'universal-product-reviews' ), 403 );
			}
		} elseif ( ! current_user_can( 'moderate_comments' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'universal-product-reviews' ), 403 );
		}

		$comment_id = isset( $_POST['comment_id'] ) ? (int) $_POST['comment_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $comment_id <= 0 ) {
			wp_die( esc_html__( 'Invalid comment.', 'universal-product-reviews' ), 400 );
		}

		check_admin_referer( 'upr_ai_reanalyze_' . $comment_id );

		$ok = AssessmentLifecycle::request_reanalysis( $comment_id );

		wp_safe_redirect(
			add_query_arg(
				array(
					'upr_ai_notice' => $ok ? 'reanalysis_requested' : 'reanalysis_refused',
				),
				admin_url( 'edit-comments.php' )
			)
		);
		exit;
	}

	public static function handle_ai_test_connection(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'universal-product-reviews' ), 403 );
		}

		check_admin_referer( 'upr_ai_test_connection' );

		$confirmed = isset( $_POST['upr_confirm_test_connection'] ) && '1' === (string) wp_unslash( $_POST['upr_confirm_test_connection'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		if ( ! $confirmed ) {
			$code = 'connection_refused';
		} else {
			$code = ExternalAiTestConnection::run();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => SettingsPage::MENU_SLUG,
					'tab'         => 'controls',
					'upr_ai_conn' => sanitize_key( $code ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Zero-write would-act aggregate (manage_woocommerce + nonce).
	 */
	public static function handle_would_act_report(): void {
		self::assert_cap();
		check_admin_referer( 'upr_would_act_report' );

		$report = WouldActReport::build();

		$args = array(
			'page'                      => SettingsPage::MENU_SLUG,
			'tab'                       => 'overview',
			'upr_would_act'             => ! empty( $report['ok'] ) ? '1' : '0',
			'upr_would_act_error'       => sanitize_key( (string) ( $report['error_code'] ?? '' ) ),
			'upr_would_act_sampled'     => (int) ( $report['sampled_comments'] ?? 0 ),
			'upr_would_act_total'       => (int) ( $report['would_act_total'] ?? 0 ),
			'upr_pre_boundary_total'    => (int) ( $report['policy_match_pre_boundary_total'] ?? 0 ),
			'upr_would_act_boundary'    => sanitize_key( (string) ( $report['control_state']['boundary'] ?? 'unset' ) ),
		);

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render_would_act_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( empty( $_GET['page'] ) || SettingsPage::MENU_SLUG !== (string) $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! isset( $_GET['upr_would_act'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$ok = '1' === (string) wp_unslash( (string) $_GET['upr_would_act'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $ok ) {
			$code = isset( $_GET['upr_would_act_error'] ) ? sanitize_key( wp_unslash( (string) $_GET['upr_would_act_error'] ) ) : 'unexpected'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: allowlisted error code */
						__( 'Would-act report failed closed (%s). No candidates returned.', 'universal-product-reviews' ),
						$code
					)
				)
			);
			return;
		}

		$sampled = isset( $_GET['upr_would_act_sampled'] ) ? (int) $_GET['upr_would_act_sampled'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$would   = isset( $_GET['upr_would_act_total'] ) ? (int) $_GET['upr_would_act_total'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pre     = isset( $_GET['upr_pre_boundary_total'] ) ? (int) $_GET['upr_pre_boundary_total'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$bound   = isset( $_GET['upr_would_act_boundary'] ) ? sanitize_key( wp_unslash( (string) $_GET['upr_would_act_boundary'] ) ) : 'unset'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: sampled comments 2: would-act total 3: pre-boundary total 4: boundary state */
					__( 'Would-act (read-only): sampled=%1$d; would_act_if_masters_on=%2$d (boundary %4$s); policy_match_pre_boundary=%3$d (non-actionable, not would-act).', 'universal-product-reviews' ),
					$sampled,
					$would,
					$pre,
					$bound
				)
			),
			esc_html__( 'Does not change status, write audit rows, or enable auto-spam.', 'universal-product-reviews' )
		);
	}

	public static function render_edit_comments_notices(): void {
		global $pagenow;
		if ( 'edit-comments.php' !== $pagenow ) {
			return;
		}
		if ( empty( $_GET['upr_ai_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$notice = sanitize_key( wp_unslash( (string) $_GET['upr_ai_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map    = array(
			'reanalysis_requested' => array( 'success', __( 'AI re-analysis requested.', 'universal-product-reviews' ) ),
			'reanalysis_refused'   => array( 'warning', __( 'AI re-analysis was not scheduled (disabled, ineligible, or rate limited).', 'universal-product-reviews' ) ),
		);
		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $notice ][0] ),
			esc_html( $map[ $notice ][1] )
		);
	}

	private static function assert_cap(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'universal-product-reviews' ), 403 );
		}
	}
}
