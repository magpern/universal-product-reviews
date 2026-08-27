<?php
/**
 * Action Scheduler bridge (group upr).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Scheduling;

use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Http\RewriteRules;
use UniversalProductReviews\Invitations\BundleSender;
use UniversalProductReviews\Invitations\InvitationScheduler;
use UniversalProductReviews\Invitations\ReconciliationService;
use UniversalProductReviews\Invitations\ReminderSender;

defined( 'ABSPATH' ) || exit;

final class Jobs {

	public const GROUP = 'upr';

	public static function register(): void {
		add_action( 'upr_schedule_order_items', array( self::class, 'handle_schedule_order' ), 10, 3 );
		add_action( 'upr_send_initial_bundle', array( self::class, 'handle_send_initial' ), 10, 1 );
		add_action( 'upr_send_reminder_item', array( self::class, 'handle_send_reminder' ), 10, 1 );
		add_action( 'upr_reconcile_invitations', array( self::class, 'handle_reconcile' ), 10, 0 );
		add_action( 'upr_db_upgrade', array( self::class, 'handle_db_upgrade' ), 10, 0 );

		add_action( 'init', array( self::class, 'ensure_nightly_reconcile' ), 20 );
	}

	public static function ensure_nightly_reconcile(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
			return;
		}
		if ( ! as_has_scheduled_action( 'upr_reconcile_invitations', array(), self::GROUP ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, 'upr_reconcile_invitations', array(), self::GROUP );
		}
	}

	public static function schedule_order_items( int $order_id, string $source, ?int $source_event_unix = null ): void {
		if ( ! InvitationScheduler::core_controls_allow_scheduling() ) {
			return;
		}
		$source_event_unix = $source_event_unix ?? time();
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			InvitationScheduler::schedule_order( $order_id, $source, $source_event_unix );
			return;
		}
		as_enqueue_async_action(
			'upr_schedule_order_items',
			array( $order_id, $source, $source_event_unix ),
			self::GROUP,
			true
		);
	}

	public static function schedule_initial_bundle( int $order_id, string $eligible_at_gmt ): void {
		if ( ! InvitationScheduler::core_controls_allow_scheduling() ) {
			return;
		}
		$ts = strtotime( $eligible_at_gmt . ' UTC' );
		if ( ! $ts ) {
			$ts = time();
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action( $ts, 'upr_send_initial_bundle', array( $order_id ), self::GROUP, true );
	}

	public static function schedule_reminder( int $order_item_id, int $days ): void {
		if ( ! InvitationScheduler::core_controls_allow_scheduling() ) {
			return;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action(
			time() + ( $days * DAY_IN_SECONDS ),
			'upr_send_reminder_item',
			array( $order_item_id ),
			self::GROUP,
			true
		);
	}

	public static function unschedule_item( int $order_item_id ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'upr_send_reminder_item', array( $order_item_id ), self::GROUP );
		}
	}

	/**
	 * Best-effort cancel of pending invitation schedule/send actions (emergency pause).
	 * Handlers must still no-op if a raced action executes.
	 */
	public static function cancel_pending_invitation_sends(): void {
		if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		as_unschedule_all_actions( 'upr_send_initial_bundle', null, self::GROUP );
		as_unschedule_all_actions( 'upr_send_reminder_item', null, self::GROUP );
		as_unschedule_all_actions( 'upr_schedule_order_items', null, self::GROUP );
	}

	public static function schedule_db_upgrade_once(): void {
		if ( ! Migrator::needs_upgrade() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'upr_db_upgrade', array(), self::GROUP ) ) {
			return;
		}
		as_enqueue_async_action( 'upr_db_upgrade', array(), self::GROUP, true );
	}

	public static function handle_schedule_order( int $order_id, string $source, int $source_event_unix = 0 ): void {
		InvitationScheduler::schedule_order( $order_id, $source, $source_event_unix > 0 ? $source_event_unix : null );
	}

	public static function handle_send_initial( int $order_id ): void {
		BundleSender::send_for_order( $order_id );
	}

	public static function handle_send_reminder( int $order_item_id ): void {
		ReminderSender::send_for_item( $order_item_id );
	}

	public static function handle_reconcile(): void {
		ReconciliationService::run( 90, false );
	}

	public static function handle_db_upgrade(): void {
		Migrator::upgrade_now();
		RewriteRules::flush_controlled();
	}
}
