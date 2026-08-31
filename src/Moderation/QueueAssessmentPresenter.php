<?php
/**
 * Derive-at-read privacy-safe assessment presenter for the M15 operator queue.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Moderation;

use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Ai\Recommendation;
use UniversalProductReviews\Ai\RecommendationPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Maps a terminal assessment row to bounded operator-facing labels.
 * Never falls back to an older completed row; caller supplies latest-of-any-state.
 */
final class QueueAssessmentPresenter {

	public const DIM_UNAVAILABLE     = 'unavailable';
	public const DIM_NONE_INDICATED  = 'none_indicated';
	public const DIM_SUSPECTED       = 'suspected';
	public const DIM_INSUFFICIENT    = 'insufficient_signal';
	public const DIM_UNSUPPORTED_LANG = 'unsupported_language';

	/**
	 * @param array<string, mixed>|null $assessment Latest assessment of any state, or null.
	 * @return array{
	 *   overall_action:string,
	 *   overall_label:string,
	 *   status_copy:string,
	 *   show_dimensions:bool,
	 *   dimensions:array{spam_likelihood:string,relevance:string,safety_policy:string,content_signal:string},
	 *   dimension_labels:array{spam_likelihood:string,relevance:string,safety_policy:string,content_signal:string},
	 *   rationale_labels:list<string>,
	 *   risk_score:?int,
	 *   confidence:string,
	 *   provider_kind:string,
	 *   assessment_available:bool,
	 *   assessment_state:string
	 * }
	 */
	public static function present( ?array $assessment, bool $is_held, bool $display_enabled ): array {
		$empty_dims = array(
			'spam_likelihood' => self::DIM_UNAVAILABLE,
			'relevance'       => self::DIM_UNAVAILABLE,
			'safety_policy'   => self::DIM_UNAVAILABLE,
			'content_signal'  => self::DIM_UNAVAILABLE,
		);

		if ( ! $display_enabled ) {
			return self::pack(
				Recommendation::ACTION_NEEDS_HUMAN,
				'—',
				false,
				$empty_dims,
				array(),
				null,
				'',
				'',
				false,
				'none'
			);
		}

		if ( null === $assessment ) {
			return self::pack(
				Recommendation::ACTION_NEEDS_HUMAN,
				__( 'Assessment unavailable', 'universal-product-reviews' ),
				true,
				$empty_dims,
				array(),
				null,
				'',
				'',
				false,
				'none'
			);
		}

		if ( ! $is_held ) {
			return self::pack(
				Recommendation::ACTION_NEEDS_HUMAN,
				__( 'Historical assessment', 'universal-product-reviews' ),
				false,
				$empty_dims,
				array(),
				null,
				'',
				'',
				true,
				self::state_token( $assessment )
			);
		}

		$state = isset( $assessment['state'] ) ? (string) $assessment['state'] : '';
		$kind  = isset( $assessment['provider_kind'] ) ? (string) $assessment['provider_kind'] : '';
		if ( ! in_array( $kind, array( 'local', 'openai' ), true ) ) {
			$kind = '';
		}

		if ( in_array( $state, array( 'failed', 'skipped', 'indeterminate' ), true ) ) {
			$failure = isset( $assessment['failure_code'] ) ? (string) $assessment['failure_code'] : '';
			$copy    = self::neutral_copy( $state, $failure );
			return self::pack(
				Recommendation::ACTION_NEEDS_HUMAN,
				$copy,
				true,
				$empty_dims,
				array(),
				null,
				'',
				$kind,
				true,
				self::state_token( $assessment )
			);
		}

		$rec     = RecommendationPolicy::suggest( $assessment );
		$overall = self::overall_label( $rec->action );
		$codes   = self::parse_reason_codes( $assessment['reason_codes'] ?? null );
		$dims    = self::dimensions_from_codes( $codes, $state );
		$score   = null;
		if ( 'completed' === $state && isset( $assessment['publication_safety_score'] ) && is_numeric( $assessment['publication_safety_score'] ) ) {
			$score = (int) $assessment['publication_safety_score'];
		}
		$confidence = isset( $assessment['confidence'] ) ? (string) $assessment['confidence'] : '';
		if ( ! in_array( $confidence, PolicyAllowlist::CONFIDENCE, true ) ) {
			$confidence = '';
		}

		return self::pack(
			$rec->action,
			$overall,
			true,
			$dims,
			self::rationale_labels( $codes ),
			$score,
			$confidence,
			$kind,
			true,
			self::state_token( $assessment )
		);
	}

	/**
	 * Operator-facing overall label for the queue presenter only.
	 * Does not change RecommendationPolicy::action_label().
	 */
	public static function overall_label( string $action ): string {
		switch ( $action ) {
			case Recommendation::ACTION_LIKELY_PUBLISHABLE:
				return __( 'Likely acceptable (advisory — human must publish)', 'universal-product-reviews' );
			case Recommendation::ACTION_LIKELY_SPAM:
				return __( 'Likely spam', 'universal-product-reviews' );
			case Recommendation::ACTION_LIKELY_ABUSE:
				return __( 'Likely abuse', 'universal-product-reviews' );
			case Recommendation::ACTION_MANDATORY_HUMAN:
				return __( 'Mandatory human review', 'universal-product-reviews' );
			case Recommendation::ACTION_NEEDS_HUMAN:
			default:
				return __( 'Needs human review', 'universal-product-reviews' );
		}
	}

