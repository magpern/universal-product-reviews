<?php
/**
 * Invitation scheduling from delivery / completed fallback.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Scheduling\Jobs;

defined( 'ABSPATH' ) || exit;

final class InvitationScheduler {

	public const META_DELIVERY_CONFIRMED_AT = '_upr_delivery_confirmed_at';

	public static function register(): void {
		add_action( 'upr_order_delivery_confirmed', array( self::class, 'on_delivery_confirmed' ), 10, 2 );
		add_action( 'upr_order_delivery_invalidated', array( self::class, 'on_delivery_invalidated' ), 10, 2 );
		add_action( 'woocommerce_order_status_completed', array( self::class, 'on_order_completed' ), 20, 1 );
	}

	/**
	 * @param array<string, mixed> $context May include delivered_at (unix timestamp).
	 */
	public static function on_delivery_confirmed( int $order_id, array $context = array() ): void {
		$event_at = isset( $context['delivered_at'] ) ? (int) $context['delivered_at'] : time();
		$order    = wc_get_order( $order_id );
		if ( $order ) {
			$order->update_meta_data( self::META_DELIVERY_CONFIRMED_AT, gmdate( 'Y-m-d H:i:s', $event_at ) );
			$order->save();
		}
		if ( ! self::core_controls_allow_scheduling() ) {
			return;
		}
		Jobs::schedule_order_items( $order_id, 'adapter', $event_at );
	}

	public static function on_delivery_invalidated( int $order_id, string $reason ): void {
		foreach ( InviteRepository::find_by_order( $order_id ) as $row ) {
			if ( ! ScheduleStates::is_terminal( (string) $row['schedule_state'] ) ) {
				SuppressionService::suppress_item( (int) $row['order_item_id'], 'delivery_invalidated:' . $reason, $order_id );
			}
		}
	}

	public static function on_order_completed( int $order_id ): void {
		$delivered = (bool) apply_filters( 'upr_is_order_delivered', false, $order_id );
		if ( $delivered ) {
			return;
		}
		if ( ! self::core_controls_allow_scheduling() ) {
			return;
		}
		$order    = wc_get_order( $order_id );
		$event_at = time();
		if ( $order ) {
			$completed = $order->get_date_completed();
			if ( $completed ) {
				$event_at = $completed->getTimestamp();
			}
		}
		Jobs::schedule_order_items( $order_id, 'fallback', $event_at );
	}

	/**
	 * Create/update invite rows and enqueue send when due.
	 *
	 * @param int|null $source_event_unix Unix timestamp of delivery/completion event.
	 */
	public static function schedule_order( int $order_id, string $delivery_source, ?int $source_event_unix = null ): void {
		if ( ! self::core_controls_allow_scheduling() ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$source_event_unix = $source_event_unix ?? self::resolve_source_event_unix( $order, $delivery_source );

		// Order-level fail-closed: pre-boundary source events must not create invitation email work.
		if ( ! Options::is_source_event_within_scheduling_boundary( $source_event_unix ) ) {
			return;
		}

		$delay_days        = 'adapter' === $delivery_source
			? Options::delay_days_after_delivery()
			: Options::delay_days_fallback_completed();

		$source_event_at = gmdate( 'Y-m-d H:i:s', $source_event_unix );
		$eligible_ts     = $source_event_unix + ( $delay_days * DAY_IN_SECONDS );
		$eligible_at     = gmdate( 'Y-m-d H:i:s', $eligible_ts );
		// Past-due → schedule immediately.
		$send_at = max( time(), $eligible_ts );
		$any_scheduled = false;

		foreach ( $order->get_items() as $item ) {
			$order_item_id = (int) $item->get_id();
			$eval          = Eligibility::evaluate_item( $order, $item );
			if ( ! $eval['eligible'] ) {
				$existing = InviteRepository::find( $order_item_id );
				if ( $existing && ! ScheduleStates::is_terminal( (string) $existing['schedule_state'] ) ) {
					SuppressionService::suppress_item( $order_item_id, (string) ( $eval['reason'] ?? 'ineligible' ), $order_id );
				}
				continue;
			}

			$auth = InvitationAuthorisation::evaluate_and_audit(
				array(
					'order_id'          => $order_id,
					'order_item_id'     => $order_item_id,
					'product_id'        => (int) $eval['product_id'],
					'operation'         => InvitationAuthorisation::OP_SCHEDULE,
					'source_event_unix' => $source_event_unix,
				)
			);
			if ( InvitationAuthorisation::DECISION_ALLOW !== $auth['decision'] ) {
				continue;
			}

			$existing = InviteRepository::find( $order_item_id );
			if ( $existing && ScheduleStates::is_terminal( (string) $existing['schedule_state'] ) ) {
				continue;
			}

			$state       = ScheduleStates::SCHEDULED;
			$delay_until = null;
			if ( ! empty( $eval['delay'] ) && is_array( $eval['delay'] ) ) {
				$state = ScheduleStates::DELAYED;
				if ( ! empty( $eval['delay']['delay_until'] ) ) {
					$delay_until = gmdate( 'Y-m-d H:i:s', (int) $eval['delay']['delay_until'] );
				}
			}

			$data = array(
				'order_id'        => $order_id,
				'product_id'      => (int) $eval['product_id'],
				'variation_id'    => $eval['variation_id'],
				'schedule_state'  => $existing ? (string) $existing['schedule_state'] : $state,
				'delay_until'     => $delay_until ?? ( $existing['delay_until'] ?? null ),
				'delivery_source' => $delivery_source,
				'suppression_code'=> null,
			);

			// Idempotent: never shift an existing eligibility/source event forward.
			if ( $existing && ! empty( $existing['eligible_at'] ) ) {
				$data['eligible_at']     = $existing['eligible_at'];
				$data['source_event_at'] = $existing['source_event_at'] ?? $source_event_at;
			} else {
				$data['eligible_at']     = $eligible_at;
				$data['source_event_at'] = $source_event_at;
				$data['schedule_state']  = $state;
			}

			InviteRepository::upsert( $order_item_id, $data );
			$any_scheduled = true;

			if ( ! $existing || empty( $existing['eligible_at'] ) ) {
				AuditLogger::log(
					'invite.scheduled',
					'system',
					$order_id,
					$order_item_id,
					array(
						'delivery_source'  => $delivery_source,
						'eligible_at'      => $data['eligible_at'],
						'source_event_at'  => $data['source_event_at'],
					)
				);
			}
		}

		if ( $any_scheduled ) {
			Jobs::schedule_initial_bundle( $order_id, gmdate( 'Y-m-d H:i:s', $send_at ) );
		}
	}

	/**
	 * Core master/pause gate before any schedule enqueue (host filter applied per-item).
	 */
	public static function core_controls_allow_scheduling(): bool {
		if ( Options::invitation_emergency_pause() ) {
			return false;
		}
		return Options::invitation_emails_enabled();
	}

	/**
	 * Resolve source event time for reconciliation / scheduling.
	 */
	public static function resolve_source_event_unix( \WC_Order $order, string $delivery_source ): int {
		if ( 'adapter' === $delivery_source ) {
			$meta = $order->get_meta( self::META_DELIVERY_CONFIRMED_AT );
			if ( is_string( $meta ) && $meta !== '' ) {
				$ts = strtotime( $meta . ' UTC' );
				if ( $ts ) {
					return $ts;
				}
			}
			/**
			 * Host may supply a unix timestamp for when delivery was confirmed.
			 *
			 * @param int $default
			 * @param int $order_id
			 */
			$filtered = (int) apply_filters( 'upr_order_delivery_confirmed_at', 0, $order->get_id() );
			if ( $filtered > 0 ) {
				return $filtered;
			}
		}

		$completed = $order->get_date_completed();
		if ( $completed ) {
			return $completed->getTimestamp();
		}

		$created = $order->get_date_created();
		if ( $created ) {
			return $created->getTimestamp();
		}

		return time();
	}
}
