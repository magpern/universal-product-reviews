<?php
/**
 * Strict validation of assessor output.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

final class AssessmentValidator {

	/**
	 * @param mixed $raw Untyped assessor return.
	 * @return AssessmentResult|string Valid result or failure_code (validation_rejected|malformed).
	 */
	public static function validate( $raw ) {
		if ( ! $raw instanceof AssessmentResult ) {
			if ( ! is_array( $raw ) ) {
				return 'malformed';
			}
			$raw = self::from_array( $raw );
			if ( ! $raw instanceof AssessmentResult ) {
				return 'malformed';
			}
		}

		$state = $raw->state;
		if ( ! in_array( $state, array( 'completed', 'indeterminate' ), true ) ) {
			return 'validation_rejected';
		}

		if ( null !== $raw->failure_code ) {
			return 'validation_rejected';
		}

		if ( null === $raw->confidence || ! in_array( $raw->confidence, PolicyAllowlist::CONFIDENCE, true ) ) {
			return 'validation_rejected';
		}

		$codes = $raw->reason_codes;
		if ( count( $codes ) > PolicyAllowlist::MAX_REASON_CODES ) {
			return 'validation_rejected';
		}
		foreach ( $codes as $code ) {
			if ( ! is_string( $code ) || ! PolicyAllowlist::is_reason_code( $code ) ) {
				return 'validation_rejected';
			}
			if ( PolicyAllowlist::reason_code_has_forbidden_label( $code ) ) {
				return 'validation_rejected';
			}
		}

		if ( 'completed' === $state ) {
			$score = $raw->publication_safety_score;
			if ( null === $score || $score < 1 || $score > 100 ) {
				return 'validation_rejected';
			}
		} else {
			if ( null !== $raw->publication_safety_score ) {
				return 'validation_rejected';
			}
		}

		return $raw;
	}

	/**
	 * @param array<string, mixed> $data Raw array.
	 */
	private static function from_array( array $data ): ?AssessmentResult {
		$state = isset( $data['state'] ) && is_string( $data['state'] ) ? $data['state'] : '';
		$codes = array();
		if ( isset( $data['reason_codes'] ) && is_array( $data['reason_codes'] ) ) {
			foreach ( $data['reason_codes'] as $c ) {
				if ( is_string( $c ) ) {
					$codes[] = $c;
				}
			}
		}
		$score = null;
		if ( array_key_exists( 'publication_safety_score', $data ) && null !== $data['publication_safety_score'] ) {
			if ( ! is_int( $data['publication_safety_score'] ) && ! is_numeric( $data['publication_safety_score'] ) ) {
				return null;
			}
			$score = (int) $data['publication_safety_score'];
		}
		$confidence = isset( $data['confidence'] ) && is_string( $data['confidence'] ) ? $data['confidence'] : null;
		$failure    = isset( $data['failure_code'] ) && is_string( $data['failure_code'] ) ? $data['failure_code'] : null;

		return new AssessmentResult( $state, $score, $confidence, $codes, $failure );
	}
}
