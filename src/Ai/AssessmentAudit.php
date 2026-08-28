<?php
/**
 * Allowlisted AI assessment audit events (enabled paths only).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Moderation\ReviewContext;

defined( 'ABSPATH' ) || exit;

final class AssessmentAudit {

	public const EVENT_COMPLETED  = 'review.ai_assessment_completed';
	public const EVENT_FAILED     = 'review.ai_assessment_failed';
	public const EVENT_SKIPPED    = 'review.ai_assessment_skipped';
	public const EVENT_REANALYSIS = 'review.ai_reanalysis_requested';

	/**
	 * @param 'completed'|'indeterminate' $state
	 */
	public static function completed( int $comment_id, int $assessment_id, string $state, string $policy_version ): void {
		self::emit(
			self::EVENT_COMPLETED,
			$comment_id,
			$assessment_id,
			$state,
			$policy_version,
			null
		);
	}

	public static function failed( int $comment_id, int $assessment_id, string $policy_version, string $failure_code ): void {
		self::emit(
			self::EVENT_FAILED,
			$comment_id,
			$assessment_id,
			'failed',
			$policy_version,
			$failure_code
		);
	}

	public static function skipped( int $comment_id, int $assessment_id, string $policy_version, string $failure_code ): void {
		self::emit(
			self::EVENT_SKIPPED,
			$comment_id,
			$assessment_id,
			'skipped',
			$policy_version,
			$failure_code
		);
	}

	public static function reanalysis_requested( int $comment_id, string $policy_version ): void {
		$product_id = ReviewContext::product_id( get_comment( $comment_id ) ?? $comment_id );
		$payload    = self::allowlisted_payload(
			$comment_id,
			$product_id,
			null,
			'skipped',
			$policy_version,
			null
		);
		AuditLogger::log( self::EVENT_REANALYSIS, 'moderator', null, null, $payload );
	}

	private static function emit(
		string $event,
		int $comment_id,
		int $assessment_id,
		string $state,
		string $policy_version,
		?string $failure_code
	): void {
		$product_id = ReviewContext::product_id( get_comment( $comment_id ) ?? $comment_id );
		$payload    = self::allowlisted_payload(
			$comment_id,
			$product_id,
			$assessment_id,
			$state,
			$policy_version,
			$failure_code
		);
		AuditLogger::log( $event, 'system', null, null, $payload );
	}

	/**
	 * @return array<string, int|string>
	 */
	private static function allowlisted_payload(
		int $comment_id,
		int $product_id,
		?int $assessment_id,
		string $state,
		string $policy_version,
		?string $failure_code
	): array {
		$payload = array(
			'comment_id'     => $comment_id,
			'product_id'     => $product_id,
			'state'          => $state,
			'policy_version' => $policy_version,
			'provider_kind'  => 'local',
		);
		if ( null !== $assessment_id && $assessment_id > 0 ) {
			$payload['assessment_id'] = $assessment_id;
		}
		if ( null !== $failure_code && '' !== $failure_code && PolicyAllowlist::is_failure_code( $failure_code ) ) {
			$payload['failure_code'] = $failure_code;
		}
		return $payload;
	}
}
