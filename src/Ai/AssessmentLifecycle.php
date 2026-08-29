<?php
/**
 * AI shadow assessment lifecycle hooks (Points A and C, retention, purge).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Moderation\ModerationAudit;
use UniversalProductReviews\Moderation\ReviewContext;
use UniversalProductReviews\Scheduling\Jobs;

defined( 'ABSPATH' ) || exit;

final class AssessmentLifecycle {

	public const REANALYSIS_LIMIT_SECONDS = 900;

	public static function register(): void {
		add_action( 'comment_post', array( self::class, 'on_comment_post' ), 25, 3 );
		add_action( 'transition_comment_status', array( self::class, 'on_transition' ), 15, 3 );
		add_action( 'delete_comment', array( self::class, 'on_delete_comment' ), 10, 1 );
		add_action( 'deleted_comment', array( self::class, 'on_deleted_comment' ), 10, 1 );
	}

	/**
	 * Point A — enqueue assessment when shadow enabled and comment is eligible.
	 *
	 * @param int        $comment_id       Comment ID.
	 * @param int|string $comment_approved Approval status.
	 * @param array      $commentdata      Comment data.
	 */
	public static function on_comment_post( $comment_id, $comment_approved, $commentdata ): void {
		unset( $comment_approved, $commentdata );

		if ( ! Options::local_ai_shadow_enabled() ) {
			return;
		}

		$comment_id = (int) $comment_id;
		if ( $comment_id <= 0 ) {
			return;
		}

		if ( ! Eligibility::is_ai_assessable( $comment_id ) ) {
			return;
		}

		Jobs::schedule_assess_review( $comment_id, PolicyAllowlist::POLICY_VERSION );
	}

	/**
	 * @param string      $new_status New status.
	 * @param string      $old_status Old status.
	 * @param \WP_Comment $comment    Comment.
	 */
	public static function on_transition( $new_status, $old_status, $comment ): void {
		$new_status = ModerationAudit::normalise_status( (string) $new_status );
		$old_status = ModerationAudit::normalise_status( (string) $old_status );

		if ( $new_status === $old_status ) {
			return;
		}
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		if ( ! ReviewContext::is_in_scope( $comment ) ) {
			return;
		}
		if ( ! in_array( $new_status, array( 'approve', 'spam', 'trash' ), true ) ) {
			return;
		}

		$comment_id = (int) $comment->comment_ID;
		AssessmentWorker::revoke_on_non_held_transition( $comment_id, $new_status );
	}

	/**
	 * @param int $comment_id Comment ID.
	 */
	public static function on_delete_comment( $comment_id ): void {
		self::purge_comment_data( (int) $comment_id );
	}

	/**
	 * @param int $comment_id Comment ID.
	 */
	public static function on_deleted_comment( $comment_id ): void {
		self::purge_comment_data( (int) $comment_id );
	}

	/**
	 * Point C — operator re-analysis (held-only, rate limited).
	 */
	public static function request_reanalysis( int $comment_id ): bool {
		if ( ! Options::local_ai_shadow_enabled() ) {
			return false;
		}
		if ( ! Eligibility::is_ai_assessable( $comment_id ) ) {
			return false;
		}

		// OpenAI re-analysis requires manage_woocommerce + external AI enabled.
		if ( 'openai' === ProviderResolver::kind() ) {
			if ( ! Options::ai_external_enabled() ) {
				return false;
			}
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return false;
			}
		} elseif ( ! current_user_can( 'moderate_comments' ) ) {
			return false;
		}

		$key = 'upr_reanalysis_' . $comment_id;
		if ( get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, '1', self::REANALYSIS_LIMIT_SECONDS );
		Jobs::schedule_assess_review( $comment_id, PolicyAllowlist::POLICY_VERSION );
		AssessmentAudit::reanalysis_requested( $comment_id, PolicyAllowlist::POLICY_VERSION, ProviderResolver::kind() );
		return true;
	}

	private static function purge_comment_data( int $comment_id ): void {
		if ( $comment_id <= 0 ) {
			return;
		}
		AssessmentRepository::delete_for_comment( $comment_id );
		AssessmentClaimsRepository::clear_any_active( $comment_id, PolicyAllowlist::POLICY_VERSION );
	}
}
