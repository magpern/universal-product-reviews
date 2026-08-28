<?php
/**
 * Bounded Action Scheduler status for group upr (public APIs only).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Scheduling;

defined( 'ABSPATH' ) || exit;

final class ActionSchedulerStatus {

	public const GROUP     = Jobs::GROUP;
	public const PER_PAGE  = 50;

	/**
	 * @return array{
	 *   available:bool,
	 *   listing_available:bool,
	 *   failed_at_least:?int,
	 *   pending_at_least:?int,
	 *   in_progress_at_least:?int,
	 *   capped:bool,
	 *   unavailable_reason:?string
	 * }
	 */
	public static function summarize(): array {
		$base = array(
			'available'           => false,
			'listing_available'   => false,
			'failed_at_least'     => null,
			'pending_at_least'    => null,
			'in_progress_at_least'=> null,
			'capped'              => false,
			'unavailable_reason'  => null,
		);

		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
			$base['unavailable_reason'] = 'as_enqueue_missing';
			return $base;
		}
		$base['available'] = true;

		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			$base['unavailable_reason'] = 'as_get_scheduled_actions_missing';
			return $base;
		}

		$base['listing_available'] = true;

		try {
			$failed   = self::count_status( 'failed' );
			// Pending: only those already due (scheduled date <= now) — "overdue" for D6.
			$pending  = self::count_status( 'pending', true );
			$progress = self::count_status( 'in-progress' );
		} catch ( \Throwable $e ) {
			$base['listing_available']  = false;
			$base['unavailable_reason'] = 'as_query_failed';
			return $base;
		}

		$base['failed_at_least']      = $failed['count'];
		$base['pending_at_least']     = $pending['count'];
		$base['in_progress_at_least'] = $progress['count'];
		$base['capped']               = $failed['capped'] || $pending['capped'] || $progress['capped'];

		return $base;
	}

	/**
	 * Bounded public listing. Never claim exhaustive inventory — callers must say "at least".
	 *
	 * @return array{count:int,capped:bool}
	 */
	private static function count_status( string $status, bool $due_only = false ): array {
		$args = array(
			'group'    => self::GROUP,
			'status'   => $status,
			'per_page' => self::PER_PAGE,
			'orderby'  => 'date',
			'order'    => 'ASC',
		);
		if ( $due_only ) {
			// Public Action Scheduler query args only (no Internal\* classes).
			$args['date']          = gmdate( 'Y-m-d H:i:s', time() );
			$args['date_compare']  = '<=';
		}
		$actions = as_get_scheduled_actions( $args, 'ids' );
		if ( ! is_array( $actions ) ) {
			return array( 'count' => 0, 'capped' => false );
		}
		$n = count( $actions );
		return array(
			'count'  => $n,
			'capped' => $n >= self::PER_PAGE,
		);
	}
}
