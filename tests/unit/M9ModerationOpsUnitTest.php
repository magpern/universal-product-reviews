<?php
/**
 * M9 ModerationOpsRepository read helpers.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Ai\ModerationOpsRepository;

final class M9ModerationOpsUnitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['upr_test_moderation_ops_row'] = array(
			'rate_window_started_at' => gmdate( 'Y-m-d H:i:s' ),
			'rate_count'             => 0,
			'circuit_open_until'   => null,
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['upr_test_moderation_ops_row'] );
		parent::tearDown();
	}

	public function test_summarize_reports_closed_circuit_and_rate_ok(): void {
		$summary = ModerationOpsRepository::summarize();
		$this->assertTrue( $summary['ok'] );
		$this->assertFalse( $summary['circuit_open'] );
		$this->assertFalse( $summary['rate_limited'] );
		$this->assertSame( 0, $summary['rate_count'] );
	}

	public function test_summarize_detects_open_circuit(): void {
		$GLOBALS['upr_test_moderation_ops_row']['circuit_open_until'] = gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS );

		$summary = ModerationOpsRepository::summarize();
		$this->assertTrue( $summary['circuit_open'] );
	}

	public function test_summarize_detects_rate_limited_window(): void {
		$GLOBALS['upr_test_moderation_ops_row']['rate_window_started_at'] = gmdate( 'Y-m-d H:i:s' );
		$GLOBALS['upr_test_moderation_ops_row']['rate_count']             = ModerationOpsRepository::RATE_LIMIT_PER_HOUR;

		$summary = ModerationOpsRepository::summarize();
		$this->assertTrue( $summary['rate_limited'] );
	}
}
