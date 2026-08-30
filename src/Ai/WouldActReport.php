<?php
/**
 * M13 zero-write would-act aggregate report (Simulation GO).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Privacy-safe would-act preview. Never writes audit/options/ledger/CAS/schedule/comments.
 */
final class WouldActReport {

	public const MAX_COMMENTS = 500;

	/** @var list<string> */
	public const ERROR_CODES = array(
		'query_failed',
		'validation_failed',
		'unexpected',
	);

	/**
	 * Build aggregate-only report. Fail-closed: never partial-as-valid.
	 *
	 * @return array<string, mixed>
	 */
	public static function build( int $limit = self::MAX_COMMENTS ): array {
		$empty = self::empty_ok_false( 'unexpected' );

		try {
			$limit = max( 1, min( self::MAX_COMMENTS, $limit ) );
			$ids   = AssessmentRepository::list_comment_ids_by_latest_assessment( $limit );
			if ( ! is_array( $ids ) ) {
				return self::empty_ok_false( 'query_failed' );
			}

			$would_act_total = 0;
			$pre_boundary_total = 0;
			$would_act_by_reason = array();
			$pre_by_reason      = array();
			$content_abstentions = array();

			foreach ( $ids as $comment_id ) {
				$comment_id = (int) $comment_id;
				if ( $comment_id <= 0 ) {
					continue;
				}

				$resolved = AssessmentRepository::resolve_actionable_assessment( $comment_id );
				if ( ! is_array( $resolved ) || ! isset( $resolved['reason'] ) ) {
					return self::empty_ok_false( 'validation_failed' );
				}

				$assessment = $resolved['assessment'] ?? null;
				if ( ! is_array( $assessment ) ) {
					$reason = (string) $resolved['reason'];
					$content_abstentions[ $reason ] = (int) ( $content_abstentions[ $reason ] ?? 0 ) + 1;
					continue;
				}

				$comment = get_comment( $comment_id );
				$content = ActionPolicy::content_eligible_for_auto_spam( $assessment, $comment );
				$pre     = ActionPolicy::policy_match_pre_boundary( $assessment, $comment );

				if ( ! empty( $content['ok'] ) ) {
					++$would_act_total;
					$would_act_by_reason['eligible'] = (int) ( $would_act_by_reason['eligible'] ?? 0 ) + 1;
				} else {
					$cr = (string) ( $content['reason'] ?? 'unknown' );
					$would_act_by_reason[ $cr ] = (int) ( $would_act_by_reason[ $cr ] ?? 0 ) + 1;
				}

				if ( ! empty( $pre['ok'] ) ) {
					++$pre_boundary_total;
					$pre_by_reason['eligible'] = (int) ( $pre_by_reason['eligible'] ?? 0 ) + 1;
				} else {
					$pr = (string) ( $pre['reason'] ?? 'unknown' );
					$pre_by_reason[ $pr ] = (int) ( $pre_by_reason[ $pr ] ?? 0 ) + 1;
				}
			}

			$boundary = Options::ai_auto_action_boundary_unix();

			return array(
				'ok'                              => true,
				'error_code'                      => null,
				'sampled_comments'                => count( $ids ),
				'would_act_total'                 => $would_act_total,
				'would_act_by_reason'             => $would_act_by_reason,
				'policy_match_pre_boundary_total' => $pre_boundary_total,
				'policy_match_pre_boundary_by_reason' => $pre_by_reason,
				'content_abstentions'             => $content_abstentions,
				'control_state'                   => array(
					'master'    => Options::ai_auto_spam_enabled(),
					'policy'    => Options::ai_auto_spam_policy_enabled(),
					'simulation'=> Options::ai_auto_spam_simulation_guard_enabled(),
					'kill'      => Options::ai_auto_spam_kill_switch(),
					'dry_run'   => Options::ai_auto_spam_dry_run(),
					'boundary'  => $boundary > 0 ? 'set' : 'unset',
				),
				'copy'                            => array(
					'would_act'       => 'Would act if masters were on (requires enablement boundary).',
					'pre_boundary'    => 'Policy match (pre-boundary) — non-actionable; not would-act.',
					'zero_write'      => 'Read-only; does not change status, write audit rows, or enable auto-spam.',
				),
			);
		} catch ( \Throwable $e ) {
			unset( $e );
			return self::empty_ok_false( 'unexpected' );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function empty_ok_false( string $error_code ): array {
		if ( ! in_array( $error_code, self::ERROR_CODES, true ) ) {
			$error_code = 'unexpected';
		}
		return array(
			'ok'                              => false,
			'error_code'                      => $error_code,
			'sampled_comments'                => 0,
			'would_act_total'                 => 0,
			'would_act_by_reason'             => array(),
			'policy_match_pre_boundary_total' => 0,
			'policy_match_pre_boundary_by_reason' => array(),
			'content_abstentions'             => array(),
			'control_state'                   => array(
				'master'     => Options::ai_auto_spam_enabled(),
				'policy'     => Options::ai_auto_spam_policy_enabled(),
				'simulation' => Options::ai_auto_spam_simulation_guard_enabled(),
				'kill'       => Options::ai_auto_spam_kill_switch(),
				'dry_run'    => Options::ai_auto_spam_dry_run(),
				'boundary'   => Options::ai_auto_action_boundary_unix() > 0 ? 'set' : 'unset',
			),
			'copy'                            => array(
				'would_act'    => 'Would act if masters were on (requires enablement boundary).',
				'pre_boundary' => 'Policy match (pre-boundary) — non-actionable; not would-act.',
				'zero_write'   => 'Read-only; does not change status, write audit rows, or enable auto-spam.',
			),
		);
	}
}
