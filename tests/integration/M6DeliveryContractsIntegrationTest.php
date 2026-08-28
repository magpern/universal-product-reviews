<?php
/**
 * M6 delivery contracts C1/C2 + DeliveryStatus — integration coverage.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Invitations\DeliveryStatus;
use UniversalProductReviews\Invitations\InvitationScheduler;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ScheduleStates;
use WP_UnitTestCase;

final class M6DeliveryContractsIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		delete_option( Options::INVITATION_EMAILS_ENABLED );
		delete_option( Options::INVITATION_EMERGENCY_PAUSE );
		delete_option( Options::INVITATION_SCHEDULING_BOUNDARY_AT );
	}

	public function test_confirm_valid_delivered_at_persists_and_helper(): void {
		$past = time() - ( 10 * DAY_IN_SECONDS );
		$this->upr_enable_invitation_emails( $past - DAY_IN_SECONDS );

		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$order      = $ctx['order'];
		$oid        = $order->get_id();

		InvitationScheduler::on_delivery_confirmed( $oid, array( 'delivered_at' => $past ) );

		$order = wc_get_order( $oid );
		$this->assertNotFalse( $order );
		$meta = (string) $order->get_meta( InvitationScheduler::META_DELIVERY_CONFIRMED_AT, true );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $past ), $meta );
		$this->assertTrue( DeliveryStatus::has_confirmation( $oid ) );

		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertNotNull( $row );
	}

	public function test_confirm_invalid_delivered_at_uses_wall_clock_not_raw(): void {
		$past = time() - DAY_IN_SECONDS;
		$this->upr_enable_invitation_emails( $past - DAY_IN_SECONDS );

		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$oid        = $ctx['order']->get_id();

		$before = time();
		InvitationScheduler::on_delivery_confirmed( $oid, array( 'delivered_at' => 100 ) ); // pre-2000
		$after = time();

		$order = wc_get_order( $oid );
		$meta  = (string) $order->get_meta( InvitationScheduler::META_DELIVERY_CONFIRMED_AT, true );
		$this->assertNotSame( gmdate( 'Y-m-d H:i:s', 100 ), $meta );
		$stored = strtotime( $meta . ' UTC' );
		$this->assertGreaterThanOrEqual( $before - 1, $stored );
		$this->assertLessThanOrEqual( $after + 1, $stored );
	}

	public function test_confirm_malformed_args_no_fatal_no_meta(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$oid        = $ctx['order']->get_id();

		InvitationScheduler::on_delivery_confirmed( 'not-an-id', 'not-array' );
		InvitationScheduler::on_delivery_confirmed( array( 1 ), null );
		InvitationScheduler::on_delivery_confirmed( 0, array( 'delivered_at' => time() ) );

		$order = wc_get_order( $oid );
		$this->assertSame( '', (string) $order->get_meta( InvitationScheduler::META_DELIVERY_CONFIRMED_AT, true ) );
		$this->assertFalse( DeliveryStatus::has_confirmation( $oid ) );
		$this->assertNull( InviteRepository::find( $ctx['order_item_id'] ) );
	}

	public function test_confirm_non_array_context_falls_back_timestamp(): void {
		$past = time() - DAY_IN_SECONDS;
		$this->upr_enable_invitation_emails( $past - DAY_IN_SECONDS );
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$oid        = $ctx['order']->get_id();

		$before = time();
		InvitationScheduler::on_delivery_confirmed( $oid, 'oops' );
		$after = time();

		$order = wc_get_order( $oid );
		$meta  = (string) $order->get_meta( InvitationScheduler::META_DELIVERY_CONFIRMED_AT, true );
		$stored = strtotime( $meta . ' UTC' );
		$this->assertGreaterThanOrEqual( $before - 1, $stored );
		$this->assertLessThanOrEqual( $after + 1, $stored );
	}

	public function test_invalidate_free_text_never_stored(): void {
		$past = time() - DAY_IN_SECONDS;
		$this->upr_enable_invitation_emails( $past - DAY_IN_SECONDS );
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );

		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'       => $ctx['order']->get_id(),
				'product_id'     => $product_id,
				'schedule_state' => ScheduleStates::SCHEDULED,
				'eligible_at'    => gmdate( 'Y-m-d H:i:s', $past ),
			)
		);

		$free = 'Customer called angry about delay!!!';
		InvitationScheduler::on_delivery_invalidated( $ctx['order']->get_id(), $free );

		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertNotNull( $row );
		$this->assertSame( ScheduleStates::SUPPRESSED, $row['schedule_state'] );
		$this->assertSame( 'delivery_invalidated:unspecified', $row['suppression_code'] );
		$this->assertStringNotContainsString( 'angry', (string) $row['suppression_code'] );
		$this->assertStringNotContainsString( $free, wp_json_encode( $row ) );
	}

	public function test_invalidate_valid_code_and_malformed_reason(): void {
		$past = time() - DAY_IN_SECONDS;
		$this->upr_enable_invitation_emails( $past - DAY_IN_SECONDS );
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );

		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'       => $ctx['order']->get_id(),
				'product_id'     => $product_id,
				'schedule_state' => ScheduleStates::SCHEDULED,
			)
		);

		InvitationScheduler::on_delivery_invalidated( $ctx['order']->get_id(), 'refund' );
		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( 'delivery_invalidated:refund', $row['suppression_code'] );
	}

	public function test_invalidate_malformed_args_no_fatal(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );

		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'       => $ctx['order']->get_id(),
				'product_id'     => $product_id,
				'schedule_state' => ScheduleStates::SCHEDULED,
			)
		);

		InvitationScheduler::on_delivery_invalidated( 'bad', array( 'x' => 1 ) );
		InvitationScheduler::on_delivery_invalidated( $ctx['order']->get_id(), array( 'free' => 'text' ) );

		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::SUPPRESSED, $row['schedule_state'] );
		$this->assertSame( 'delivery_invalidated:unspecified', $row['suppression_code'] );
	}

	public function test_do_action_malformed_does_not_fatal(): void {
		$past = time() - DAY_IN_SECONDS;
		$this->upr_enable_invitation_emails( $past - DAY_IN_SECONDS );
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );

		do_action( 'upr_order_delivery_confirmed', new \stdClass(), false );
		do_action( 'upr_order_delivery_invalidated', null, new \stdClass() );
		do_action( 'upr_order_delivery_confirmed', $ctx['order']->get_id(), array( 'delivered_at' => $past ) );

		$this->assertTrue( DeliveryStatus::has_confirmation( $ctx['order']->get_id() ) );
	}
}
