<?php
/**
 * Built-in deterministic in-process publication-safety heuristic.
 *
 * Not filter-replaceable. Review text never leaves this module to host callbacks.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

final class BuiltInLocalAssessor {

	/**
	 * Optional test-only override callback: fn(AssessmentRequest): AssessmentResult.
	 * Production never sets this; tests may inject via set_test_assessor().
	 *
	 * @var callable|null
	 */
	private static $test_assessor = null;

	/**
	 * @param callable|null $assessor Test seam only.
	 */
	public static function set_test_assessor( ?callable $assessor ): void {
		self::$test_assessor = $assessor;
	}

	public static function assess( AssessmentRequest $request ): AssessmentResult {
		if ( null !== self::$test_assessor ) {
			$result = ( self::$test_assessor )( $request );
			if ( $result instanceof AssessmentResult ) {
				return $result;
			}
			throw new \RuntimeException( 'test_assessor_invalid' );
		}

		return self::heuristic( $request );
	}

	private static function heuristic( AssessmentRequest $request ): AssessmentResult {
		$text  = $request->review_text;
		$codes = array();
		$score = 15;

		$trimmed = trim( $text );
		if ( '' === $trimmed || strlen( $trimmed ) < 8 ) {
			return new AssessmentResult(
				'indeterminate',
				null,
				'low',
				array( 'insufficient_signal' ),
				null
			);
		}

		if ( preg_match( '/https?:\/\/|www\./i', $text ) ) {
			$codes[] = 'link_abuse';
			$score   = max( $score, 70 );
		}
		if ( preg_match( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text )
			|| preg_match( '/\b(?:\+?\d[\d\s().-]{7,}\d)\b/', $text )
			|| preg_match( '/\b(?:whatsapp|telegram|signal)\b/i', $text )
		) {
			$codes[] = 'contact_info_suspected';
			$codes[] = 'pii_suspected';
			$score   = max( $score, 75 );
		}
		if ( preg_match( '/\b(?:buy now|click here|seo|crypto|forex|viagra|casino)\b/i', $text ) ) {
			$codes[] = 'spam_pattern';
			$score   = max( $score, 80 );
		}
		if ( preg_match( '/\b(?:kill|murder|bomb threat)\b/i', $text ) ) {
			$codes[] = 'threat_suspected';
			$score   = max( $score, 90 );
		}
		if ( preg_match( '/\b(?:fda approved|ce marked|eu compliant)\b/i', $text ) ) {
			$codes[] = 'regulatory_claim_suspected';
			$score   = max( $score, 65 );
		}
		if ( preg_match( '/\b(?:cures?|treats?|diagnos(?:e|is)|miracle)\b/i', $text ) ) {
			$codes[] = 'medical_claim_suspected';
			$score   = max( $score, 70 );
		}
		if ( preg_match( '/\b(?:unsafe|exploded|caught fire|poison)\b/i', $text ) ) {
			$codes[] = 'safety_claim_suspected';
			$score   = max( $score, 60 );
		}

		$codes = array_values( array_unique( $codes ) );
		if ( count( $codes ) > PolicyAllowlist::MAX_REASON_CODES ) {
			$codes = array_slice( $codes, 0, PolicyAllowlist::MAX_REASON_CODES );
		}

		if ( array() === $codes ) {
			return new AssessmentResult( 'completed', 12, 'medium', array(), null );
		}

		$confidence = $score >= 80 ? 'high' : ( $score >= 60 ? 'medium' : 'low' );
		return new AssessmentResult( 'completed', min( 100, $score ), $confidence, $codes, null );
	}
}
