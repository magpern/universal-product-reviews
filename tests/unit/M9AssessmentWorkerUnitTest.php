<?php
/**
 * Unit tests for M9 assessment worker support types (eligibility, retention).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Ai\AssessmentRetention;
use UniversalProductReviews\Ai\Eligibility;
use UniversalProductReviews\Moderation\ModerationAudit;

final class M9AssessmentWorkerUnitTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['upr_test_post_type'], $GLOBALS['upr_test_comments'], $GLOBALS['upr_test_options'] );
		parent::tearDown();
	}

	/**
	 * @dataProvider held_status_provider
	 */
	public function test_eligibility_normalises_held_statuses( string $raw, bool $expected ): void {
		$this->assertSame( $expected, Eligibility::is_held_status( $raw ) );
	}

	/**
	 * @return array<string, array{0:string,1:bool}>
	 */
	public static function held_status_provider(): array {
		return array(
			'zero'         => array( '0', true ),
			'hold'         => array( 'hold', true ),
			'unapproved'   => array( 'unapproved', true ),
			'approve'      => array( '1', false ),
			'approved'     => array( 'approved', false ),
			'spam'         => array( 'spam', false ),
			'trash'        => array( 'trash', false ),
		);
	}

	public function test_is_ai_assessable_requires_in_scope_top_level_held(): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		$GLOBALS['upr_test_comments'][42] = (object) array(
			'comment_ID'       => 42,
			'comment_post_ID'  => 5,
			'comment_type'     => 'review',
			'comment_parent'   => 0,
			'comment_approved' => '0',
			'comment_content'  => 'Held product review text here.',
		);

		$this->assertTrue( Eligibility::is_ai_assessable( 42 ) );

		$GLOBALS['upr_test_comments'][43] = (object) array(
			'comment_ID'       => 43,
			'comment_post_ID'  => 5,
			'comment_type'     => 'review',
			'comment_parent'   => 7,
			'comment_approved' => '0',
			'comment_content'  => 'Reply text.',
		);
		$this->assertFalse( Eligibility::is_ai_assessable( 43 ) );

		$GLOBALS['upr_test_comments'][44] = (object) array(
			'comment_ID'       => 44,
			'comment_post_ID'  => 5,
			'comment_type'     => 'review',
			'comment_parent'   => 0,
			'comment_approved' => '1',
			'comment_content'  => 'Approved review.',
		);
		$this->assertFalse( Eligibility::is_ai_assessable( 44 ) );
	}

	/**
	 * @dataProvider retention_due_provider
	 */
	public function test_retention_due_dates( string $status, int $days ): void {
		$from  = 1_700_000_000;
		$due   = AssessmentRetention::due_at_for_status( $status, $from );
		$expected = gmdate( 'Y-m-d H:i:s', $from + ( $days * DAY_IN_SECONDS ) );
		$this->assertSame( $expected, $due );
	}

	/**
	 * @return array<string, array{0:string,1:int}>
	 */
	public static function retention_due_provider(): array {
		return array(
			'hold numeric'    => array( '0', AssessmentRetention::DAYS_HOLD ),
			'hold string'     => array( 'hold', AssessmentRetention::DAYS_HOLD ),
			'approve'         => array( '1', AssessmentRetention::DAYS_APPROVE ),
			'approved string' => array( 'approved', AssessmentRetention::DAYS_APPROVE ),
			'spam'            => array( 'spam', AssessmentRetention::DAYS_SPAM ),
			'trash'           => array( 'trash', AssessmentRetention::DAYS_SPAM ),
		);
	}

	public function test_moderation_audit_status_normalisation_matches_eligibility(): void {
		$this->assertSame( 'hold', ModerationAudit::normalise_status( '0' ) );
		$this->assertSame( 'hold', ModerationAudit::normalise_status( 'unapproved' ) );
		$this->assertTrue( Eligibility::is_held_status( '0' ) );
	}
}
