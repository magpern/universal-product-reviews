<?php
/**
 * Database schema definitions for UPR custom tables.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const DB_VERSION = '20260829a';

	public const OPS_ROW_ID = 1;

	/**
	 * @return array<string, string> table_name => CREATE TABLE SQL
	 */
	public static function table_definitions(): array {
		global $wpdb;

		$charset      = $wpdb->get_charset_collate();
		$invite       = $wpdb->prefix . 'upr_invite_items';
		$tokens       = $wpdb->prefix . 'upr_tokens';
		$audit        = $wpdb->prefix . 'upr_audit';
		$assessments  = $wpdb->prefix . 'upr_moderation_assessments';
		$claims       = $wpdb->prefix . 'upr_moderation_assessment_claims';
		$ops          = $wpdb->prefix . 'upr_moderation_ops';
		$external_ops = $wpdb->prefix . 'upr_moderation_external_ops';

		return array(
			$invite => "CREATE TABLE {$invite} (
				order_item_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				variation_id bigint(20) unsigned DEFAULT NULL,
				eligible_at datetime DEFAULT NULL,
				source_event_at datetime DEFAULT NULL,
				initial_send_started_at datetime DEFAULT NULL,
				initial_sent_at datetime DEFAULT NULL,
				initial_message_id varchar(64) DEFAULT NULL,
				initial_attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
				initial_last_error varchar(191) DEFAULT NULL,
				reminder_send_started_at datetime DEFAULT NULL,
				reminder_sent_at datetime DEFAULT NULL,
				reminder_message_id varchar(64) DEFAULT NULL,
				reminder_attempt_count smallint(5) unsigned NOT NULL DEFAULT 0,
				reminder_last_error varchar(191) DEFAULT NULL,
				review_completed_at datetime DEFAULT NULL,
				review_comment_id bigint(20) unsigned DEFAULT NULL,
				schedule_state varchar(32) NOT NULL,
				submit_claim_token varchar(64) DEFAULT NULL,
				submit_claim_expires_at datetime DEFAULT NULL,
				submit_claim_prior_state varchar(32) DEFAULT NULL,
				bundle_id varchar(64) DEFAULT NULL,
				suppression_code varchar(64) DEFAULT NULL,
				delay_until datetime DEFAULT NULL,
				delivery_source varchar(16) DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (order_item_id),
				KEY order_id (order_id),
				KEY schedule_eligible (schedule_state, eligible_at),
				KEY product_id (product_id),
				KEY delay_until (delay_until),
				KEY bundle_id (bundle_id),
				KEY initial_message_id (initial_message_id),
				KEY submit_claim_expires (submit_claim_expires_at),
				UNIQUE KEY review_comment_id (review_comment_id)
			) {$charset};",
			$tokens => "CREATE TABLE {$tokens} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_item_id bigint(20) unsigned NOT NULL,
				purpose varchar(16) NOT NULL,
				token_hash char(64) NOT NULL,
				parent_token_id bigint(20) unsigned DEFAULT NULL,
				product_id bigint(20) unsigned NOT NULL,
				expires_at datetime NOT NULL,
				revoked_at datetime DEFAULT NULL,
				redeemed_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				meta_json text DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY token_hash (token_hash),
				KEY item_purpose (order_item_id, purpose, revoked_at, redeemed_at),
				KEY parent_token_id (parent_token_id),
				KEY expires_at (expires_at),
				KEY purpose_expires (purpose, expires_at)
			) {$charset};",
			$audit => "CREATE TABLE {$audit} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				occurred_at datetime NOT NULL,
				actor_type varchar(16) NOT NULL,
				event_type varchar(64) NOT NULL,
				order_id bigint(20) unsigned DEFAULT NULL,
				order_item_id bigint(20) unsigned DEFAULT NULL,
				payload_json text DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY occurred_at (occurred_at),
				KEY item_occurred (order_item_id, occurred_at),
				KEY event_occurred (event_type, occurred_at)
			) {$charset};",
			$assessments => "CREATE TABLE {$assessments} (
				assessment_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				schema_version varchar(64) NOT NULL,
				comment_id bigint(20) unsigned NOT NULL,
				mode varchar(16) NOT NULL,
				state varchar(32) NOT NULL,
				publication_safety_score tinyint unsigned DEFAULT NULL,
				confidence varchar(16) DEFAULT NULL,
				reason_codes text DEFAULT NULL,
				policy_version varchar(32) NOT NULL,
				provider_kind varchar(16) NOT NULL,
				provider_fingerprint char(64) NOT NULL,
				failure_code varchar(64) DEFAULT NULL,
				requested_at datetime NOT NULL,
				completed_at datetime NOT NULL,
				retention_due_at datetime NOT NULL,
				PRIMARY KEY  (assessment_id),
				KEY comment_completed (comment_id, completed_at),
				KEY retention_due_at (retention_due_at),
				KEY state_completed (state, completed_at)
			) {$charset};",
			$claims => "CREATE TABLE {$claims} (
				comment_id bigint(20) unsigned NOT NULL,
				policy_version varchar(32) NOT NULL,
				claim_token varchar(64) DEFAULT NULL,
				claim_expires_at datetime DEFAULT NULL,
				requested_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (comment_id, policy_version)
			) {$charset};",
			$ops => "CREATE TABLE {$ops} (
				id tinyint unsigned NOT NULL,
				rate_window_started_at datetime NOT NULL,
				rate_count smallint unsigned NOT NULL DEFAULT 0,
				consecutive_failures smallint unsigned NOT NULL DEFAULT 0,
				circuit_open_until datetime DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id)
			) {$charset};",
			$external_ops => "CREATE TABLE {$external_ops} (
				id tinyint unsigned NOT NULL,
				day_key varchar(10) NOT NULL,
				day_count int unsigned NOT NULL DEFAULT 0,
				month_key varchar(7) NOT NULL,
				month_count int unsigned NOT NULL DEFAULT 0,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id)
			) {$charset};",
		);
	}

	/**
	 * Idempotent seed for the single moderation ops row.
	 */
	public static function seed_moderation_ops_row(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'upr_moderation_ops';
		$now   = gmdate( 'Y-m-d H:i:s' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (id, rate_window_started_at, rate_count, consecutive_failures, circuit_open_until, updated_at)
				VALUES (%d, %s, %d, %d, NULL, %s)",
				self::OPS_ROW_ID,
				$now,
				0,
				0,
				$now
			)
		);
	}

	/**
	 * Idempotent seed for external OpenAI quota row.
	 */
	public static function seed_moderation_external_ops_row(): void {
		global $wpdb;

		$table = $wpdb->prefix . 'upr_moderation_external_ops';
		$now   = gmdate( 'Y-m-d H:i:s' );
		$day   = gmdate( 'Y-m-d' );
		$month = gmdate( 'Y-m' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (id, day_key, day_count, month_key, month_count, updated_at)
				VALUES (%d, %s, %d, %s, %d, %s)",
				self::OPS_ROW_ID,
				$day,
				0,
				$month,
				0,
				$now
			)
		);
	}
}
