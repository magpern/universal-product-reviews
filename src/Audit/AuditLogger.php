<?php
/**
 * Audit log writer.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Audit;

defined( 'ABSPATH' ) || exit;

final class AuditLogger {

	/**
	 * @param array<string, mixed> $payload Non-secret payload only.
	 */
	public static function log(
		string $event_type,
		string $actor_type = 'system',
		?int $order_id = null,
		?int $order_item_id = null,
		array $payload = array()
	): void {
		global $wpdb;

		$table = $wpdb->prefix . 'upr_audit';
		$wpdb->insert(
			$table,
			array(
				'occurred_at'    => current_time( 'mysql', true ),
				'actor_type'     => substr( $actor_type, 0, 16 ),
				'event_type'     => substr( $event_type, 0, 64 ),
				'order_id'       => $order_id,
				'order_item_id'  => $order_item_id,
				'payload_json'   => $payload ? wp_json_encode( $payload ) : null,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}
}
