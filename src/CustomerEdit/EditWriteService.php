<?php
/**
 * Transactional body+rating+content_written unit (M14 write crash safety).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

defined( 'ABSPATH' ) || exit;

final class EditWriteService {

	public const CRASH_AFTER_BODY                 = 'body';
	public const CRASH_AFTER_RATING               = 'rating';
	public const CRASH_BEFORE_CONTENT_WRITTEN_CAS = 'before_content_written';

	/** @var string|null Crash after this write step for tests. */
	public static ?string $crash_after_for_tests = null;

	/**
	 * Apply target body and rating then CAS content_written in one InnoDB unit.
	 *
	 * Caller must have committed phase=writing first. Rollback leaves comment
	 * and rating unchanged; the writing checkpoint is outside this unit.
	 */
	public static function persist_mutation(
		int $comment_id,
		string $claim_token,
		int $generation,
		string $canonical_body,
		int $rating
	): bool {
		global $wpdb;

		if ( $comment_id <= 0 || $rating < 1 || $rating > 5 || '' === $claim_token || $generation <= 0 ) {
			return false;
		}

		$row = EditClaimRepository::get( $comment_id );
		if ( ! is_array( $row ) || 'writing' !== (string) ( $row['phase'] ?? '' ) ) {
			return false;
		}
		if ( (string) ( $row['claim_token'] ?? '' ) !== $claim_token || (int) ( $row['generation'] ?? 0 ) !== $generation ) {
			return false;
		}

		$wpdb->query( 'START TRANSACTION' );
		try {
			CustomerEditAuthorization::arm( $comment_id, $claim_token, $generation );

			$content_changed = (int) ( $row['content_changed'] ?? 0 ) === 1;
			$rating_changed  = (int) ( $row['rating_changed'] ?? 0 ) === 1;

			if ( $content_changed ) {
				$updated = wp_update_comment(
					array(
						'comment_ID'      => $comment_id,
						'comment_content' => $canonical_body,
					)
				);
				if ( false === $updated || is_wp_error( $updated ) ) {
					self::rollback( $comment_id );
					return false;
				}
			}
			self::maybe_crash( self::CRASH_AFTER_BODY );

			if ( $rating_changed ) {
				$ok = update_comment_meta( $comment_id, 'rating', $rating );
				if ( false === $ok ) {
					self::rollback( $comment_id );
					return false;
				}
			}
			self::maybe_crash( self::CRASH_AFTER_RATING );

			clean_comment_cache( $comment_id );
			$fresh = get_comment( $comment_id );
			if ( ! $fresh instanceof \WP_Comment ) {
				self::rollback( $comment_id );
				return false;
			}
			$fresh_hmac = EditClaimRepository::hmac_body( (string) $fresh->comment_content );
			$fresh_rate = (int) get_comment_meta( $comment_id, 'rating', true );
			$target_hmac = (string) ( $row['target_content_hmac'] ?? '' );
			$target_rate = (int) ( $row['target_rating'] ?? 0 );
			if ( $fresh_hmac !== $target_hmac || $fresh_rate !== $target_rate ) {
				self::rollback( $comment_id );
				return false;
			}

			self::maybe_crash( self::CRASH_BEFORE_CONTENT_WRITTEN_CAS );

			$op_id = (string) ( $row['finalise_op_id'] ?? '' );
			if ( '' === $op_id ) {
				$op_id = wp_generate_uuid4();
			}
			if ( ! EditClaimRepository::mark_content_written( $comment_id, $claim_token, $generation, $op_id ) ) {
				self::rollback( $comment_id );
				return false;
			}

			$wpdb->query( 'COMMIT' );
			return true;
		} catch ( \Throwable $e ) {
			self::rollback( $comment_id );
			throw $e;
		} finally {
			CustomerEditAuthorization::clear();
		}
	}

	private static function rollback( int $comment_id ): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		if ( $comment_id > 0 ) {
			clean_comment_cache( $comment_id );
			wp_cache_delete( $comment_id, 'comment_meta' );
			update_meta_cache( 'comment', array( $comment_id ) );
		}
	}

	private static function maybe_crash( string $step ): void {
		if ( self::$crash_after_for_tests === $step ) {
			throw new \RuntimeException( 'upr_edit_write_crash_' . $step );
		}
	}
}
