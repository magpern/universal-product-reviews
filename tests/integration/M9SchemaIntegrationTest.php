<?php
/**
 * M9 schema migration coverage.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Database\Schema;
use WP_UnitTestCase;

final class M9SchemaIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
	}

	public function test_m9_tables_exist_after_upgrade(): void {
		global $wpdb;

		$this->assertSame( Schema::DB_VERSION, (string) get_option( Migrator::OPTION_VERSION, '' ) );
		$this->assertTrue( Migrator::tables_exist() );

		foreach ( array(
			$wpdb->prefix . 'upr_moderation_assessments',
			$wpdb->prefix . 'upr_moderation_assessment_claims',
			$wpdb->prefix . 'upr_moderation_ops',
			$wpdb->prefix . 'upr_moderation_external_ops',
		) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found );
		}
	}

	public function test_ops_row_seeded_idempotently(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'upr_moderation_ops';
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, $count );

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", Schema::OPS_ROW_ID ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertIsArray( $row );
		$this->assertSame( (string) Schema::OPS_ROW_ID, (string) $row['id'] );

		Schema::seed_moderation_ops_row();
		$count2 = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 1, $count2 );
	}

	public function test_claims_primary_key_is_composite(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'upr_moderation_assessment_claims';
		$keys  = $wpdb->get_results( "SHOW KEYS FROM {$table} WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertCount( 2, $keys );
		$cols = array_column( $keys, 'Column_name' );
		$this->assertSame( array( 'comment_id', 'policy_version' ), $cols );
	}

	public function test_upgrade_is_idempotent_at_target_version(): void {
		Migrator::reset_schema_run_count();
		$this->assertTrue( Migrator::upgrade_now() );
		$this->assertSame( 0, Migrator::schema_run_count() );
	}
}
