<?php
/**
 * M11 recommendation policy unit tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Ai\Recommendation;
use UniversalProductReviews\Ai\RecommendationPolicy;
use UniversalProductReviews\Moderation\CommentListEnhancements;

final class M11RecommendationPolicyUnitTest extends TestCase {

	public function test_high_risk_spam_family(): void {
		$rec = RecommendationPolicy::suggest(
			array(
				'state'                     => 'completed',
				'publication_safety_score'  => 85,
				'confidence'                => 'high',
				'reason_codes'              => wp_json_encode( array( 'spam_pattern' ) ),
			)
		);
		$this->assertSame( Recommendation::ACTION_LIKELY_SPAM, $rec->action );
	}

	public function test_high_risk_abuse_family(): void {
		$rec = RecommendationPolicy::suggest(
			array(
				'state'                    => 'completed',
				'publication_safety_score' => 90,
				'confidence'               => 'high',
				'reason_codes'             => wp_json_encode( array( 'threat_suspected' ) ),
			)
		);
		$this->assertSame( Recommendation::ACTION_LIKELY_ABUSE, $rec->action );
	}

	public function test_mandatory_human_precedes_spam(): void {
		$rec = RecommendationPolicy::suggest(
			array(
				'state'                    => 'completed',
				'publication_safety_score' => 95,
				'confidence'               => 'high',
				'reason_codes'             => wp_json_encode( array( 'spam_pattern', 'pii_suspected' ) ),
			)
		);
		$this->assertSame( Recommendation::ACTION_MANDATORY_HUMAN, $rec->action );
	}

	public function test_low_risk_likely_publishable(): void {
		$rec = RecommendationPolicy::suggest(
			array(
				'state'                    => 'completed',
				'publication_safety_score' => 20,
				'confidence'               => 'medium',
				'reason_codes'             => '[]',
			)
		);
		$this->assertSame( Recommendation::ACTION_LIKELY_PUBLISHABLE, $rec->action );
		$label = RecommendationPolicy::action_label( $rec->action );
		$this->assertStringContainsString( 'advisory', strtolower( $label ) );
		$this->assertStringContainsString( 'human must approve', strtolower( $label ) );
	}

	public function test_inverted_thresholds_do_not_swap_actions(): void {
		// Low risk must NOT map to likely_spam even with spam code if score < 80.
		$low = RecommendationPolicy::suggest(
			array(
				'state'                    => 'completed',
				'publication_safety_score' => 30,
				'confidence'               => 'high',
				'reason_codes'             => wp_json_encode( array( 'spam_pattern' ) ),
			)
		);
		$this->assertNotSame( Recommendation::ACTION_LIKELY_SPAM, $low->action );
		$this->assertSame( Recommendation::ACTION_LIKELY_PUBLISHABLE, $low->action );

		// High risk without family codes is needs_human, not publishable.
		$high = RecommendationPolicy::suggest(
			array(
				'state'                    => 'completed',
				'publication_safety_score' => 90,
				'confidence'               => 'high',
				'reason_codes'             => '[]',
			)
		);
		$this->assertNotSame( Recommendation::ACTION_LIKELY_PUBLISHABLE, $high->action );
		$this->assertSame( Recommendation::ACTION_NEEDS_HUMAN, $high->action );
	}

	/**
	 * @dataProvider abstention_provider
	 * @param array<string, mixed>|null $row Row.
	 */
	public function test_abstention_paths( $row ): void {
		$rec = RecommendationPolicy::suggest( $row );
		$this->assertSame( Recommendation::ACTION_NEEDS_HUMAN, $rec->action );
	}

	/**
	 * @return array<string, array{0:mixed}>
	 */
	public function abstention_provider(): array {
		return array(
			'null'          => array( null ),
			'failed'        => array( array( 'state' => 'failed', 'publication_safety_score' => 80, 'confidence' => 'high', 'reason_codes' => '[]' ) ),
			'skipped'       => array( array( 'state' => 'skipped', 'publication_safety_score' => 80, 'confidence' => 'high', 'reason_codes' => '[]' ) ),
			'indeterminate' => array( array( 'state' => 'indeterminate', 'publication_safety_score' => null, 'confidence' => 'low', 'reason_codes' => '[]' ) ),
			'low_conf'      => array( array( 'state' => 'completed', 'publication_safety_score' => 80, 'confidence' => 'low', 'reason_codes' => wp_json_encode( array( 'spam_pattern' ) ) ) ),
			'null_score'    => array( array( 'state' => 'completed', 'publication_safety_score' => null, 'confidence' => 'high', 'reason_codes' => '[]' ) ),
			'malformed'     => array( array( 'state' => 'completed', 'publication_safety_score' => 'nope', 'confidence' => 'high', 'reason_codes' => '[]' ) ),
		);
	}

	public function test_column_held_shows_actionable_and_non_held_hides(): void {
		$row = array(
			'state'                    => 'completed',
			'publication_safety_score' => 85,
			'confidence'               => 'high',
			'provider_kind'            => 'local',
			'reason_codes'             => wp_json_encode( array( 'spam_pattern' ) ),
		);
		$held = CommentListEnhancements::format_ai_advisory_display( $row, true, true );
		$this->assertStringContainsString( 'Likely spam', $held );
		$this->assertStringContainsString( 'risk 85', $held );
		$this->assertStringNotContainsString( '<script>', $held );

		$hist = CommentListEnhancements::format_ai_advisory_display( $row, false, true );
		$this->assertSame( 'Historical assessment', $hist );
		$this->assertStringNotContainsString( 'Likely spam', $hist );
	}

	public function test_display_disabled_hides_labels(): void {
		$row = array(
			'state'                    => 'completed',
			'publication_safety_score' => 20,
			'confidence'               => 'high',
			'provider_kind'            => 'local',
			'reason_codes'             => '[]',
		);
		$this->assertSame( '—', CommentListEnhancements::format_ai_advisory_display( $row, true, false ) );
	}

	public function test_injection_like_reason_codes_ignored(): void {
		$rec = RecommendationPolicy::suggest(
			array(
				'state'                    => 'completed',
				'publication_safety_score' => 20,
				'confidence'               => 'high',
				'reason_codes'             => wp_json_encode( array( '<script>alert(1)</script>', 'spam_pattern' ) ),
			)
		);
		// Invalid codes dropped; spam alone with low risk → publishable.
		$this->assertSame( Recommendation::ACTION_LIKELY_PUBLISHABLE, $rec->action );
		$out = CommentListEnhancements::format_ai_advisory_display(
			array(
				'state'                    => 'completed',
				'publication_safety_score' => 20,
				'confidence'               => 'high',
				'provider_kind'            => 'local',
				'reason_codes'             => wp_json_encode( array( '<script>alert(1)</script>' ) ),
			),
			true,
			true
		);
		$this->assertStringNotContainsString( '<script>', $out );
	}

	public function test_no_provider_emitted_action_field_honored(): void {
		$rec = RecommendationPolicy::suggest(
			array(
				'state'                    => 'completed',
				'publication_safety_score' => 20,
				'confidence'               => 'high',
				'reason_codes'             => '[]',
				'suggested_action'         => 'likely_spam',
			)
		);
		$this->assertSame( Recommendation::ACTION_LIKELY_PUBLISHABLE, $rec->action );
	}
}
