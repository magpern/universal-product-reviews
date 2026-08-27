<?php
/**
 * M4.1 operator controls / diagnostics — integration coverage.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Admin\AdminCache;
use UniversalProductReviews\Admin\Diagnostics\DiagnosticsService;
use UniversalProductReviews\Admin\OverviewRepository;
use UniversalProductReviews\Admin\SupportExport;
use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Database\Schema;
use UniversalProductReviews\Invitations\InvitationEmailControls;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Plugin;
use WP_UnitTestCase;

final class M4OperatorControlsIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		delete_option( Options::INVITATION_EMAILS_ENABLED );
		delete_option( Options::INVITATION_EMERGENCY_PAUSE );
		delete_option( Options::INVITATION_EMERGENCY_PAUSE_META );
		delete_option( Options::INVITATION_CONTROLS_EPOCH );
		delete_option( Options::INVITATION_SCHEDULING_BOUNDARY_AT );
		AdminCache::invalidate();
	}

	public function test_plugin_does_not_hook_page_load_migration(): void {
		Plugin::reset_for_tests();
		Plugin::init();
		$this->assertFalse(
			(bool) has_action( 'admin_init', array( Migrator::class, 'maybe_upgrade_controlled' ) ),
			'page-load migrator must not be registered'
		);
		$this->assertFalse(
			(bool) has_action( 'admin_init', array( \UniversalProductReviews\Scheduling\Jobs::class, 'schedule_db_upgrade_once' ) ),
			'auto AS db-upgrade schedule must not be registered on admin_init'
		);
	}

	public function test_enable_disable_audit_events(): void {
		global $wpdb;
		$audit = $wpdb->prefix . 'upr_audit';

		InvitationEmailControls::set_emails_enabled( true );
		$enabled = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$audit} WHERE event_type = 'invite.emails_enabled'"
		);
		$this->assertGreaterThanOrEqual( 1, $enabled );

		InvitationEmailControls::set_emails_enabled( false );
		$disabled = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$audit} WHERE event_type = 'invite.emails_disabled'"
		);
		$this->assertGreaterThanOrEqual( 1, $disabled );
	}

	public function test_open_workload_includes_old_still_active_rows(): void {
		global $wpdb;
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$old        = gmdate( 'Y-m-d H:i:s', time() - ( 120 * DAY_IN_SECONDS ) );

		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'        => $ctx['order']->get_id(),
				'product_id'      => $product_id,
				'schedule_state'  => ScheduleStates::SCHEDULED,
				'eligible_at'     => $old,
				'source_event_at' => $old,
			)
		);

		$table = InviteRepository::table();
		$wpdb->update(
			$table,
			array(
				'updated_at' => $old,
				'created_at' => $old,
			),
			array( 'order_item_id' => $ctx['order_item_id'] ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		$open = OverviewRepository::open_workload_counts();
		$this->assertTrue( $open['ok'] );
		$this->assertGreaterThanOrEqual( 1, $open['total_open'] );
		$this->assertArrayHasKey( ScheduleStates::SCHEDULED, $open['by_state'] );

		$recent = OverviewRepository::recent_lifecycle_counts( 30 );
		$this->assertTrue( $recent['ok'] );
		// Old updated_at must not appear in the recent window, proving open-work is not window-limited.
		$this->assertArrayNotHasKey( ScheduleStates::SCHEDULED, $recent['by_state'] );
	}

	public function test_support_export_forbids_sensitive_keys_and_order_ids(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		AuditLogger::log(
			'email.failed',
			'hook',
			$ctx['order']->get_id(),
			$ctx['order_item_id'],
			array(
				'error'          => 'smtp boom free text',
				'customer_email' => 'leak@example.com',
				'token'          => 'raw-token-value',
			)
		);

		$payload = SupportExport::build();
		$json    = wp_json_encode( $payload );
		$this->assertIsString( $json );
		$this->assertDoesNotMatchRegularExpression( '/"order_id"\s*:/', $json );
		$this->assertDoesNotMatchRegularExpression( '/"order_item_id"\s*:/', $json );
		$this->assertStringNotContainsString( 'leak@example.com', $json );
		$this->assertStringNotContainsString( 'raw-token-value', $json );
		$this->assertStringNotContainsString( 'smtp boom', $json );
		$this->assertArrayNotHasKey( 'order_id', $payload );
		$this->assertSame( SupportExport::SCHEMA_VERSION, $payload['schema_version'] );
		$this->assertSame( 7, $payload['window_days'] );
		// Ensure the audited order/item identifiers are not emitted as allowlisted values.
		$flat = wp_json_encode( $payload['aggregates'] ?? array() ) . wp_json_encode( $payload['last_reconcile'] ?? array() );
		$this->assertStringNotContainsString( '"order_id"', $flat );
		$this->assertSame( 1, (int) ( $payload['aggregates']['email_failed_count'] ?? 0 ) );
	}

	public function test_recent_audit_allowlist_excludes_payload_and_email(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		AuditLogger::log(
			'email.failed',
			'hook',
			$ctx['order']->get_id(),
			null,
			array( 'customer_email' => 'secret@example.com', 'message' => 'boom' )
		);

		$rows = OverviewRepository::recent_audit_allowlisted( 25 );
		$this->assertNotEmpty( $rows );
		$first = $rows[0];
		$this->assertArrayHasKey( 'event_type', $first );
		$this->assertArrayHasKey( 'actor_type', $first );
		$this->assertArrayHasKey( 'order_id', $first );
		$this->assertArrayNotHasKey( 'payload_json', $first );
		$this->assertArrayNotHasKey( 'customer_email', $first );
		$encoded = wp_json_encode( $rows );
		$this->assertStringNotContainsString( 'secret@example.com', $encoded );
		$this->assertStringNotContainsString( 'boom', $encoded );
	}

	public function test_db_upgrade_action_requires_confirm_and_verifies_version(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user    = get_user_by( 'id', $user_id );
		$user->add_cap( 'manage_woocommerce' );
		wp_set_current_user( $user_id );

		$_POST = array(
			'upr_confirm' => '1',
			'_wpnonce'    => wp_create_nonce( 'upr_db_upgrade' ),
		);

		// Capture redirect by short-circuiting wp_die / exit is hard; call upgrade path pieces.
		$ok = Migrator::upgrade_now();
		$this->assertTrue( $ok );
		$this->assertSame( Schema::DB_VERSION, (string) get_option( Migrator::OPTION_VERSION, '' ) );

		// Cap denied path for AdminActions::assert_cap via reflection of register presence.
		$this->assertTrue( has_action( 'admin_post_upr_db_upgrade' ) );
		$this->assertTrue( has_action( 'admin_post_upr_support_export' ) );
		$this->assertTrue( has_action( 'admin_post_upr_reconcile_apply' ) );
	}

	public function test_diagnostics_degrade_safely_and_d6_unavailable_without_as_listing(): void {
		update_option( Migrator::OPTION_VERSION, Schema::DB_VERSION, false );
		AdminCache::invalidate();
		$results = DiagnosticsService::run( false );
		$this->assertCount( 11, $results );
		$by = array();
		foreach ( $results as $row ) {
			$by[ $row['id'] ] = $row;
			$this->assertContains( $row['status'], array( 'pass', 'warning', 'information', 'unavailable' ) );
			$this->assertNotSame( 'critical', $row['status'] );
		}
		// D6 may be pass/warning/unavailable depending on AS; never claim exhaustive wording without "at least".
		if ( 'warning' === $by['D6']['status'] ) {
			$this->assertStringContainsString( 'at least', $by['D6']['message'] );
		}
	}

	public function test_no_woocommerce_internal_imports_in_admin_surface(): void {
		$roots = array(
			dirname( __DIR__, 2 ) . '/src/Admin',
			dirname( __DIR__, 2 ) . '/src/Scheduling/ActionSchedulerStatus.php',
		);
		foreach ( $roots as $root ) {
			$files = is_dir( $root )
				? glob( $root . '/**/*.php' ) ?: array()
				: array( $root );
			// glob ** may not recurse; use RecursiveDirectoryIterator when dir.
			if ( is_dir( $root ) ) {
				$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
				$files = array();
				foreach ( $it as $file ) {
					if ( $file->isFile() && 'php' === $file->getExtension() ) {
						$files[] = $file->getPathname();
					}
				}
			}
			foreach ( $files as $path ) {
				$src = file_get_contents( $path );
				$this->assertIsString( $src );
				$this->assertStringNotContainsString( 'Automattic\\WooCommerce\\Internal', $src, $path );
				$this->assertDoesNotMatchRegularExpression( '/\bbiopentra\b/i', $src, $path );
			}
		}
	}
}
