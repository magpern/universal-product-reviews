<?php
/**
 * M10 integration: atomic external daily/monthly quotas.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Ai\ExternalQuotaRepository;
use UniversalProductReviews\Database\Schema;
use WP_UnitTestCase;

final class M10ExternalQuotaIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		Schema::seed_moderation_external_ops_row();
		$this->reset_external_ops();
	}

	public function test_consume_increments_both_counters(): void {
		$this->assertSame( 'ok', ExternalQuotaRepository::try_consume( 10, 100 ) );
		$sum = ExternalQuotaRepository::summarize();
		$this->assertTrue( $sum['ok'] );
		$this->assertSame( 1, $sum['day_count'] );
		$this->assertSame( 1, $sum['month_count'] );
	}

	public function test_monthly_full_does_not_consume_daily(): void {
		$this->seed_counts( 0, 5 );
		$this->assertSame( 'budget_exceeded', ExternalQuotaRepository::try_consume( 10, 5 ) );
		$sum = ExternalQuotaRepository::summarize();
		$this->assertSame( 0, $sum['day_count'] );
		$this->assertSame( 5, $sum['month_count'] );
	}

	public function test_daily_full_does_not_consume_monthly(): void {
		$this->seed_counts( 3, 3 );
		$this->assertSame( 'budget_exceeded', ExternalQuotaRepository::try_consume( 3, 100 ) );
		$sum = ExternalQuotaRepository::summarize();
		$this->assertSame( 3, $sum['day_count'] );
		$this->assertSame( 3, $sum['month_count'] );
	}

	private function reset_external_ops(): void {
		$this->seed_counts( 0, 0 );
	}

	private function seed_counts( int $day, int $month ): void {
		global $wpdb;
		$table = ExternalQuotaRepository::table();
		$wpdb->update(
			$table,
			array(
				'day_key'     => gmdate( 'Y-m-d' ),
				'day_count'   => $day,
				'month_key'   => gmdate( 'Y-m' ),
				'month_count' => $month,
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => Schema::OPS_ROW_ID ),
			array( '%s', '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);
	}
}
