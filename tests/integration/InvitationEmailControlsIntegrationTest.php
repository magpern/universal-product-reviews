<?php
/**
 * Invitation email controls — integration coverage.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Email\LoggingMailTransport;
use UniversalProductReviews\Invitations\BundleSender;
use UniversalProductReviews\Invitations\EmergencyPause;
use UniversalProductReviews\Invitations\InvitationAuthorisation;
use UniversalProductReviews\Invitations\InvitationEmailControls;
use UniversalProductReviews\Invitations\InvitationScheduler;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ReconciliationService;
use UniversalProductReviews\Invitations\ReminderSender;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Tokens\TokenRepository;
use UniversalProductReviews\Tokens\TokenService;
use WP_UnitTestCase;

final class InvitationEmailControlsIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		LoggingMailTransport::reset();
		delete_option( Options::INVITATION_EMAILS_ENABLED );
		delete_option( Options::INVITATION_EMERGENCY_PAUSE );
		delete_option( Options::INVITATION_EMERGENCY_PAUSE_META );
		delete_option( Options::INVITATION_CONTROLS_EPOCH );
		delete_option( Options::INVITATION_SCHEDULING_BOUNDARY_AT );
		remove_all_filters( InvitationAuthorisation::FILTER );
	}

	public function tear_down(): void {
		remove_all_filters( InvitationAuthorisation::FILTER );
		LoggingMailTransport::reset();
		parent::tear_down();
	}

	public function test_default_blocks_delivery_scheduling_and_send(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$order      = $ctx['order'];
		$past       = time() - ( 30 * DAY_IN_SECONDS );
		$order->update_meta_data( InvitationScheduler::META_DELIVERY_CONFIRMED_AT, gmdate( 'Y-m-d H:i:s', $past ) );
		$order->save();

		InvitationScheduler::schedule_order( $order->get_id(), 'adapter', $past );
		$this->assertNull( InviteRepository::find( $ctx['order_item_id'] ) );

		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'        => $order->get_id(),
				'product_id'      => $product_id,
				'schedule_state'  => ScheduleStates::SCHEDULED,
				'eligible_at'     => gmdate( 'Y-m-d H:i:s', $past ),
				'source_event_at' => gmdate( 'Y-m-d H:i:s', $past ),
			)
		);
		BundleSender::send_for_order( $order->get_id() );
		$this->assertSame( 0, count( LoggingMailTransport::$sent ) );
		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::SCHEDULED, $row['schedule_state'] );
		$this->assertEmpty( $row['initial_sent_at'] );
	}

	public function test_host_not_authorised_blocks_without_mail_or_sent_state(): void {
		$past = time() - ( 30 * DAY_IN_SECONDS );
		$this->upr_enable_invitation_emails( $past - DAY_IN_SECONDS );
		add_filter(
			InvitationAuthorisation::FILTER,
			static function () {
				return array(
					'decision'    => InvitationAuthorisation::DECISION_NOT_AUTHORISED,
					'reason_code' => 'pilot_denied',
				);
			}
		);

		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		InvitationScheduler::schedule_order( $ctx['order']->get_id(), 'adapter', $past );
		$this->assertNull( InviteRepository::find( $ctx['order_item_id'] ) );

		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'        => $ctx['order']->get_id(),
				'product_id'      => $product_id,
				'schedule_state'  => ScheduleStates::SCHEDULED,
				'eligible_at'     => gmdate( 'Y-m-d H:i:s', $past ),
				'source_event_at' => gmdate( 'Y-m-d H:i:s', $past ),
			)
		);
		BundleSender::send_for_order( $ctx['order']->get_id() );
		$this->assertSame( 0, count( LoggingMailTransport::$sent ) );
		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertEmpty( $row['initial_sent_at'] );
	}

	public function test_delivery_while_disabled_then_enable_reconcile_does_not_retro_send(): void {
		$this->upr_use_logging_mail_transport();
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$past       = time() - ( 30 * DAY_IN_SECONDS );

		InvitationScheduler::on_delivery_confirmed(
			$ctx['order']->get_id(),
			array( 'delivered_at' => $past )
		);
		$order = wc_get_order( $ctx['order']->get_id() );
		$this->assertNotEmpty( $order->get_meta( InvitationScheduler::META_DELIVERY_CONFIRMED_AT ) );
		$this->assertNull( InviteRepository::find( $ctx['order_item_id'] ) );

		InvitationEmailControls::set_emails_enabled( true );
		$this->assertGreaterThan( $past, Options::invitation_scheduling_boundary_unix() );

		$summary = ReconciliationService::run( 90, false );
		$this->assertNull( InviteRepository::find( $ctx['order_item_id'] ) );
		$this->assertSame( 0, count( LoggingMailTransport::$sent ) );
		$this->assertGreaterThan( 0, (int) $summary['authorisation_denied_skipped'] );

		BundleSender::send_for_order( $ctx['order']->get_id() );
		ReminderSender::send_for_item( $ctx['order_item_id'] );
		$this->assertSame( 0, count( LoggingMailTransport::$sent ) );
		$this->assertNull( InviteRepository::find( $ctx['order_item_id'] ) );
	}

	public function test_completed_while_disabled_then_enable_reconcile_does_not_retro_send(): void {
		$this->upr_use_logging_mail_transport();
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );

		InvitationScheduler::on_order_completed( $ctx['order']->get_id() );
		$this->assertNull( InviteRepository::find( $ctx['order_item_id'] ) );

		InvitationEmailControls::set_emails_enabled( true );
		ReconciliationService::run( 90, false );

		$this->assertNull( InviteRepository::find( $ctx['order_item_id'] ) );
		$this->assertSame( 0, count( LoggingMailTransport::$sent ) );
	}

	public function test_delivery_while_paused_then_unpause_reconcile_does_not_retro_send(): void {
		$this->upr_use_logging_mail_transport();
		$past = time() - ( 30 * DAY_IN_SECONDS );
		$this->upr_enable_invitation_emails( $past - DAY_IN_SECONDS );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		EmergencyPause::set_paused( true, 'ops incident', $user_id );

		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		InvitationScheduler::on_delivery_confirmed(
			$ctx['order']->get_id(),
			array( 'delivered_at' => $past )
		);
		$this->assertNull( InviteRepository::find( $ctx['order_item_id'] ) );

		EmergencyPause::set_paused( false, 'cleared', $user_id );
		$this->assertGreaterThan( $past, Options::invitation_scheduling_boundary_unix() );

		ReconciliationService::run( 90, false );
		$this->assertNull( InviteRepository::find( $ctx['order_item_id'] ) );
		$this->assertSame( 0, count( LoggingMailTransport::$sent ) );
	}

	public function test_emergency_pause_revokes_tokens_and_blocks_retro_reminder_after_unpause(): void {
		$this->upr_use_logging_mail_transport();
		$past = time() - ( 30 * DAY_IN_SECONDS );
		$this->upr_enable_invitation_emails( $past - DAY_IN_SECONDS );

		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );

		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'         => $ctx['order']->get_id(),
				'product_id'       => $product_id,
				'schedule_state'   => ScheduleStates::INITIAL_SENT,
				'initial_sent_at'  => gmdate( 'Y-m-d H:i:s', $past ),
				'source_event_at'  => gmdate( 'Y-m-d H:i:s', $past ),
				'eligible_at'      => gmdate( 'Y-m-d H:i:s', $past ),
			)
		);
		$issued = TokenService::issue_invite( $ctx['order_item_id'], $product_id );
		$this->assertNotNull( $issued );
		TokenService::exchange_invite( $issued['raw'] );

		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		EmergencyPause::set_paused( true, 'ops incident', $user_id );

		$invite = TokenRepository::find_by_id( (int) $issued['id'] );
		$this->assertNotEmpty( $invite['revoked_at'] );

		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::INITIAL_SENT, $row['schedule_state'] );

		ReminderSender::send_for_item( $ctx['order_item_id'] );
		$this->assertSame( 0, count( LoggingMailTransport::$sent ) );

		EmergencyPause::set_paused( false, 'cleared', $user_id );
		LoggingMailTransport::reset();
		ReminderSender::send_for_item( $ctx['order_item_id'] );
		$this->assertSame( 0, count( LoggingMailTransport::$sent ), 'unpause must not retro-send reminder for pre-boundary initial_sent' );
		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertEmpty( $row['reminder_sent_at'] );
		$this->assertSame( ScheduleStates::INITIAL_SENT, $row['schedule_state'] );

		global $wpdb;
		$pause_events = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}upr_audit WHERE event_type = 'invite.emergency_pause'"
		);
		$this->assertGreaterThanOrEqual( 1, $pause_events );
	}

	public function test_new_eligible_event_after_enable_allows_normal_flow(): void {
		$this->upr_use_logging_mail_transport();
		InvitationEmailControls::set_emails_enabled( true );
		$boundary = Options::invitation_scheduling_boundary_unix();
		$this->assertGreaterThan( 0, $boundary );

		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		// Source after boundary but old enough that delay window is already due.
		$event = $boundary + 1;
		// Force delay to 0 for this assertion path via temporary option.
		update_option( Options::DELAY_AFTER_DELIVERY, 0, false );

		$ctx['order']->update_meta_data( InvitationScheduler::META_DELIVERY_CONFIRMED_AT, gmdate( 'Y-m-d H:i:s', $event ) );
		$ctx['order']->save();

		InvitationScheduler::schedule_order( $ctx['order']->get_id(), 'adapter', $event );
		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertNotNull( $row );
		$this->assertSame( ScheduleStates::SCHEDULED, $row['schedule_state'] );

		BundleSender::send_for_order( $ctx['order']->get_id() );
		$this->assertSame( 1, count( LoggingMailTransport::$sent ) );
		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::INITIAL_SENT, $row['schedule_state'] );
		$this->assertNotEmpty( $row['initial_sent_at'] );
	}

	public function test_allowed_flow_sends_via_logging_transport(): void {
		$past = time() - ( 30 * DAY_IN_SECONDS );
		$this->upr_enable_invitation_emails( $past - DAY_IN_SECONDS );
		$this->upr_use_logging_mail_transport();
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$ctx['order']->update_meta_data( InvitationScheduler::META_DELIVERY_CONFIRMED_AT, gmdate( 'Y-m-d H:i:s', $past ) );
		$ctx['order']->save();

		InvitationScheduler::schedule_order( $ctx['order']->get_id(), 'adapter', $past );
		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertNotNull( $row );
		$this->assertSame( ScheduleStates::SCHEDULED, $row['schedule_state'] );

		BundleSender::send_for_order( $ctx['order']->get_id() );
		$this->assertSame( 1, count( LoggingMailTransport::$sent ) );
		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::INITIAL_SENT, $row['schedule_state'] );
		$this->assertNotEmpty( $row['initial_sent_at'] );
	}

	public function test_reconcile_idempotent_without_auth_audit_storm(): void {
		delete_option( Options::INVITATION_EMAILS_ENABLED );
		$product_id = $this->upr_create_product();
		$this->upr_create_order_with_item( $product_id );

		global $wpdb;
		$before = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}upr_audit WHERE event_type = 'invite.authorisation_denied'"
		);
		$summary = ReconciliationService::run( 90, false );
		$this->assertArrayHasKey( 'authorisation_denied_skipped', $summary );
		$after = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}upr_audit WHERE event_type = 'invite.authorisation_denied'"
		);
		$this->assertSame( $before, $after );
		$summary2 = ReconciliationService::run( 90, false );
		$this->assertSame( $summary['orders_scanned'], $summary2['orders_scanned'] );
	}

	public function test_completed_fallback_blocked_when_disabled(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		InvitationScheduler::on_order_completed( $ctx['order']->get_id() );
		$this->assertNull( InviteRepository::find( $ctx['order_item_id'] ) );
	}
}
