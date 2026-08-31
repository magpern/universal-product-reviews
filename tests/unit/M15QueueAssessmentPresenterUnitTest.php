<?php
/**
 * M15 QueueAssessmentPresenter unit tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Ai\Recommendation;
use UniversalProductReviews\Ai\RecommendationPolicy;
use UniversalProductReviews\Moderation\QueueAssessmentPresenter;

final class M15QueueAssessmentPresenterUnitTest extends TestCase {

	public function test_likely_publishable_presenter_label_not_action_label(): void {
		$row = array(
			'state'                    => 'completed',
			'publication_safety_score' => 20,
			'confidence'               => 'high',
			'reason_codes'             => '[]',
			'provider_kind'            => 'local',
		);
		$presented = QueueAssessmentPresenter::present( $row, true, true );
		$this->assertSame( Recommendation::ACTION_LIKELY_PUBLISHABLE, $presented['overall_action'] );
		$this->assertStringContainsString( 'Likely acceptable', $presented['status_copy'] );
		$this->assertStringContainsString( 'human must publish', strtolower( $presented['status_copy'] ) );
		$this->assertFalse( QueueAssessmentPresenter::label_contains_forbidden_approval_wording( $presented['status_copy'] ) );

		$global = RecommendationPolicy::action_label( Recommendation::ACTION_LIKELY_PUBLISHABLE );
		$this->assertStringContainsString( 'Likely publishable', $global );
		$this->assertStringNotContainsString( 'Likely acceptable', $global );
	}

	public function test_content_edited_stale_hides_prior_risk(): void {
		$row = array(
			'state'                    => 'skipped',
			'failure_code'             => 'content_edited',
			'publication_safety_score' => 90,
			'confidence'               => 'high',
			'reason_codes'             => wp_json_encode( array( 'spam_pattern' ) ),
			'provider_kind'            => 'local',
		);
		$presented = QueueAssessmentPresenter::present( $row, true, true );
		$this->assertStringContainsString( 'Stale', $presented['status_copy'] );
		$this->assertNull( $presented['risk_score'] );
		$this->assertSame( QueueAssessmentPresenter::DIM_UNAVAILABLE, $presented['dimensions']['spam_likelihood'] );
		$html = QueueAssessmentPresenter::render_definition_list( $presented );
		$this->assertStringNotContainsString( 'risk 90', $html );
		$this->assertStringNotContainsString( 'spam pattern', $html );
	}

	public function test_non_held_historical_only(): void {
		$row = array(
			'state'                    => 'completed',
			'publication_safety_score' => 20,
			'confidence'               => 'high',
			'reason_codes'             => '[]',
			'provider_kind'            => 'local',
		);
		$presented = QueueAssessmentPresenter::present( $row, false, true );
		$this->assertSame( 'Historical assessment', $presented['status_copy'] );
		$this->assertFalse( $presented['show_dimensions'] );
		$this->assertSame( '', QueueAssessmentPresenter::render_definition_list( $presented ) );
	}

	public function test_display_off_em_dash(): void {
		$presented = QueueAssessmentPresenter::present(
			array( 'state' => 'completed', 'publication_safety_score' => 20, 'confidence' => 'high', 'reason_codes' => '[]' ),
			true,
			false
		);
		$this->assertSame( '—', $presented['status_copy'] );
	}

	public function test_no_assessment_unavailable(): void {
		$presented = QueueAssessmentPresenter::present( null, true, true );
		$this->assertSame( 'Assessment unavailable', $presented['status_copy'] );
		$this->assertFalse( $presented['assessment_available'] );
		$this->assertSame( 'none', $presented['assessment_state'] );
	}

	public function test_credential_missing_and_quota_copy(): void {
		$cred = QueueAssessmentPresenter::present(
			array( 'state' => 'failed', 'failure_code' => 'credential_missing', 'provider_kind' => 'openai' ),
			true,
			true
		);
		$this->assertStringContainsString( 'credential missing', strtolower( $cred['status_copy'] ) );

		$quota = QueueAssessmentPresenter::present(
			array( 'state' => 'skipped', 'failure_code' => 'budget_exceeded', 'provider_kind' => 'openai' ),
			true,
			true
		);
		$this->assertStringContainsString( 'quota', strtolower( $quota['status_copy'] ) );
	}

	public function test_completed_dimensions_and_escaped_dl(): void {
		$row = array(
			'state'                    => 'completed',
			'publication_safety_score' => 85,
			'confidence'               => 'high',
			'reason_codes'             => wp_json_encode( array( 'spam_pattern', 'off_topic', 'pii_suspected' ) ),
			'provider_kind'            => 'local',
		);
		$presented = QueueAssessmentPresenter::present( $row, true, true );
		$this->assertSame( QueueAssessmentPresenter::DIM_SUSPECTED, $presented['dimensions']['spam_likelihood'] );
		$this->assertSame( QueueAssessmentPresenter::DIM_SUSPECTED, $presented['dimensions']['relevance'] );
		$this->assertSame( QueueAssessmentPresenter::DIM_SUSPECTED, $presented['dimensions']['safety_policy'] );
		$html = QueueAssessmentPresenter::render_definition_list( $presented );
		$this->assertStringContainsString( '<dl class="upr-queue-assessment">', $html );
		$this->assertStringContainsString( 'Spam likelihood', $html );
		$this->assertStringContainsString( 'Content signal', $html );
		$this->assertStringNotContainsString( '<script', $html );
	}

	public function test_reason_code_cap_eight(): void {
		$codes = array(
			'spam_pattern',
			'link_abuse',
			'pii_suspected',
			'contact_info_suspected',
			'abuse_harassment',
			'threat_suspected',
			'hate_suspected',
			'off_topic',
			'fraud_suspected',
		);
		$row = array(
			'state'                    => 'completed',
			'publication_safety_score' => 50,
			'confidence'               => 'medium',
			'reason_codes'             => wp_json_encode( $codes ),
			'provider_kind'            => 'local',
		);
		$presented = QueueAssessmentPresenter::present( $row, true, true );
		$this->assertLessThanOrEqual( 8, count( $presented['rationale_labels'] ) );
	}
}
