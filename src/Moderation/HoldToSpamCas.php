<?php
/**
 * UPR-owned hold→spam CAS + WordPress public-hook parity (M12).
 *
 * Does not call the wp_set_comment_status function (non-CAS). Uses conditional UPDATE.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

defined( 'ABSPATH' ) || exit;

final class HoldToSpamCas {

	/**
	 * Conditional write only (for shared DB transaction with ledger).
	 *
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
				'spam',
				$comment_id,
				'0'
			)
		);

		return max( 0, (int) $n );
	}

	/**
	 * Post-CAS public hook / cache / count parity for held→spam (WP ≥ 6.5 sequence).
	 *
	 * Must run under AiActionOrigin::run after a successful CAS commit.
	 *
	 * @param \WP_Comment $comment_old Clone taken before CAS with comment_approved === '0'.
	 */
	public static function deliver_hooks_after_successful_cas( int $comment_id, \WP_Comment $comment_old ): void {
		AiActionOrigin::run(
			static function () use ( $comment_id, $comment_old ): void {
				clean_comment_cache( $comment_id );
				$comment = get_comment( $comment_id );
				if ( ! $comment instanceof \WP_Comment ) {
					return;
				}

				/**
				 * Mirrors core wp_set_comment_status for spam: second arg is requested status string.
				 */
				do_action( 'wp_set_comment_status', $comment->comment_ID, 'spam' );

				wp_transition_comment_status( 'spam', $comment_old->comment_approved, $comment );

				wp_update_comment_count( $comment->comment_post_ID );
			}
		);
	}
}
