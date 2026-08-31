<?php
/**
 * Approve→hold CAS + WordPress public-hook parity (M14).
 *
 * Does not call the wp_set_comment_status function (non-CAS). Uses conditional UPDATE.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

defined( 'ABSPATH' ) || exit;

final class ApproveToHoldCas {

	/**
	 * @return int Affected rows (0 or 1).
	 */
	public static function cas_write( int $comment_id ): int {
		global $wpdb;

		if ( $comment_id <= 0 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->comments.
		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->comments} SET comment_approved = %s WHERE comment_ID = %d AND comment_approved = %s",
				'0',
				$comment_id,
				'1'
			)
		);

		return max( 0, (int) $n );
	}

	/**
	 * @param \WP_Comment $comment_old Clone taken before CAS with comment_approved === '1'.
	 */
	public static function deliver_hooks_after_successful_cas( int $comment_id, \WP_Comment $comment_old ): void {
		SystemStatusOrigin::run(
			static function () use ( $comment_id, $comment_old ): void {
				clean_comment_cache( $comment_id );
				$comment = get_comment( $comment_id );
				if ( ! $comment instanceof \WP_Comment ) {
					return;
				}

				do_action( 'wp_set_comment_status', $comment->comment_ID, 'hold' );
				wp_transition_comment_status( 'hold', $comment_old->comment_approved, $comment );
				wp_update_comment_count( $comment->comment_post_ID );
			}
		);
	}
}
