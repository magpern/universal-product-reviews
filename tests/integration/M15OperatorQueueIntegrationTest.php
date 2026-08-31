<?php
/**
 * M15 operator AI moderation queue integration tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Admin\OverviewRepository;
use UniversalProductReviews\Admin\PluginActionLinks;
use UniversalProductReviews\Ai\AssessmentRepository;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Ai\ProviderFingerprint;
use UniversalProductReviews\Moderation\CommentListEnhancements;
use UniversalProductReviews\Moderation\CommentListPrefetch;
use UniversalProductReviews\Moderation\OperatorQueueKeepHold;
use UniversalProductReviews\Moderation\QueueAssessmentPresenter;
use UniversalProductReviews\Plugin;
use WP_UnitTestCase;

final class M15OperatorQueueIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	/** @var int */
	private $admin_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		CommentListPrefetch::reset_for_tests();
		CommentListEnhancements::reset_for_tests();
		OperatorQueueKeepHold::reset_for_tests();
		Plugin::reset_for_tests();
		Plugin::init();
		delete_transient( 'upr_held_review_count_v1' );
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );
		$user = new \WP_User( $this->admin_id );
		$user->add_cap( 'moderate_comments' );
		$user->add_cap( 'manage_woocommerce' );
	}

	public function tear_down(): void {
		CommentListPrefetch::reset_for_tests();
		CommentListEnhancements::reset_for_tests();
		OperatorQueueKeepHold::reset_for_tests();
		unset( $_GET, $_POST, $_REQUEST, $GLOBALS['pagenow'] );
		delete_transient( 'upr_held_review_count_v1' );
		parent::tear_down();
	}

	public function test_native_publish_emits_status_changed_not_deferred(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$before     = $this->count_events( 'review.operator_deferred' );
		$before_sc  = $this->count_events( 'review.status_changed' );

		wp_set_comment_status( $comment_id, 'approve' );

		$this->assertSame( '1', (string) get_comment( $comment_id )->comment_approved );
		$this->assertSame( $before, $this->count_events( 'review.operator_deferred' ) );
		$this->assertSame( $before_sc + 1, $this->count_events( 'review.status_changed' ) );
	}

	public function test_keep_hold_writes_no_status_and_emits_deferred(): void {
		$product_id = $this->upr_create_product();
		$pack       = $this->upr_create_order_with_item( $product_id );
		$comment_id = $this->insert_held_review( $product_id );
		update_comment_meta( $comment_id, '_upr_order_item_id', $pack['order_item_id'] );
		$this->insert_completed_assessment( $comment_id );

		$before_status = (string) get_comment( $comment_id )->comment_approved;
		$before_audit  = $this->count_events( 'review.operator_deferred' );

		OperatorQueueKeepHold::emit_deferred_audit( get_comment( $comment_id ) );
		// Same-request dedupe.
		OperatorQueueKeepHold::emit_deferred_audit( get_comment( $comment_id ) );

		$this->assertSame( $before_status, (string) get_comment( $comment_id )->comment_approved );
		$this->assertSame( $before_audit + 1, $this->count_events( 'review.operator_deferred' ) );

		$row = $this->latest_event( 'review.operator_deferred' );
		$this->assertNotNull( $row );
		$this->assertSame( 'moderator', $row['actor_type'] );
		$payload = json_decode( (string) $row['payload_json'], true );
		$this->assertIsArray( $payload );
		$this->assertSame( $comment_id, (int) $payload['comment_id'] );
		$this->assertSame( 'keep_hold', $payload['queue_action'] );
		$this->assertSame( 'hold', $payload['old_status'] );
		$this->assertSame( 'hold', $payload['new_status'] );
		$this->assertTrue( (bool) $payload['assessment_available'] );
		$this->assertSame( 'completed', $payload['assessment_state'] );
		$this->assertArrayNotHasKey( 'assessment_id', $payload );
		$this->assertArrayNotHasKey( 'policy_version', $payload );
		$this->assertArrayNotHasKey( 'reason_codes', $payload );
		$this->assertSame( (int) $pack['order']->get_id(), (int) $row['order_id'] );
		$this->assertSame( (int) $pack['order_item_id'], (int) $row['order_item_id'] );
	}

	public function test_separate_requests_emit_separate_deferred_events(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$before     = $this->count_events( 'review.operator_deferred' );

		OperatorQueueKeepHold::emit_deferred_audit( get_comment( $comment_id ) );
		OperatorQueueKeepHold::reset_for_tests();
		OperatorQueueKeepHold::emit_deferred_audit( get_comment( $comment_id ) );

		$this->assertSame( $before + 2, $this->count_events( 'review.operator_deferred' ) );
	}

	public function test_presenter_on_pending_held_renders_dl_without_secrets(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$this->insert_completed_assessment( $comment_id );

		$_GET['upr_view']       = CommentListEnhancements::VIEW_PENDING;
		$GLOBALS['pagenow']     = 'edit-comments.php';
		set_current_screen( 'edit-comments' );
		CommentListEnhancements::on_current_screen( get_current_screen() );

		$map = AssessmentRepository::latest_for_comments( array( $comment_id ) );
		$presented = QueueAssessmentPresenter::present( $map[ $comment_id ] ?? null, true, true );
		$html      = QueueAssessmentPresenter::render_definition_list( $presented );

		$this->assertStringContainsString( '<dl', $html );
		$this->assertStringContainsString( 'Likely spam', $presented['status_copy'] );
		$this->assertStringNotContainsString( 'sk-proj', $html );
		$this->assertStringNotContainsString( 'Synthetic held review', $html );
		$this->assertStringNotContainsString( 'synthetic@example.test', $html );
		$this->assertStringNotContainsString( 'assessment_id', $html );
	}

	public function test_held_count_aggregate_and_no_id_leak(): void {
		$product_id = $this->upr_create_product();
		$this->insert_held_review( $product_id );
		$this->insert_held_review( $product_id );

		delete_transient( 'upr_held_review_count_v1' );
		$result = OverviewRepository::held_product_review_count();
		$this->assertTrue( $result['ok'] );
		$this->assertGreaterThanOrEqual( 2, $result['count'] );
		$this->assertArrayNotHasKey( 'comment_ids', $result );
	}

	public function test_plugin_link_points_at_pending(): void {
		$out = PluginActionLinks::filter_action_links( array() );
		$this->assertArrayHasKey( 'upr_product_reviews', $out );
		$this->assertStringContainsString( 'upr_view=' . CommentListEnhancements::VIEW_PENDING, $out['upr_product_reviews'] );
		$this->assertStringNotContainsString( 'Held', $out['upr_product_reviews'] );
	}

	public function test_row_actions_relabel_on_pending_held(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$_GET['upr_view']   = CommentListEnhancements::VIEW_PENDING;
		$GLOBALS['pagenow'] = 'edit-comments.php';
		set_current_screen( 'edit-comments' );
		CommentListEnhancements::on_current_screen( get_current_screen() );

		$actions = array(
			'approve' => '<a href="https://example.test/approve">Approve</a>',
			'spam'    => '<a href="https://example.test/spam">Spam</a>',
			'trash'   => '<a href="https://example.test/trash">Trash</a>',
		);
		$out = CommentListEnhancements::row_actions( $actions, get_comment( $comment_id ) );
		$this->assertStringContainsString( 'Publish', $out['approve'] );
		$this->assertStringContainsString( 'Mark as spam', $out['spam'] );
		$this->assertStringContainsString( 'Move to trash', $out['trash'] );
		$this->assertArrayHasKey( 'upr_keep_hold', $out );
		$this->assertStringContainsString( 'Keep on hold', $out['upr_keep_hold'] );
		$this->assertStringNotContainsString( '<form', $out['upr_keep_hold'] );
		$this->assertStringContainsString( 'type="submit"', $out['upr_keep_hold'] );
		$this->assertStringContainsString( 'form="' . OperatorQueueKeepHold::form_dom_id( $comment_id ) . '"', $out['upr_keep_hold'] );
		$this->assertStringNotContainsString( 'Deny', $out['approve'] . $out['spam'] . $out['trash'] . $out['upr_keep_hold'] );
	}

	public function test_keep_hold_native_row_actions_lifecycle(): void {
		$product_id = $this->upr_create_product();
		$comment_id = $this->insert_held_review( $product_id );
		$_GET['upr_view']   = CommentListEnhancements::VIEW_PENDING;
		$GLOBALS['pagenow'] = 'edit-comments.php';
		set_current_screen( 'edit-comments' );
		CommentListEnhancements::on_current_screen( get_current_screen() );

		$filtered = CommentListEnhancements::row_actions(
			array(
				'approve' => '<a href="https://example.test/approve">Approve</a>',
			),
			get_comment( $comment_id )
		);
		$this->assertArrayHasKey( 'upr_keep_hold', $filtered );

		// Core wraps each action in <span class="{action}">…</span>.
		$wrapped = OperatorQueueKeepHold::wrap_row_actions_like_core( $filtered );
		$this->assertStringContainsString( '<span class="upr_keep_hold">', $wrapped );
		$this->assertDoesNotMatchRegularExpression( '/<span[^>]*>\s*<form\b/i', $wrapped );
		$this->assertMatchesRegularExpression(
			'/<span class="upr_keep_hold">\s*<button type="submit" form="' . preg_quote( OperatorQueueKeepHold::form_dom_id( $comment_id ), '/' ) . '"/i',
			$wrapped
		);
		$this->assertStringContainsString( '>Keep on hold<', $wrapped );
		$this->assertStringContainsString( 'screen-reader-text', $wrapped );

		// Also exercise WP_Comments_List_Table::row_actions when available.
		if ( ! class_exists( 'WP_Comments_List_Table', false ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-comments-list-table.php';
		}
		$table = new \WP_Comments_List_Table( array( 'screen' => get_current_screen() ) );
		$ref   = new \ReflectionMethod( $table, 'row_actions' );
		$ref->setAccessible( true );
		$core_html = (string) $ref->invoke( $table, $filtered, true );
		$this->assertDoesNotMatchRegularExpression( '/<span[^>]*>\s*<form\b/i', $core_html );
		$this->assertStringContainsString( 'form="' . OperatorQueueKeepHold::form_dom_id( $comment_id ) . '"', $core_html );

		ob_start();
		OperatorQueueKeepHold::render_pending_forms();
		$form_html = (string) ob_get_clean();
		$this->assertStringContainsString( 'method="post"', $form_html );
		$this->assertStringNotContainsString( 'method="get"', $form_html );
		$this->assertStringContainsString( 'id="' . OperatorQueueKeepHold::form_dom_id( $comment_id ) . '"', $form_html );
		$this->assertStringContainsString( 'name="comment_id" value="' . $comment_id . '"', $form_html );
		$this->assertStringContainsString( 'name="action" value="' . OperatorQueueKeepHold::ACTION . '"', $form_html );
		$this->assertMatchesRegularExpression(
			'/<input[^>]+name="_wpnonce"[^>]+value="([a-f0-9]+)"/i',
			$form_html,
			$form_html
		);
		preg_match( '/name="_wpnonce"[^>]+value="([a-f0-9]+)"/i', $form_html, $nonce_m );
		$this->assertNotEmpty( $nonce_m[1] ?? '' );
		$this->assertSame( 1, wp_verify_nonce( $nonce_m[1], 'upr_queue_keep_hold_' . $comment_id ) );

		$before_status = (string) get_comment( $comment_id )->comment_approved;
		$before_audit  = $this->count_events( 'review.operator_deferred' );
		OperatorQueueKeepHold::emit_deferred_audit( get_comment( $comment_id ) );
		$this->assertSame( $before_status, (string) get_comment( $comment_id )->comment_approved );
		$this->assertSame( $before_audit + 1, $this->count_events( 'review.operator_deferred' ) );
	}

	public function test_support_export_unchanged_schema(): void {
		// SupportExport class may live under Admin.
		$class = class_exists( \UniversalProductReviews\Admin\SupportExport::class )
			? \UniversalProductReviews\Admin\SupportExport::class
			: null;
		$this->assertNotNull( $class );
		$built = $class::build();
		$this->assertSame( 'upr-support-export/v1', $built['schema_version'] );
		$this->assertArrayNotHasKey( 'assessments', $built );
		$json = wp_json_encode( $built );
		$this->assertIsString( $json );
		$this->assertStringNotContainsString( 'operator_deferred', $json );
		$this->assertStringNotContainsString( 'assessment_id', $json );
	}

	private function insert_held_review( int $product_id ): int {
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Synthetic Reviewer',
				'comment_author_email' => 'synthetic@example.test',
				'comment_content'      => 'Synthetic held review for M15 tests only.',
				'comment_type'         => 'review',
				'comment_approved'     => '0',
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $comment_id );
		return $comment_id;
	}

	private function insert_completed_assessment( int $comment_id ): int {
		global $wpdb;
		$completed = gmdate( 'Y-m-d H:i:s' );
		$ok        = $wpdb->insert(
			AssessmentRepository::table(),
			array(
				'schema_version'           => PolicyAllowlist::SCHEMA_VERSION,
				'comment_id'               => $comment_id,
				'mode'                     => 'shadow',
				'state'                    => 'completed',
				'publication_safety_score' => 90,
				'confidence'               => 'high',
				'reason_codes'             => wp_json_encode( array( 'spam_pattern' ) ),
				'policy_version'           => PolicyAllowlist::POLICY_VERSION,
				'provider_kind'            => 'local',
				'provider_fingerprint'     => ProviderFingerprint::for_builtin( PolicyAllowlist::POLICY_VERSION ),
				'failure_code'             => null,
				'requested_at'             => $completed,
				'completed_at'             => $completed,
				'retention_due_at'         => gmdate( 'Y-m-d H:i:s', time() + ( 180 * DAY_IN_SECONDS ) ),
			),
			array( '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$this->assertNotFalse( $ok );
		return (int) $wpdb->insert_id;
	}

	private function count_events( string $event_type ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'upr_audit';
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_type = %s", $event_type )
		);
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function latest_event( string $event_type ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'upr_audit';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_type = %s ORDER BY id DESC LIMIT 1",
				$event_type
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}
}
