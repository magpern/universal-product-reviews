<?php
/**
 * Strict JSON Schema for OpenAI structured outputs + sentinel score mapping.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

use UniversalProductReviews\Ai\AssessmentResult;
use UniversalProductReviews\Ai\PolicyAllowlist;

defined( 'ABSPATH' ) || exit;

final class OpenAiStructuredOutput {

	/**
	 * OpenAI strict json_schema document (additionalProperties false; all props required).
	 *
	 * @return array<string, mixed>
	 */
	public static function json_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array(
				'state',
				'publication_safety_score',
				'confidence',
				'reason_codes',
			),
			'properties'           => array(
				'state'                     => array(
					'type' => 'string',
					'enum' => array( 'completed', 'indeterminate' ),
				),
				'publication_safety_score'  => array(
					'type'    => 'integer',
					'minimum' => OpenAiConstants::SCORE_SENTINEL_NULL,
					'maximum' => 100,
				),
				'confidence'                => array(
					'type' => 'string',
					'enum' => PolicyAllowlist::CONFIDENCE,
				),
				'reason_codes'              => array(
					'type'     => 'array',
					'maxItems' => PolicyAllowlist::MAX_REASON_CODES,
					'items'    => array(
						'type' => 'string',
						'enum' => PolicyAllowlist::REASON_CODES,
					),
				),
			),
		);
	}

	/**
	 * Map provider JSON object → AssessmentResult (sentinel 0 → null for indeterminate).
	 *
	 * @param array<string, mixed> $decoded Decoded schema object only.
	 */
	public static function map_to_result( array $decoded ): AssessmentResult {
		$state = isset( $decoded['state'] ) && is_string( $decoded['state'] ) ? $decoded['state'] : '';
		$confidence = isset( $decoded['confidence'] ) && is_string( $decoded['confidence'] ) ? $decoded['confidence'] : null;

		$codes = array();
		if ( isset( $decoded['reason_codes'] ) && is_array( $decoded['reason_codes'] ) ) {
			foreach ( $decoded['reason_codes'] as $c ) {
				if ( is_string( $c ) ) {
					$codes[] = $c;
				}
			}
		}

		$score_raw = $decoded['publication_safety_score'] ?? null;
		$score     = null;
		if ( is_int( $score_raw ) || ( is_numeric( $score_raw ) && (string) (int) $score_raw === (string) $score_raw ) ) {
			$score = (int) $score_raw;
		}

		if ( 'indeterminate' === $state && OpenAiConstants::SCORE_SENTINEL_NULL === $score ) {
			$score = null;
		}

		return new AssessmentResult( $state, $score, $confidence, $codes, null );
	}
}
