<?php
/**
 * Unit tests for M9 built-in assessor boundary.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Ai\AssessmentRequest;
use UniversalProductReviews\Ai\AssessmentResult;
use UniversalProductReviews\Ai\AssessmentValidator;
use UniversalProductReviews\Ai\BuiltInLocalAssessor;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Ai\ProviderFingerprint;

final class M9BuiltinAssessorUnitTest extends TestCase {

	protected function tearDown(): void {
		BuiltInLocalAssessor::set_test_assessor( null );
		parent::tearDown();
	}

	public function test_fingerprint_stable_and_salt_free(): void {
		$a = ProviderFingerprint::for_builtin();
		$b = ProviderFingerprint::for_builtin();
		$this->assertSame( $a, $b );
		$this->assertSame( 64, strlen( $a ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $a );
	}

	public function test_request_has_no_rating_field(): void {
		$ref = new \ReflectionClass( AssessmentRequest::class );
		$props = array_map( static fn( $p ) => $p->getName(), $ref->getProperties() );
		$this->assertNotContains( 'rating', $props );
		$this->assertContains( 'review_text', $props );
		$this->assertContains( 'policy_version', $props );
	}

	public function test_heuristic_flags_links(): void {
		$result = BuiltInLocalAssessor::assess(
			new AssessmentRequest( 'Great product see https://spam.example/buy', PolicyAllowlist::POLICY_VERSION )
		);
		$validated = AssessmentValidator::validate( $result );
		$this->assertInstanceOf( AssessmentResult::class, $validated );
		$this->assertSame( 'completed', $validated->state );
		$this->assertContains( 'link_abuse', $validated->reason_codes );
	}

	public function test_short_text_indeterminate(): void {
		$result = BuiltInLocalAssessor::assess(
			new AssessmentRequest( 'ok', PolicyAllowlist::POLICY_VERSION )
		);
		$validated = AssessmentValidator::validate( $result );
		$this->assertInstanceOf( AssessmentResult::class, $validated );
		$this->assertSame( 'indeterminate', $validated->state );
		$this->assertNull( $validated->publication_safety_score );
	}

	public function test_validator_rejects_sentiment_code(): void {
		$raw = new AssessmentResult( 'completed', 50, 'high', array( 'sentiment_negative' ), null );
		$this->assertSame( 'validation_rejected', AssessmentValidator::validate( $raw ) );
	}

	public function test_validator_rejects_out_of_range_score(): void {
		$raw = new AssessmentResult( 'completed', 0, 'high', array(), null );
		$this->assertSame( 'validation_rejected', AssessmentValidator::validate( $raw ) );
	}

	public function test_validator_rejects_too_many_codes(): void {
		$codes = array_slice( PolicyAllowlist::REASON_CODES, 0, 9 );
		$raw   = new AssessmentResult( 'completed', 40, 'medium', $codes, null );
		$this->assertSame( 'validation_rejected', AssessmentValidator::validate( $raw ) );
	}

	public function test_test_seam_does_not_use_filter(): void {
		BuiltInLocalAssessor::set_test_assessor(
			static function (): AssessmentResult {
				return new AssessmentResult( 'completed', 33, 'low', array( 'off_topic' ), null );
			}
		);
		$result = BuiltInLocalAssessor::assess( new AssessmentRequest( 'hello world text', PolicyAllowlist::POLICY_VERSION ) );
		$this->assertSame( 33, $result->publication_safety_score );
	}
}
