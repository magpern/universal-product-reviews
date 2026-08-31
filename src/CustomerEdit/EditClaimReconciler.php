<?php
/**
 * Recover in-flight customer-edit claims via existing reconcile job (E21).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

use UniversalProductReviews\Moderation\ApproveToHoldCas;

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
			$phase = (string) ( $row['phase'] ?? '' );
			if ( 'writing' === $phase ) {
				$result = self::recover_writing( $row );
			} else {
				$result = self::recover_content_written( $row );
			}
			if ( 'abandoned' === $result ) {
				++$abandoned;
			} elseif ( 'completed' === $result ) {
				++$finalised;
			}
		}

		return array(
			'released'  => $released,
			'finalised' => $finalised,
			'abandoned' => $abandoned,
		);
	}

	/**
	 * @param array<string, mixed> $row Claim row.
	 * @return 'completed'|'abandoned'|'busy'
	 */
	private static function recover_writing( array $row ): string {
		$comment_id  = (int) $row['comment_id'];
		$claim_token = (string) $row['claim_token'];
		$generation  = (int) $row['generation'];
		clean_comment_cache( $comment_id );
		wp_cache_delete( $comment_id, 'comment_meta' );
		$comment     = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			EditClaimRepository::force_abandon( $comment_id, $claim_token, $generation );
			return 'abandoned';
		}

		$live_hmac   = EditClaimRepository::hmac_body( (string) $comment->comment_content );
		$live_rate   = (int) get_comment_meta( $comment_id, 'rating', true );
		$target_hmac = (string) ( $row['target_content_hmac'] ?? '' );
		$target_rate = (int) ( $row['target_rating'] ?? 0 );
		$prior_hmac  = (string) ( $row['prior_content_hmac'] ?? '' );
		$prior_rate  = (int) ( $row['prior_rating'] ?? 0 );

		if ( $live_hmac === $target_hmac && $live_rate === $target_rate ) {
			$op_id = (string) ( $row['finalise_op_id'] ?? '' );
			if ( '' === $op_id ) {
				$op_id = wp_generate_uuid4();
			}
			if ( ! EditClaimRepository::mark_content_written( $comment_id, $claim_token, $generation, $op_id ) ) {
				return 'busy';
			}
			return self::run_finaliser( $comment_id, $claim_token, $generation );
		}

		if ( $live_hmac === $target_hmac && $live_rate !== $target_rate && $target_rate >= 1 && $target_rate <= 5 ) {
			CustomerEditAuthorization::arm( $comment_id, $claim_token, $generation );
			try {
				update_comment_meta( $comment_id, 'rating', $target_rate );
			} finally {
				CustomerEditAuthorization::clear();
			}
			clean_comment_cache( $comment_id );
			$op_id = wp_generate_uuid4();
			if ( ! EditClaimRepository::mark_content_written( $comment_id, $claim_token, $generation, $op_id ) ) {
				return 'busy';
			}
			return self::run_finaliser( $comment_id, $claim_token, $generation );
		}

		if ( '' !== $prior_hmac && $live_hmac === $prior_hmac && $live_rate === $prior_rate ) {
			EditClaimRepository::force_abandon( $comment_id, $claim_token, $generation );
			return 'abandoned';
		}

		ApproveToHoldCas::cas_write( $comment_id );
		clean_comment_cache( $comment_id );
		EditClaimRepository::force_abandon( $comment_id, $claim_token, $generation );
		return 'abandoned';
	}

	/**
	 * @param array<string, mixed> $row Claim row.
	 * @return 'completed'|'abandoned'|'busy'
	 */
	private static function recover_content_written( array $row ): string {
		$comment_id  = (int) $row['comment_id'];
		$claim_token = (string) $row['claim_token'];
		$generation  = (int) $row['generation'];
		$comment     = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			EditClaimRepository::force_abandon( $comment_id, $claim_token, $generation );
			return 'abandoned';
		}
		$live_hmac = EditClaimRepository::hmac_body( (string) $comment->comment_content );
		$live_rate = (int) get_comment_meta( $comment_id, 'rating', true );
		if ( $live_hmac !== (string) $row['target_content_hmac'] || $live_rate !== (int) $row['target_rating'] ) {
			EditClaimRepository::force_abandon( $comment_id, $claim_token, $generation );
			return 'abandoned';
		}
		return self::run_finaliser( $comment_id, $claim_token, $generation );
	}

	/**
	 * @return 'completed'|'abandoned'|'busy'
	 */
	private static function run_finaliser( int $comment_id, string $claim_token, int $generation ): string {
		return EditFinaliser::run( $comment_id, $claim_token, $generation );
	}
}
