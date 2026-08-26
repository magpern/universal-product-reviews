<?php
/**
 * Reconciliation service.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Scheduling\Jobs;
use UniversalProductReviews\Tokens\TokenRepository;

defined( 'ABSPATH' ) || exit;

final class ReconciliationService {

	/**
	 * @return array{orders_scanned:int,rows_upserted:int,suppressed:int,claims_recovered:int,orphans_repaired:int,actions:list<string>}
	 */
	public static function run( int $lookback_days = 90, bool $dry_run = false ): array {
		$summary = array(
			'orders_scanned'    => 0,
			'rows_upserted'     => 0,
			'suppressed'        => 0,
			'claims_recovered'  => 0,
			'orphans_repaired'  => 0,
			'actions'           => array(),
		);

		$after = gmdate( 'Y-m-d H:i:s', time() - ( $lookback_days * DAY_IN_SECONDS ) );
		$orders = wc_get_orders(
			array(
				'limit'        => 200,
				'status'       => array( 'completed', 'processing' ),
				'date_created' => '>' . $after,
				'return'       => 'ids',
			)
		);

		foreach ( (array) $orders as $order_id ) {
			$order_id = (int) $order_id;
			++$summary['orders_scanned'];
			$delivered = (bool) apply_filters( 'upr_is_order_delivered', false, $order_id );
			$source    = $delivered ? 'adapter' : 'fallback';

			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				$item_id = (int) $item->get_id();
				$eval    = Eligibility::evaluate_item( $order, $item );
				$existing = InviteRepository::find( $item_id );

				if ( ! $eval['eligible'] ) {
					if ( $existing && ! ScheduleStates::is_terminal( (string) $existing['schedule_state'] ) ) {
						$summary['actions'][] = "suppress:{$item_id}:" . ( $eval['reason'] ?? '' );
						if ( ! $dry_run ) {
							SuppressionService::suppress_item( $item_id, (string) ( $eval['reason'] ?? 'ineligible' ), $order_id );
						}
						++$summary['suppressed'];
					}
					continue;
				}

				if ( ! $existing ) {
					$summary['actions'][] = "upsert:{$item_id}:{$source}";
					++$summary['rows_upserted'];
					if ( ! $dry_run ) {
						InvitationScheduler::schedule_order( $order_id, $source );
					}
				}
			}
		}

		if ( $dry_run ) {
			// Zero writes — including no audit.
			$stale = InviteRepository::find_stale_sending( 30 );
			$summary['claims_recovered'] = count( $stale );
			$summary['actions'][]        = 'dry_run:no_writes';
			return $summary;
		}

		$summary['claims_recovered'] = SendClaimService::recover_abandoned_claims();
		$summary['orphans_repaired'] = self::repair_orphaned_reviews();

		AuditLogger::log(
			'reconcile.completed',
			'cli',
			null,
			null,
			array(
				'orders_scanned'   => $summary['orders_scanned'],
				'rows_upserted'    => $summary['rows_upserted'],
				'suppressed'       => $summary['suppressed'],
				'claims_recovered' => $summary['claims_recovered'],
				'orphans_repaired' => $summary['orphans_repaired'],
			)
		);

		return $summary;
	}

	private static function repair_orphaned_reviews(): int {
		global $wpdb;
		$repaired = 0;

		// Comments with _upr_order_item_id but invite not completed.
		$meta_key = '_upr_order_item_id';
		$results  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_id, meta_value FROM {$wpdb->commentmeta} WHERE meta_key = %s LIMIT 500",
				$meta_key
			),
			ARRAY_A
		);

		foreach ( (array) $results as $row ) {
			$comment_id    = (int) $row['comment_id'];
			$order_item_id = (int) $row['meta_value'];
			$invite        = InviteRepository::find( $order_item_id );
			if ( ! $invite ) {
				continue;
			}
			if ( ScheduleStates::COMPLETED === $invite['schedule_state'] && ! empty( $invite['review_comment_id'] ) ) {
				continue;
			}
			if ( ! empty( $invite['review_comment_id'] ) && (int) $invite['review_comment_id'] !== $comment_id ) {
				continue;
			}

			InviteRepository::conditional_update(
				$order_item_id,
				array(
					'schedule_state'      => ScheduleStates::COMPLETED,
					'review_completed_at' => current_time( 'mysql', true ),
					'review_comment_id'   => $comment_id,
				)
			);
			TokenRepository::revoke_for_item( $order_item_id );
			Jobs::unschedule_item( $order_item_id );
			++$repaired;
		}

		return $repaired;
	}
}
