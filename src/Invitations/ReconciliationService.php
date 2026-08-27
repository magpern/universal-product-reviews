<?php
/**
 * Reconciliation service.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Invitations;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Scheduling\Jobs;

defined( 'ABSPATH' ) || exit;

final class ReconciliationService {

	public const PAGE_SIZE = 100;

	/**
	 * @return array{orders_scanned:int,rows_upserted:int,suppressed:int,claims_recovered:int,orphans_repaired:int,submit_claims_released:int,actions:list<string>}
	 */
	public static function run( int $lookback_days = 90, bool $dry_run = false ): array {
		$summary = array(
			'orders_scanned'               => 0,
			'rows_upserted'                => 0,
			'suppressed'                   => 0,
			'claims_recovered'             => 0,
			'orphans_repaired'             => 0,
			'submit_claims_released'       => 0,
			'authorisation_denied_skipped' => 0,
			'actions'                      => array(),
		);

		$after_ts = time() - ( $lookback_days * DAY_IN_SECONDS );
		$after    = gmdate( 'Y-m-d H:i:s', $after_ts );

		foreach ( self::iterate_order_ids( $after ) as $order_id ) {
			++$summary['orders_scanned'];
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			if ( ! InvitationScheduler::core_controls_allow_scheduling() ) {
				++$summary['authorisation_denied_skipped'];
				continue;
			}

			$delivered = (bool) apply_filters( 'upr_is_order_delivered', false, $order_id )
				|| (bool) $order->get_meta( InvitationScheduler::META_DELIVERY_CONFIRMED_AT );
			$source    = $delivered ? 'adapter' : 'fallback';
			$event_at  = InvitationScheduler::resolve_source_event_unix( $order, $source );

			foreach ( $order->get_items() as $item ) {
				$item_id  = (int) $item->get_id();
				$eval     = Eligibility::evaluate_item( $order, $item );
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

				$auth = InvitationAuthorisation::evaluate(
					array(
						'order_id'          => $order_id,
						'order_item_id'     => $item_id,
						'product_id'        => (int) ( $eval['product_id'] ?? 0 ),
						'operation'         => InvitationAuthorisation::OP_SCHEDULE,
						'source_event_unix' => $event_at,
					)
				);
				if ( InvitationAuthorisation::DECISION_ALLOW !== $auth['decision'] ) {
					++$summary['authorisation_denied_skipped'];
					continue;
				}

				if ( ! $existing ) {
					$summary['actions'][] = "upsert:{$item_id}:{$source}";
					++$summary['rows_upserted'];
					if ( ! $dry_run ) {
						InvitationScheduler::schedule_order( $order_id, $source, $event_at );
					}
					continue;
				}

				// Existing row: never shift eligible_at forward; may backfill source_event_at only if empty.
				if ( ! $dry_run && empty( $existing['source_event_at'] ) ) {
					InviteRepository::conditional_update(
						$item_id,
						array( 'source_event_at' => gmdate( 'Y-m-d H:i:s', $event_at ) ),
						array()
					);
				}
			}
		}

		if ( $dry_run ) {
			$stale = InviteRepository::find_stale_sending( Options::send_claim_stale_minutes() );
			$summary['claims_recovered'] = count( $stale );
			$summary['actions'][]        = 'dry_run:no_writes';
			return $summary;
		}

		$summary['claims_recovered'] = SendClaimService::recover_abandoned_claims();
		$submit_recovery               = SubmitClaimService::recover_expired_claims();
		$summary['submit_claims_released'] = $submit_recovery['released'];
		$summary['orphans_repaired']       = self::repair_orphaned_reviews() + $submit_recovery['repaired'];

		AuditLogger::log(
			'reconcile.completed',
			'cli',
			null,
			null,
			array(
				'orders_scanned'               => $summary['orders_scanned'],
				'rows_upserted'                => $summary['rows_upserted'],
				'suppressed'                   => $summary['suppressed'],
				'claims_recovered'             => $summary['claims_recovered'],
				'orphans_repaired'             => $summary['orphans_repaired'],
				'submit_claims_released'       => $summary['submit_claims_released'],
				'authorisation_denied_skipped' => $summary['authorisation_denied_skipped'],
			)
		);

		return $summary;
	}

	/**
	 * HPOS-compatible pagination over the lookback window (no silent 200 cap).
	 *
	 * @return \Generator<int, int>
	 */
	public static function iterate_order_ids( string $after_gmt ): \Generator {
		$page = 1;
		do {
			$batch = wc_get_orders(
				array(
					'limit'        => self::PAGE_SIZE,
					'page'         => $page,
					'status'       => array( 'completed', 'processing' ),
					'date_created' => '>' . $after_gmt,
					'orderby'      => 'ID',
					'order'        => 'ASC',
					'return'       => 'ids',
				)
			);
			if ( ! is_array( $batch ) || ! $batch ) {
				break;
			}
			foreach ( $batch as $id ) {
				yield (int) $id;
			}
			if ( count( $batch ) < self::PAGE_SIZE ) {
				break;
			}
			++$page;
		} while ( true );
	}

	private static function repair_orphaned_reviews(): int {
		global $wpdb;
		$repaired = 0;
		$meta_key = '_upr_order_item_id';
		$last_id  = 0;

		do {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT comment_id, meta_value FROM {$wpdb->commentmeta}
					WHERE meta_key = %s AND comment_id > %d
					ORDER BY comment_id ASC LIMIT 200",
					$meta_key,
					$last_id
				),
				ARRAY_A
			);
			if ( ! $results ) {
				break;
			}
			foreach ( $results as $row ) {
				$comment_id    = (int) $row['comment_id'];
				$last_id       = $comment_id;
				$order_item_id = (int) $row['meta_value'];
				$invite        = InviteRepository::find( $order_item_id );
				if ( ! $invite ) {
					continue;
				}
				if ( ScheduleStates::COMPLETED === $invite['schedule_state'] && ! empty( $invite['review_comment_id'] ) ) {
					continue;
				}
				if ( ScheduleStates::SUPPRESSED === $invite['schedule_state'] ) {
					CompletionService::reject_comment( $comment_id );
					continue;
				}
				if ( ! empty( $invite['review_comment_id'] ) && (int) $invite['review_comment_id'] !== $comment_id ) {
					continue;
				}
				if ( CompletionService::finalize_from_comment( $order_item_id, $comment_id, (int) $invite['order_id'] ) ) {
					++$repaired;
				}
			}
			if ( count( $results ) < 200 ) {
				break;
			}
		} while ( true );

		return $repaired;
	}
}
