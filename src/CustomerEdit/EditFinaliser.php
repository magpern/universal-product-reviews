<?php
/**
 * Per-generation edit finalisation (E33).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\CustomerEdit;

use UniversalProductReviews\Ai\AssessmentAudit;
use UniversalProductReviews\Ai\AssessmentClaimsRepository;
use UniversalProductReviews\Ai\AssessmentRepository;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Moderation\ApproveToHoldCas;
use UniversalProductReviews\Moderation\ReviewContext;

defined( 'ABSPATH' ) || exit;

final class EditFinaliser {

	/** @var int|null Crash after this E33 step (1–7) for tests. */
	public static ?int $crash_after_step_for_tests = null;

	/**
	 * @return 'completed'|'abandoned'|'busy'
	 */
	public static function run( int $comment_id, string $claim_token, int $generation ): string {
		$row = EditClaimRepository::acquire_finalise_lease( $comment_id, $claim_token, $generation );
		if ( ! is_array( $row ) ) {
			$current = EditClaimRepository::get( $comment_id );
			if ( is_array( $current ) && ! empty( $current['finalized_at'] ) && (string) ( $current['claim_token'] ?? '' ) === $claim_token ) {
				return (string) ( $current['finalise_outcome'] ?? 'completed' ) === 'abandoned' ? 'abandoned' : 'completed';
			}
			return 'busy';
		}

		$op_id       = (string) ( $row['finalise_op_id'] ?? '' );
		$lease_token = (string) ( $row['finalise_lease_token'] ?? '' );
		if ( '' === $op_id || '' === $lease_token ) {
			return 'busy';
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			EditClaimRepository::mark_finalized( $comment_id, $claim_token, $generation, $op_id, $lease_token, 'abandoned', 'ineligible' );
			return 'abandoned';
		}

		$old_clone = clone $comment;
		$affected  = ApproveToHoldCas::cas_write( $comment_id );
		if ( 1 === $affected ) {
			ApproveToHoldCas::deliver_hooks_after_successful_cas( $comment_id, $old_clone );
		} else {
			clean_comment_cache( $comment_id );
			$live = get_comment( $comment_id );
			if ( ! $live instanceof \WP_Comment ) {
				EditClaimRepository::mark_finalized( $comment_id, $claim_token, $generation, $op_id, $lease_token, 'abandoned', 'ineligible' );
				return 'abandoned';
			}
			$st = (string) $live->comment_approved;
			if ( '0' !== $st ) {
				EditClaimRepository::mark_finalized( $comment_id, $claim_token, $generation, $op_id, $lease_token, 'abandoned', 'ineligible' );
				return 'abandoned';
			}
			$comment = $live;
		}
		self::maybe_crash( 1 );

		self::recount( (int) $comment->comment_post_ID );
		self::maybe_crash( 2 );

		AssessmentClaimsRepository::clear_all_active_for_comment( $comment_id );
		self::maybe_crash( 3 );

		$skip_id = AssessmentRepository::insert_skipped_content_edited( $comment_id, $op_id );
		if ( $skip_id > 0 ) {
			AssessmentAudit::skipped( $comment_id, $skip_id, PolicyAllowlist::POLICY_VERSION, 'content_edited' );
		}
		self::maybe_crash( 4 );

		$ctx     = ReviewContext::build( $comment );
		$payload = array(
			'comment_id'       => $comment_id,
			'product_id'       => (int) $ctx['product_id'],
			'old_status'       => (string) ( $row['prior_status'] ?? '' ),
			'new_status'       => 'hold',
			'content_changed'  => true,
			'rating_changed'   => true,
			'path'             => (string) ( $row['auth_class'] ?? 'guest_session' ),
			'finalise_op_id'   => $op_id,
		);
		if ( ! empty( $ctx['order_id'] ) ) {
			$payload['order_id'] = (int) $ctx['order_id'];
		}
		if ( ! empty( $ctx['order_item_id'] ) ) {
			$payload['order_item_id'] = (int) $ctx['order_item_id'];
		}
		AuditLogger::log_once(
			'review.customer_edited',
			$op_id,
			'customer',
			isset( $payload['order_id'] ) ? (int) $payload['order_id'] : null,
			isset( $payload['order_item_id'] ) ? (int) $payload['order_item_id'] : null,
			$payload
		);
		self::maybe_crash( 5 );

		$reassess = 'ineligible';
		$fresh    = get_comment( $comment_id );
		$held     = $fresh instanceof \WP_Comment && '0' === (string) $fresh->comment_approved;
		if ( Options::local_ai_shadow_enabled() && $held ) {
			$existing = (string) ( $row['finalise_reassess'] ?? 'none' );
			if ( 'scheduled' !== $existing && 'ineligible' !== $existing ) {
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action(
						'upr_assess_review',
						array( $comment_id, PolicyAllowlist::POLICY_VERSION, $op_id ),
						'upr',
						true
					);
				}
				$reassess = 'scheduled';
				EditClaimRepository::set_reassess( $comment_id, $claim_token, $generation, $lease_token, $reassess );
			} else {
				$reassess = $existing;
			}
		} else {
			EditClaimRepository::set_reassess( $comment_id, $claim_token, $generation, $lease_token, 'ineligible' );
		}
		self::maybe_crash( 6 );

		EditClaimRepository::mark_finalized( $comment_id, $claim_token, $generation, $op_id, $lease_token, 'completed', $reassess );
		self::maybe_crash( 7 );

		return 'completed';
	}

	private static function recount( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}
		if ( class_exists( 'WC_Comments' ) && is_callable( array( 'WC_Comments', 'clear_transients' ) ) ) {
			\WC_Comments::clear_transients( $product_id );
			return;
		}
		if ( function_exists( 'wp_update_comment_count' ) ) {
			wp_update_comment_count( $product_id );
		}
	}

	private static function maybe_crash( int $step ): void {
		if ( self::$crash_after_step_for_tests === $step ) {
			throw new \RuntimeException( 'upr_edit_finalise_crash_' . $step );
		}
	}
}
