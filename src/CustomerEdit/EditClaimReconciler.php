<?php
/**
 * Recover in-flight customer-edit claims via existing reconcile job (E21).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

defined( 'ABSPATH' ) || exit;

final class EditClaimReconciler {

	/**
	 * @return array{released:int,finalised:int,abandoned:int}
	 */
	public static function run(): array {
		$released  = 0;
		$finalised = 0;
		$abandoned = 0;

		foreach ( EditClaimRepository::find_expired_unwritten() as $row ) {
			EditClaimRepository::abandon_unwritten(
				(int) $row['comment_id'],
				(string) $row['claim_token'],
				(int) $row['generation']
			);
			++$released;
		}

		foreach ( EditClaimRepository::find_recovery_owned() as $row ) {
			$comment_id  = (int) $row['comment_id'];
			$claim_token = (string) $row['claim_token'];
			$generation  = (int) $row['generation'];
			$comment     = get_comment( $comment_id );
			if ( ! $comment instanceof \WP_Comment ) {
				EditClaimRepository::force_abandon( $comment_id, $claim_token, $generation );
				++$abandoned;
				continue;
			}
			$live_hmac = EditClaimRepository::hmac_body( (string) $comment->comment_content );
			$live_rate = (int) get_comment_meta( $comment_id, 'rating', true );
			if ( $live_hmac !== (string) $row['target_content_hmac'] || $live_rate !== (int) $row['target_rating'] ) {
				EditClaimRepository::force_abandon( $comment_id, $claim_token, $generation );
				++$abandoned;
				continue;
			}
			$outcome = EditFinaliser::run( $comment_id, $claim_token, $generation );
			if ( 'abandoned' === $outcome ) {
				++$abandoned;
			} elseif ( 'completed' === $outcome ) {
				++$finalised;
			}
		}

		return array(
			'released'  => $released,
			'finalised' => $finalised,
			'abandoned' => $abandoned,
		);
	}
}
