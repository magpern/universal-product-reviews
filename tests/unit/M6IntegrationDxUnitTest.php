<?php
/**
 * M6 delivery event normalisation and integration readiness — unit coverage.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Admin\AdminCache;
use UniversalProductReviews\Admin\Diagnostics\DiagnosticsService;
use UniversalProductReviews\Admin\Diagnostics\IntegrationReadiness;
use UniversalProductReviews\Admin\SupportExport;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Database\Schema;
use UniversalProductReviews\Invitations\DeliveryEventNormaliser;
use UniversalProductReviews\Invitations\DeliveryStatus;

final class M6IntegrationDxUnitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['upr_test_options']    = array();
		$GLOBALS['upr_test_filters']    = array();
		$GLOBALS['upr_test_transients'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['upr_test_options']    = array();
		$GLOBALS['upr_test_filters']    = array();
		$GLOBALS['upr_test_transients'] = array();
		parent::tearDown();
	}

	public function test_normalize_order_id_matrix(): void {
		$this->assertSame( 12, DeliveryEventNormaliser::normalize_order_id( 12 ) );
		$this->assertSame( 12, DeliveryEventNormaliser::normalize_order_id( '12' ) );
		$this->assertNull( DeliveryEventNormaliser::normalize_order_id( 0 ) );
		$this->assertNull( DeliveryEventNormaliser::normalize_order_id( -1 ) );
		$this->assertNull( DeliveryEventNormaliser::normalize_order_id( '12.5' ) );
		$this->assertNull( DeliveryEventNormaliser::normalize_order_id( array( 1 ) ) );
		$this->assertNull( DeliveryEventNormaliser::normalize_order_id( null ) );
		$this->assertNull( DeliveryEventNormaliser::normalize_order_id( new \stdClass() ) );
	}

	public function test_normalize_context_non_array_becomes_empty(): void {
		$this->assertSame( array(), DeliveryEventNormaliser::normalize_context( null ) );
		$this->assertSame( array(), DeliveryEventNormaliser::normalize_context( 'x' ) );
		$this->assertSame( array( 'delivered_at' => 1 ), DeliveryEventNormaliser::normalize_context( array( 'delivered_at' => 1 ) ) );
	}

	public function test_normalize_delivered_at_matrix(): void {
		$now = 1_700_000_000;
		$this->assertSame( $now, DeliveryEventNormaliser::normalize_delivered_at( array(), $now ) );
		$this->assertSame( $now, DeliveryEventNormaliser::normalize_delivered_at( array( 'delivered_at' => 0 ), $now ) );
		$this->assertSame( $now, DeliveryEventNormaliser::normalize_delivered_at( array( 'delivered_at' => -5 ), $now ) );
		$this->assertSame( $now, DeliveryEventNormaliser::normalize_delivered_at( array( 'delivered_at' => 'nope' ), $now ) );
		$this->assertSame( $now, DeliveryEventNormaliser::normalize_delivered_at( array( 'delivered_at' => 100 ), $now ) ); // pre-2000
		$this->assertSame( $now, DeliveryEventNormaliser::normalize_delivered_at( array( 'delivered_at' => $now + 90000 ), $now ) );
		$valid = $now - 3600;
		$this->assertSame( $valid, DeliveryEventNormaliser::normalize_delivered_at( array( 'delivered_at' => $valid ), $now ) );
		$this->assertSame( $valid, DeliveryEventNormaliser::normalize_delivered_at( array( 'delivered_at' => (string) $valid ), $now ) );
	}

	public function test_normalize_reason_matrix(): void {
		$this->assertSame( 'unspecified', DeliveryEventNormaliser::normalize_reason( null ) );
		$this->assertSame( 'unspecified', DeliveryEventNormaliser::normalize_reason( array( 'x' ) ) );
		$this->assertSame( 'unspecified', DeliveryEventNormaliser::normalize_reason( 12 ) );
		$this->assertSame( 'unspecified', DeliveryEventNormaliser::normalize_reason( '' ) );
		$this->assertSame( 'unspecified', DeliveryEventNormaliser::normalize_reason( '   ' ) );
		$this->assertSame( 'unspecified', DeliveryEventNormaliser::normalize_reason( 'Cancel!' ) );
		$this->assertSame( 'unspecified', DeliveryEventNormaliser::normalize_reason( 'user@example.com said no' ) );
		$this->assertSame( 'cancel', DeliveryEventNormaliser::normalize_reason( 'cancel' ) );
		$this->assertSame( 'delivery_reversed', DeliveryEventNormaliser::normalize_reason( ' delivery_reversed ' ) );
		$this->assertSame(
			'delivery_invalidated:unspecified',
			DeliveryEventNormaliser::compose_invalidation_code( 'FREE TEXT PLEASE IGNORE' )
		);
	}

	public function test_i1_information_when_lookup_missing(): void {
		$r = IntegrationReadiness::check_i1();
		$this->assertSame( 'I1', $r['id'] );
		$this->assertSame( 'information', $r['status'] );
		$this->assertSame( 'lookup_not_detected', $r['evidence_code'] );
		$this->assertStringContainsString( 'not detected', strtolower( $r['message'] ) );
	}

	public function test_i3_enum_default_custom(): void {
		$this->assertSame( 'default', IntegrationReadiness::mail_transport_mode() );
		$r = IntegrationReadiness::check_i3();
		$this->assertSame( 'mail_default', $r['evidence_code'] );

		add_filter( 'upr_mail_transport', static function ( $t ) {
			return $t;
		} );
		$this->assertSame( 'custom', IntegrationReadiness::mail_transport_mode() );
		$r = IntegrationReadiness::check_i3();
		$this->assertSame( 'mail_custom', $r['evidence_code'] );
		$this->assertStringNotContainsString( 'MailTransport', $r['message'] );
	}

	public function test_i5_core_availability_wording(): void {
		$r = IntegrationReadiness::check_i5();
		$this->assertSame( 'pass', $r['status'] );
		$this->assertSame( 'Core availability service present.', $r['message'] );
		$this->assertSame( 'availability_present', $r['evidence_code'] );
	}

	public function test_readiness_not_in_diag_cache_and_support_export(): void {
		AdminCache::set(
			array(
				'generated_at' => time(),
				'results'      => array(
					array(
						'id'            => 'D1',
						'status'        => 'pass',
						'severity'      => 'Pass',
						'message'       => 'cached',
						'evidence_code' => 'emails_enabled',
					),
				),
			)
		);

		$d = DiagnosticsService::run( true );
		$this->assertCount( 1, $d );
		$this->assertSame( 'D1', $d[0]['id'] );

		$i = IntegrationReadiness::run();
		$this->assertCount( 5, $i );
		$this->assertSame( 'I1', $i[0]['id'] );

		$GLOBALS['upr_test_options'][ Migrator::OPTION_VERSION ] = Schema::DB_VERSION;
		$payload = SupportExport::build();
		$this->assertSame( SupportExport::SCHEMA_VERSION, $payload['schema_version'] );
		$json = (string) json_encode( $payload );
		$this->assertStringNotContainsString( '"I1"', $json );
		$this->assertStringNotContainsString( 'lookup_not_detected', $json );
	}

	public function test_delivery_status_false_for_invalid_id(): void {
		$this->assertFalse( DeliveryStatus::has_confirmation( 0 ) );
		$this->assertFalse( DeliveryStatus::has_confirmation( -3 ) );
	}
}