	/**
	 * Human label for a dimension value token.
	 */
	public static function dimension_value_label( string $token ): string {
		switch ( $token ) {
			case self::DIM_NONE_INDICATED:
				return __( 'None indicated', 'universal-product-reviews' );
			case self::DIM_SUSPECTED:
				return __( 'Suspected', 'universal-product-reviews' );
			case self::DIM_INSUFFICIENT:
				return __( 'Insufficient signal', 'universal-product-reviews' );
			case self::DIM_UNSUPPORTED_LANG:
				return __( 'Unsupported language', 'universal-product-reviews' );
			case self::DIM_UNAVAILABLE:
			default:
				return __( 'Unavailable', 'universal-product-reviews' );
		}
	}

	/**
	 * Render escaped definition list HTML for the upr_ai cell (caller echoes).
	 *
	 * @param array<string, mixed> $presented Output of present().
	 */
	public static function render_definition_list( array $presented ): string {
		if ( empty( $presented['show_dimensions'] ) ) {
			return '';
		}

		$status = isset( $presented['status_copy'] ) ? (string) $presented['status_copy'] : '';
		$labels = isset( $presented['dimension_labels'] ) && is_array( $presented['dimension_labels'] )
			? $presented['dimension_labels']
			: array();

		$html  = '<dl class="upr-queue-assessment">';
		$html .= '<div><dt>' . esc_html__( 'Overall', 'universal-product-reviews' ) . '</dt>';
		$html .= '<dd>' . esc_html( $status ) . '</dd></div>';

		$map = array(
			'spam_likelihood' => __( 'Spam likelihood', 'universal-product-reviews' ),
			'relevance'       => __( 'Relevance', 'universal-product-reviews' ),
			'safety_policy'   => __( 'Safety/policy concern', 'universal-product-reviews' ),
			'content_signal'  => __( 'Content signal', 'universal-product-reviews' ),
		);
		foreach ( $map as $key => $dt ) {
			$val = isset( $labels[ $key ] ) ? (string) $labels[ $key ] : self::dimension_value_label( self::DIM_UNAVAILABLE );
			$html .= '<div><dt>' . esc_html( $dt ) . '</dt><dd>' . esc_html( $val ) . '</dd></div>';
		}

		$rationale = isset( $presented['rationale_labels'] ) && is_array( $presented['rationale_labels'] )
			? $presented['rationale_labels']
			: array();
		if ( array() !== $rationale ) {
			$html .= '<div><dt>' . esc_html__( 'Reasons', 'universal-product-reviews' ) . '</dt>';
			$html .= '<dd>' . esc_html( implode( ', ', $rationale ) ) . '</dd></div>';
		}

		$meta = array();
		if ( ! empty( $presented['provider_kind'] ) ) {
			$meta[] = (string) $presented['provider_kind'];
		}
		if ( isset( $presented['risk_score'] ) && is_int( $presented['risk_score'] ) ) {
			$meta[] = sprintf(
				/* translators: %d: publication risk score 1–100 (higher = greater risk) */
				__( 'risk %d', 'universal-product-reviews' ),
				$presented['risk_score']
			);
		}
		if ( ! empty( $presented['confidence'] ) ) {
			$meta[] = (string) $presented['confidence'];
		}
		if ( array() !== $meta ) {
			$html .= '<div><dt>' . esc_html__( 'Details', 'universal-product-reviews' ) . '</dt>';
			$html .= '<dd>' . esc_html( implode( ' · ', $meta ) ) . '</dd></div>';
		}

		$html .= '</dl>';
		return $html;
	}

	/**
	 * Assert presenter overall labels never contain approve/approved for AI output.
	 */
	public static function label_contains_forbidden_approval_wording( string $label ): bool {
		$lower = strtolower( $label );
		return false !== strpos( $lower, 'approved' ) || (bool) preg_match( '/\bapprove\b/', $lower );
	}

	/**
	 * @param array{spam_likelihood:string,relevance:string,safety_policy:string,content_signal:string} $dims Dimension tokens.
	 * @param list<string>                                                                               $rationale Reason labels.
	 * @return array<string, mixed>
	 */
	private static function pack(
		string $action,
		string $status_copy,
		bool $show_dimensions,
		array $dims,
		array $rationale,
		?int $risk_score,
		string $confidence,
		string $provider_kind,
		bool $assessment_available,
		string $assessment_state
	): array {
		$dim_labels = array();
		foreach ( $dims as $k => $token ) {
			$dim_labels[ $k ] = self::dimension_value_label( (string) $token );
		}

		return array(
			'overall_action'       => $action,
			'overall_label'        => self::overall_label( $action ),
			'status_copy'          => $status_copy,
			'show_dimensions'      => $show_dimensions,
			'dimensions'           => $dims,
			'dimension_labels'     => $dim_labels,
			'rationale_labels'     => $rationale,
			'risk_score'           => $risk_score,
			'confidence'           => $confidence,
			'provider_kind'        => $provider_kind,
			'assessment_available' => $assessment_available,
			'assessment_state'     => $assessment_state,
		);
	}

