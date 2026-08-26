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

	public const DB_VERSION = '20260826';

	/**
	 * @return array<string, string> table_name => CREATE TABLE SQL (without dbDelta extras handled by caller)
	 */
	public static function table_definitions(): array {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$invite  = $wpdb->prefix . 'upr_invite_items';
		$tokens  = $wpdb->prefix . 'upr_tokens';
		$audit   = $wpdb->prefix . 'upr_audit';

		return array(
			$invite => "CREATE TABLE {$invite} (
				order_item_id bigint(20) unsigned NOT NULL,
				order_id bigint(20) unsigned NOT NULL,
				product_id bigint(20) unsigned NOT NULL,
				variation_id bigint(20) unsigned DEFAULT NULL,
				eligible_at datetime DEFAULT NULL,
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
		);
	}
}
