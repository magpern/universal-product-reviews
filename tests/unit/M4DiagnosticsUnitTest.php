<?php
/**
 * M4 diagnostics and support export — unit coverage.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Admin\AdminCache;
use UniversalProductReviews\Admin\Diagnostics\DiagnosticsService;
use UniversalProductReviews\Admin\SupportExport;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Database\Schema;

final class M4DiagnosticsUnitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['upr_test_options']    = array();
		$GLOBALS['upr_test_filters']    = array();
		$GLOBALS['upr_test_transients'] = array();
		$GLOBALS['upr_test_caps']       = array();
	}

	protected function tearDown(): void {
		$GLOBALS['upr_test_options']    = array();
		$GLOBALS['upr_test_filters']    = array();
		$GLOBALS['upr_test_transients'] = array();
		$GLOBALS['upr_test_caps']       = array();
		parent::tearDown();
	}

	public function test_d3_warns_only_when_emails_on_unpaused_boundary_unset(): void {
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMAILS_ENABLED ]         = 'yes';
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMERGENCY_PAUSE ]        = 'no';
		$GLOBALS['upr_test_options'][ Options::INVITATION_SCHEDULING_BOUNDARY_AT ] = '0';

		$r = DiagnosticsService::check_d3();
		$this->assertSame( 'warning', $r['status'] );
		$this->assertSame( 'boundary_unset', $r['evidence_code'] );

		// Emails off → pass (not applicable).
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMAILS_ENABLED ] = 'no';
		$r = DiagnosticsService::check_d3();
		$this->assertSame( 'pass', $r['status'] );

		// Paused with emails on → pass (not applicable).
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMAILS_ENABLED ]  = 'yes';
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMERGENCY_PAUSE ] = 'yes';
		$r = DiagnosticsService::check_d3();
		$this->assertSame( 'pass', $r['status'] );

		// Boundary set → pass.
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMERGENCY_PAUSE ]        = 'no';
		$GLOBALS['upr_test_options'][ Options::INVITATION_SCHEDULING_BOUNDARY_AT ] = '1000';
		$r = DiagnosticsService::check_d3();
		$this->assertSame( 'pass', $r['status'] );
		$this->assertSame( 'boundary_set', $r['evidence_code'] );
	}

	public function test_d2_warning_severity_when_paused(): void {
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMERGENCY_PAUSE ] = 'yes';
		$r = DiagnosticsService::check_d2();
		$this->assertSame( 'warning', $r['status'] );
		$this->assertSame( 'Warning', $r['severity'] );
		$this->assertSame( 'pause_active', $r['evidence_code'] );

		$GLOBALS['upr_test_options'][ Options::INVITATION_EMERGENCY_PAUSE ] = 'no';
		$r = DiagnosticsService::check_d2();
		$this->assertSame( 'pass', $r['status'] );
	}

	public function test_support_export_has_no_order_id_keys(): void {
		$GLOBALS['upr_test_options'][ Migrator::OPTION_VERSION ] = Schema::DB_VERSION;
		$payload = SupportExport::build();
		$this->assertSame( SupportExport::SCHEMA_VERSION, $payload['schema_version'] );
		$this->assertSame( 7, $payload['window_days'] );
		$this->assertFalse( $this->array_has_forbidden_key( $payload ) );
		$json = json_encode( $payload );
		$this->assertIsString( $json );
		foreach ( array( 'order_id', 'order_item_id', 'token_hash', 'payload_json', '@example.com' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $json );
		}
	}

	public function test_d6_uses_unavailable_or_at_least_never_exhaustive(): void {
		$r = DiagnosticsService::check_d6();
		$this->assertContains( $r['status'], array( 'pass', 'warning', 'unavailable' ) );
		if ( 'unavailable' === $r['status'] ) {
			$this->assertStringContainsString( 'unavailable', strtolower( $r['message'] ) );
		}
		if ( 'warning' === $r['status'] ) {
			$this->assertStringContainsString( 'at least', $r['message'] );
		}
		$this->assertStringNotContainsString( 'exactly', strtolower( $r['message'] ) );
		$this->assertStringNotContainsString( 'all actions', strtolower( $r['message'] ) );
	}

	public function test_plugin_bootstrap_source_has_no_page_load_migration(): void {
		$plugin = dirname( __DIR__, 2 ) . '/src/Plugin.php';
		$src    = file_get_contents( $plugin );
		$this->assertIsString( $src );
		$this->assertStringNotContainsString( 'maybe_upgrade_controlled', $src );
		$this->assertStringNotContainsString( 'schedule_db_upgrade_once', $src );
	}

	public function test_diagnostics_service_statuses_with_option_stubs(): void {
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMAILS_ENABLED ]         = 'no';
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMERGENCY_PAUSE ]        = 'no';
		$GLOBALS['upr_test_options'][ Options::INVITATION_SCHEDULING_BOUNDARY_AT ] = '0';
		$GLOBALS['upr_test_options'][ Migrator::OPTION_VERSION ]                   = Schema::DB_VERSION;
		unset( $GLOBALS['upr_test_missing_tables'] );

		AdminCache::invalidate();
		$results = DiagnosticsService::run( false );
		$this->assertCount( 15, $results );

		$by_id = array();
		foreach ( $results as $row ) {
			$by_id[ $row['id'] ] = $row;
			$this->assertContains( $row['status'], array( 'pass', 'warning', 'information', 'unavailable' ) );
			$this->assertArrayHasKey( 'severity', $row );
			$this->assertArrayHasKey( 'message', $row );
			$this->assertArrayHasKey( 'evidence_code', $row );
		}

		$this->assertSame( 'information', $by_id['D1']['status'] );
		$this->assertSame( 'pass', $by_id['D2']['status'] );
		$this->assertSame( 'pass', $by_id['D3']['status'] );
		$this->assertSame( 'pass', $by_id['D4']['status'] );
		// AS APIs absent in unit bootstrap → D5 warning, D6 unavailable.
		$this->assertSame( 'warning', $by_id['D5']['status'] );
		$this->assertSame( 'unavailable', $by_id['D6']['status'] );
		$this->assertSame( 'pass', $by_id['D10']['status'] );

		// Cache path: AdminCache get returns false initially after invalidate; second run with cache uses set value.
		$cached = DiagnosticsService::run( true );
		$this->assertCount( 15, $cached );
	}

	public function test_d4_warns_when_version_matches_but_tables_missing(): void {
		$GLOBALS['upr_test_options'][ Migrator::OPTION_VERSION ] = Schema::DB_VERSION;
		$GLOBALS['upr_test_missing_tables']                      = true;
		$r = DiagnosticsService::check_d4();
		$this->assertSame( 'warning', $r['status'] );
		$this->assertSame( 'schema_tables_missing', $r['evidence_code'] );
		unset( $GLOBALS['upr_test_missing_tables'] );
	}

	/**
	 * @param mixed $node
	 */
	private function array_has_forbidden_key( $node ): bool {
		$forbidden = array(
			'order_id',
			'order_item_id',
			'token',
			'token_hash',
			'payload_json',
			'product_name',
			'user_email',
			'billing_email',
			'customer_email',
		);
		if ( ! is_array( $node ) ) {
			return false;
		}
		foreach ( $node as $key => $value ) {
			$key_l = strtolower( (string) $key );
			if ( in_array( $key_l, $forbidden, true ) ) {
				return true;
			}
			if ( is_array( $value ) && $this->array_has_forbidden_key( $value ) ) {
				return true;
			}
		}
		return false;
	}
}