	private static function state_token( array $assessment ): string {
		$state = isset( $assessment['state'] ) ? (string) $assessment['state'] : '';
		if ( in_array( $state, PolicyAllowlist::TERMINAL_STATES, true ) ) {
			return $state;
		}
		return 'none';
	}

	private static function neutral_copy( string $state, string $failure ): string {
		if ( 'indeterminate' === $state ) {
			return __( 'Assessment inconclusive', 'universal-product-reviews' );
		}
		if ( 'skipped' === $state && 'content_edited' === $failure ) {
			return __( 'Stale — content edited', 'universal-product-reviews' );
		}
		if ( 'credential_missing' === $failure ) {
			return __( 'External credential missing', 'universal-product-reviews' );
		}
		if ( 'budget_exceeded' === $failure ) {
			return __( 'External quota exhausted', 'universal-product-reviews' );
		}
		if ( in_array( $failure, array( 'provider_unavailable', 'circuit_open' ), true ) ) {
			return __( 'Provider unavailable', 'universal-product-reviews' );
		}
		if ( 'failed' === $state ) {
			$label = self::failure_code_label( $failure );
			return sprintf(
				/* translators: %s: allowlisted failure code label */
				__( 'Assessment failed — %s', 'universal-product-reviews' ),
				$label
			);
		}
		if ( 'skipped' === $state ) {
			$label = self::failure_code_label( $failure );
			return sprintf(
				/* translators: %s: allowlisted failure code label */
				__( 'Assessment skipped — %s', 'universal-product-reviews' ),
				$label
			);
		}
		return __( 'Assessment unavailable', 'universal-product-reviews' );
	}

	private static function failure_code_label( string $code ): string {
		if ( '' === $code || ! PolicyAllowlist::is_failure_code( $code ) ) {
			return __( 'unknown', 'universal-product-reviews' );
		}
		return str_replace( '_', ' ', $code );
	}

	/**
	 * @param mixed $raw JSON string, array, or null.
	 * @return list<string>
	 */
	private static function parse_reason_codes( $raw ): array {
		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return array();
			}
		} else {
			return array();
		}

		$out = array();
		foreach ( $decoded as $code ) {
			if ( ! is_string( $code ) || ! PolicyAllowlist::is_reason_code( $code ) ) {
				continue;
			}
			$out[] = $code;
			if ( count( $out ) >= PolicyAllowlist::MAX_REASON_CODES ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * @param list<string> $codes Allowlisted reason codes.
	 * @return array{spam_likelihood:string,relevance:string,safety_policy:string,content_signal:string}
	 */
	private static function dimensions_from_codes( array $codes, string $state ): array {
		if ( 'completed' !== $state ) {
			return array(
				'spam_likelihood' => self::DIM_UNAVAILABLE,
				'relevance'       => self::DIM_UNAVAILABLE,
				'safety_policy'   => self::DIM_UNAVAILABLE,
				'content_signal'  => self::DIM_UNAVAILABLE,
			);
		}

		$spam = array_intersect( $codes, RecommendationPolicy::SPAM_FAMILY_CODES ) !== array()
			? self::DIM_SUSPECTED
			: self::DIM_NONE_INDICATED;

		$relevance = in_array( 'off_topic', $codes, true )
			? self::DIM_SUSPECTED
			: self::DIM_NONE_INDICATED;

		$safety_codes = array_merge(
			RecommendationPolicy::MANDATORY_HUMAN_CODES,
			RecommendationPolicy::ABUSE_FAMILY_CODES
		);
		$safety = array_intersect( $codes, $safety_codes ) !== array()
			? self::DIM_SUSPECTED
			: self::DIM_NONE_INDICATED;

		$content = self::DIM_NONE_INDICATED;
		if ( in_array( 'insufficient_signal', $codes, true ) ) {
			$content = self::DIM_INSUFFICIENT;
		} elseif ( in_array( 'unsupported_language', $codes, true ) ) {
			$content = self::DIM_UNSUPPORTED_LANG;
		}

		return array(
			'spam_likelihood' => $spam,
			'relevance'       => $relevance,
			'safety_policy'   => $safety,
			'content_signal'  => $content,
		);
	}

	/**
	 * @param list<string> $codes Codes.
	 * @return list<string>
	 */
	private static function rationale_labels( array $codes ): array {
		$labels = array();
		foreach ( $codes as $code ) {
			$labels[] = str_replace( '_', ' ', $code );
		}
		return $labels;
	}
}
