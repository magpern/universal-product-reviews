<?php
/**
 * M5 integration: Comments list, audit, staff replies.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Audit\AuditLogger;
use UniversalProductReviews\Invitations\CompletionService;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Moderation\CommentListEnhancements;
use UniversalProductReviews\Moderation\CommentListPrefetch;
use UniversalProductReviews\Moderation\ModerationAudit;
use UniversalProductReviews\Moderation\ReviewContext;
use UniversalProductReviews\Moderation\ReviewModeration;
use UniversalProductReviews\Moderation\StaffReplyPolicy;
use UniversalProductReviews\Moderation\SystemStatusOrigin;
use WP_UnitTestCase;

final class M5ModerationIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	/** @var int */
	private $admin_id = 0;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		ModerationAudit::reset_for_tests();
		CommentListPrefetch::reset_for_tests();
		CommentListEnhancements::reset_for_tests();
		SystemStatusOrigin::reset_for_tests();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->grant_moderation_caps( $this->admin_id );
	}

	public function tear_down(): void {
		ModerationAudit::reset_for_tests();
		CommentListPrefetch::reset_for_tests();
		CommentListEnhancements::reset_for_tests();
		SystemStatusOrigin::reset_for_tests();
		remove_all_filters( 'wp_doing_ajax' );
		unset( $_REQUEST, $_GET, $GLOBALS['pagenow'] );
		parent::tear_down();
	}

	public function test_source_labels_meta_invite_and_dual(): void {
		$product_id = $this->upr_create_product();
		$pack       = $this->upr_create_order_with_item( $product_id );
		$item_id    = $pack['order_item_id'];
		$order_id   = (int) $pack['order']->get_id();

		$meta_only = $this->insert_product_review( $product_id, 'Meta linked' );
		update_comment_meta( $meta_only, '_upr_order_item_id', $item_id );

		$invite_only = $this->insert_product_review( $product_id, 'Invite linked' );
		InviteRepository::upsert(
			$item_id + 100000,
			array(
				'order_id'          => $order_id,
				'product_id'        => $product_id,
				'schedule_state'    => ScheduleStates::COMPLETED,
				'review_comment_id' => $invite_only,
			)
		);

		$dual = $this->insert_product_review( $product_id, 'Dual linked' );
		update_comment_meta( $dual, '_upr_order_item_id', $item_id );
		InviteRepository::upsert(
			$item_id,
			array(
				'order_id'          => $order_id,
				'product_id'        => $product_id,
				'schedule_state'    => ScheduleStates::COMPLETED,
				'review_comment_id' => $dual,
			)
		);

		$unlinked = $this->insert_product_review( $product_id, 'Unlinked' );

		$this->assertSame( ReviewContext::SOURCE_INVITATION, ReviewContext::source_key( get_comment( $meta_only ), null, $item_id ) );
		$rows = InviteRepository::find_by_review_comment_ids( array( $invite_only ) );
		$this->assertSame( ReviewContext::SOURCE_INVITATION, ReviewContext::source_key( get_comment( $invite_only ), $rows[ $invite_only ], 0 ) );
		$this->assertSame( ReviewContext::SOURCE_INVITATION, ReviewContext::source_key( get_comment( $dual ), InviteRepository::find( $item_id ), $item_id ) );
		$this->assertSame( ReviewContext::SOURCE_UNLINKED, ReviewContext::source_key( get_comment( $unlinked ), null, 0 ) );
	}

	public function test_invitation_linked_filter_no_duplicates_and_pagination(): void {
		$product_id = $this->upr_create_product();
		$pack       = $this->upr_create_order_with_item( $product_id );
		$item_id    = $pack['order_item_id'];
		$order_id   = (int) $pack['order']->get_id();

		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$cid = $this->insert_product_review( $product_id, 'Linked review ' . $i . ' unique-token-abc' );
			update_comment_meta( $cid, '_upr_order_item_id', $item_id + $i + 1 );
			InviteRepository::upsert(
				$item_id + $i + 1,
				array(
					'order_id'          => $order_id,
					'product_id'        => $product_id,
					'schedule_state'    => ScheduleStates::COMPLETED,
					'review_comment_id' => $cid,
				)
			);
			$ids[] = $cid;
		}

		$dual = $ids[0];

		add_filter(
			'comments_clauses',
			array( CommentListEnhancements::class, 'invitation_linked_clauses' ),
			10,
			2
		);

		$page1 = get_comments(
			array(
				'type'                  => 'review',
				'post_type'             => 'product',
				'post_id'               => $product_id,
				'number'                => 2,
				'offset'                => 0,
				'orderby'               => 'comment_ID',
				'order'                 => 'ASC',
				'upr_invitation_linked' => true,
			)
		);
		$page2 = get_comments(
			array(
				'type'                  => 'review',
				'post_type'             => 'product',
				'post_id'               => $product_id,
				'number'                => 2,
				'offset'                => 2,
				'orderby'               => 'comment_ID',
				'order'                 => 'ASC',
				'upr_invitation_linked' => true,
			)
		);
		$total = (int) get_comments(
			array(
				'type'                  => 'review',
				'post_type'             => 'product',
				'post_id'               => $product_id,
				'count'                 => true,
				'upr_invitation_linked' => true,
			)
		);

		$page1_ids = array_map( static fn( $c ) => (int) $c->comment_ID, $page1 );
		$page2_ids = array_map( static fn( $c ) => (int) $c->comment_ID, $page2 );
		$this->assertCount( 2, $page1_ids );
		$this->assertCount( 2, $page2_ids );
		$this->assertSame( array_values( array_unique( $page1_ids ) ), $page1_ids, 'no duplicates on page 1' );
		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ), 'pages disjoint' );
		$this->assertSame( 5, $total );
		$this->assertContains( $dual, array_merge( $page1_ids, $page2_ids ) );

		$search = get_comments(
			array(
				'type'                  => 'review',
				'post_type'             => 'product',
				'post_id'               => $product_id,
				'search'                => 'unique-token-abc',
				'upr_invitation_linked' => true,
			)
		);
		$this->assertNotEmpty( $search );
		foreach ( $search as $c ) {
			$this->assertStringContainsString( 'unique-token-abc', $c->comment_content );
		}

		remove_filter( 'comments_clauses', array( CommentListEnhancements::class, 'invitation_linked_clauses' ), 10 );
	}

	public function test_prefetch_bounded_query_count(): void {
		$product_id = $this->upr_create_product();
		$pack       = $this->upr_create_order_with_item( $product_id );
		$ids        = array();
		$objects    = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$cid = $this->insert_product_review( $product_id, 'Prefetch ' . $i );
			update_comment_meta( $cid, '_upr_order_item_id', $pack['order_item_id'] );
			update_comment_meta( $cid, 'rating', 4 );
			$ids[]     = $cid;
			$objects[] = get_comment( $cid );
		}
		InviteRepository::upsert(
			$pack['order_item_id'],
			array(
				'order_id'          => (int) $pack['order']->get_id(),
				'product_id'        => $product_id,
				'schedule_state'    => ScheduleStates::COMPLETED,
				'review_comment_id' => $ids[0],
			)
		);

		CommentListPrefetch::hydrate_from_comments( $objects );
		$this->assertLessThanOrEqual( 5, CommentListPrefetch::query_count() );
		$ctx = CommentListPrefetch::get( $ids[0] );
		$this->assertNotNull( $ctx );
		$this->assertSame( ReviewContext::SOURCE_INVITATION, $ctx['source'] );
		$this->assertSame( 4, $ctx['rating'] );
		$this->assertNotEmpty( get_the_title( $product_id ) );
	}

	public function test_the_comments_prefetch_lifecycle_no_recursion(): void {
		wp_set_current_user( $this->admin_id );
		$this->as_comments_screen();
		$_GET['upr_view'] = CommentListEnhancements::VIEW_PRODUCT_REVIEWS;
		CommentListEnhancements::on_current_screen( get_current_screen() );

		$product_id = $this->upr_create_product();
		$pack       = $this->upr_create_order_with_item( $product_id );
		$ids        = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$cid = $this->insert_product_review( $product_id, 'Lifecycle ' . $i );
			update_comment_meta( $cid, '_upr_order_item_id', $pack['order_item_id'] );
			update_comment_meta( $cid, 'rating', 5 );
			$ids[] = $cid;
		}
		InviteRepository::upsert(
			$pack['order_item_id'],
			array(
				'order_id'          => (int) $pack['order']->get_id(),
				'product_id'        => $product_id,
				'schedule_state'    => ScheduleStates::COMPLETED,
				'review_comment_id' => $ids[0],
			)
		);

		$the_comments_calls   = 0;
		$comment_in_queries   = 0;
		$prefetch_hook_calls  = 0;

		add_filter(
			'the_comments',
			static function ( $comments ) use ( &$the_comments_calls ) {
				++$the_comments_calls;
				if ( $the_comments_calls > 3 ) {
					throw new \RuntimeException( 'the_comments recursion detected' );
				}
				return $comments;
			},
			1,
			1
		);
		add_action(
			'pre_get_comments',
			static function ( $query ) use ( &$comment_in_queries ) {
				if ( ! empty( $query->query_vars['comment__in'] ) ) {
					++$comment_in_queries;
				}
			},
			1,
			1
		);
		add_filter(
			'the_comments',
			static function ( $comments, $query ) use ( &$prefetch_hook_calls ) {
				if ( CommentListEnhancements::is_comments_list_query( $query ) ) {
					++$prefetch_hook_calls;
				}
				return $comments;
			},
			5,
			2
		);

		CommentListPrefetch::reset_for_tests();

		$primary = get_comments(
			array(
				'type'      => 'review',
				'post_type' => 'product',
				'post_id'   => $product_id,
				'number'    => 10,
				'status'    => 'all',
				'orderby'   => 'comment_ID',
				'order'     => 'ASC',
			)
		);

		$this->assertSame( 1, $the_comments_calls, 'primary list fires the_comments once' );
		$this->assertSame( 1, $prefetch_hook_calls, 'prefetch targets primary list once' );
		$this->assertSame( 0, $comment_in_queries, 'no nested page-comment WP_Comment_Query' );
		$this->assertCount( 3, $primary );
		$this->assertLessThanOrEqual( 5, CommentListPrefetch::query_count() );

		foreach ( $ids as $cid ) {
			$ctx = CommentListPrefetch::get( $cid );
			$this->assertNotNull( $ctx, 'displayed comment hydrated' );
			$this->assertSame( ReviewContext::SOURCE_INVITATION, $ctx['source'] );
			$this->assertSame( 5, $ctx['rating'] );
		}

		// Secondary lookup on the same screen must not constrain or prefetch.
		CommentListPrefetch::reset_for_tests();
		$the_comments_calls  = 0;
		$prefetch_hook_calls = 0;

		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$blog_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Reader',
				'comment_author_email' => 'reader@example.com',
				'comment_content'      => 'Blog note',
				'comment_type'         => 'comment',
				'comment_approved'     => 1,
			)
		);

		$secondary = get_comments(
			array(
				'comment__in' => array( $blog_id ),
				'number'      => 1,
				'status'      => 'all',
			)
		);

		$this->assertCount( 1, $secondary );
		$this->assertSame( $blog_id, (int) $secondary[0]->comment_ID );
		$secondary_query               = new \WP_Comment_Query();
		$secondary_query->query_vars['comment__in'] = array( $blog_id );
		$secondary_query->query_vars['number']      = 1;
		$secondary_query->query_vars['status']      = 'all';
		$this->assertFalse( CommentListEnhancements::is_comments_list_query( $secondary_query ) );
		$this->assertNull( CommentListPrefetch::get( $blog_id ) );
		$this->assertSame( 0, CommentListPrefetch::query_count() );

		// Even with upr_view set, secondary comment__in query must not be forced to product reviews.
		$forced = get_comments(
			array(
				'comment__in' => array( $blog_id ),
				'number'      => 1,
				'status'      => 'all',
			)
		);
		$this->assertCount( 1, $forced );
		$this->assertSame( 'comment', $forced[0]->comment_type );
	}

	public function test_order_link_requires_object_capability(): void {
		$product_id = $this->upr_create_product();
		$pack       = $this->upr_create_order_with_item( $product_id );
		$cid        = $this->insert_product_review( $product_id, 'Cap check' );
		update_comment_meta( $cid, '_upr_order_item_id', $pack['order_item_id'] );
		InviteRepository::upsert(
			$pack['order_item_id'],
			array(
				'order_id'          => (int) $pack['order']->get_id(),
				'product_id'        => $product_id,
				'schedule_state'    => ScheduleStates::COMPLETED,
				'review_comment_id' => $cid,
			)
		);

		CommentListPrefetch::hydrate( array( $cid ) );
		$ctx = CommentListPrefetch::context_for( $cid );
		$this->assertGreaterThan( 0, (int) $ctx['order_id'] );

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->assertFalse( current_user_can( 'edit_post', (int) $ctx['order_id'] ) );

		wp_set_current_user( $this->admin_id );
		$this->assertTrue( current_user_can( 'edit_post', (int) $ctx['product_id'] ) );
	}

	public function test_operator_moderation_audit_and_sequence(): void {
		wp_set_current_user( $this->admin_id );
		$this->as_comments_screen();
		$this->assertTrue( is_admin() );
		$this->assertTrue( current_user_can( 'moderate_comments' ) );

		$product_id = $this->upr_create_product();
		$cid        = $this->insert_product_review( $product_id, 'Audit me', '0' );

		wp_set_comment_status( $cid, 'approve' );
		wp_set_comment_status( $cid, 'hold' );
		wp_set_comment_status( $cid, 'approve' );

		$events = $this->audit_events_for_comment( $cid );
		$types  = array_column( $events, 'event_type' );
		$this->assertSame(
			array(
				ModerationAudit::EVENT_STATUS_CHANGED,
				ModerationAudit::EVENT_STATUS_CHANGED,
				ModerationAudit::EVENT_STATUS_CHANGED,
			),
			$types
		);
		foreach ( $events as $row ) {
			$payload = json_decode( (string) $row['payload_json'], true );
			$this->assertIsArray( $payload );
			$this->assertArrayHasKey( 'old_status', $payload );
			$this->assertArrayHasKey( 'new_status', $payload );
			$this->assertSame( 'operator', $payload['origin'] );
			$this->assertArrayNotHasKey( 'email', $payload );
			$this->assertArrayNotHasKey( 'comment_content', $payload );
			$this->assertArrayNotHasKey( 'token', $payload );
			$this->assertArrayNotHasKey( 'url', $payload );
		}
	}

	public function test_reject_comment_is_system_spam(): void {
		wp_set_current_user( 0 );
		$product_id = $this->upr_create_product();
		$cid        = $this->insert_product_review( $product_id, 'Reject me', '0' );
		CompletionService::reject_comment( $cid );
		$events = $this->audit_events_for_comment( $cid );
		$this->assertNotEmpty( $events );
		$last = end( $events );
		$this->assertSame( ModerationAudit::EVENT_SYSTEM_SPAM, $last['event_type'] );
		$payload = json_decode( (string) $last['payload_json'], true );
		$this->assertSame( 'upr_system', $payload['origin'] );
		$this->assertSame( 'spam', $payload['new_status'] );
	}

	public function test_external_transition_is_system_status_changed(): void {
		wp_set_current_user( 0 );
		$product_id = $this->upr_create_product();
		$cid        = $this->insert_product_review( $product_id, 'External', '0' );
		wp_set_comment_status( $cid, 'approve' );
		$events = $this->audit_events_for_comment( $cid );
		$this->assertNotEmpty( $events );
		$last = end( $events );
		$this->assertSame( ModerationAudit::EVENT_SYSTEM_STATUS_CHANGED, $last['event_type'] );
		$payload = json_decode( (string) $last['payload_json'], true );
		$this->assertSame( 'external_system', $payload['origin'] );
	}

	public function test_blog_comment_creates_no_upr_audit(): void {
		wp_set_current_user( $this->admin_id );
		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$cid     = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_author'       => 'Reader',
				'comment_author_email' => 'r@example.com',
				'comment_content'      => 'Blog',
				'comment_type'         => 'comment',
				'comment_approved'     => 0,
			)
		);
		wp_set_comment_status( $cid, 'approve' );
		$this->assertSame( array(), $this->audit_events_for_comment( (int) $cid ) );
	}

	public function test_native_admin_reply_shape_and_exemption(): void {
		wp_set_current_user( $this->admin_id );
		$product_id = $this->upr_create_product();
		$parent_id  = $this->insert_product_review( $product_id, 'Parent review', '1' );

		$this->prime_native_reply_request();

		$reply_data = array(
			'comment_post_ID'      => $product_id,
			'comment_author'       => 'Admin',
			'comment_author_email' => 'admin@example.com',
			'comment_author_url'   => '',
			'comment_content'      => 'Staff reply',
			'comment_type'         => 'review',
			'comment_parent'       => $parent_id,
			'user_id'              => $this->admin_id,
		);

		// Proof: depth-one parent is top-level product review.
		$parent = get_comment( $parent_id );
		$this->assertSame( 'review', $parent->comment_type );
		$this->assertSame( 0, (int) $parent->comment_parent );
		$this->assertSame( $product_id, (int) $parent->comment_post_ID );

		$this->assertTrue( StaffReplyPolicy::is_validated_staff_reply( $reply_data ) );
		$approved = ReviewModeration::hold_new_product_reviews( 1, $reply_data );
		$this->assertSame( 1, $approved, 'exemption passes core approve through' );

		$reply_id = wp_new_comment( $reply_data, true );
		$this->assertIsInt( $reply_id );
		$reply = get_comment( $reply_id );
		$this->assertSame( 'review', $reply->comment_type );
		$this->assertSame( $parent_id, (int) $reply->comment_parent );
		$this->assertSame( $product_id, (int) $reply->comment_post_ID );

		$reply_events = $this->audit_events_of_type( ModerationAudit::EVENT_REPLY_POSTED );
		$this->assertNotEmpty( $reply_events );
	}

	public function test_invalid_and_missing_nonce_no_exemption(): void {
		wp_set_current_user( $this->admin_id );
		$product_id = $this->upr_create_product();
		$parent_id  = $this->insert_product_review( $product_id, 'Parent', '1' );

		$reply_data = array(
			'comment_post_ID' => $product_id,
			'comment_type'    => 'review',
			'comment_parent'  => $parent_id,
		);

		$_REQUEST = array(
			'action'      => 'replyto-comment',
			'_ajax_nonce' => 'invalid',
		);
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
		$this->assertFalse( StaffReplyPolicy::is_validated_staff_reply( $reply_data ) );
		$this->assertSame( 0, ReviewModeration::hold_new_product_reviews( 1, $reply_data ) );

		$_REQUEST = array( 'action' => 'replyto-comment' );
		$this->assertFalse( StaffReplyPolicy::verify_reply_nonce() );
		$this->assertSame( 0, ReviewModeration::hold_new_product_reviews( 1, $reply_data ) );
	}

	public function test_crafted_admin_ajax_and_frontend_no_exemption(): void {
		wp_set_current_user( $this->admin_id );
		$product_id = $this->upr_create_product();
		$parent_id  = $this->insert_product_review( $product_id, 'Parent', '1' );
		$reply_data = array(
			'comment_post_ID' => $product_id,
			'comment_type'    => 'review',
			'comment_parent'  => $parent_id,
		);

		$_REQUEST = array(
			'action'      => 'heartbeat',
			'_ajax_nonce' => wp_create_nonce( 'replyto-comment' ),
		);
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
		$this->assertFalse( StaffReplyPolicy::is_validated_staff_reply( $reply_data ) );

		// Frontend-style post with comment_parent.
		$_REQUEST = array();
		$this->assertFalse( StaffReplyPolicy::is_exact_native_reply_request() );
		$this->assertSame( 0, ReviewModeration::hold_new_product_reviews( 1, $reply_data ) );
	}

	public function test_customer_parent_forgery_and_nested_reply_held(): void {
		wp_set_current_user( $this->admin_id );
		$product_id = $this->upr_create_product();
		$parent_id  = $this->insert_product_review( $product_id, 'Parent', '1' );
		$this->prime_native_reply_request();

		// Nested: parent is itself a reply.
		$mid = wp_insert_comment(
			array(
				'comment_post_ID'  => $product_id,
				'comment_content'  => 'Mid',
				'comment_type'     => 'review',
				'comment_parent'   => $parent_id,
				'comment_approved' => 1,
				'user_id'          => $this->admin_id,
			)
		);
		$nested = array(
			'comment_post_ID' => $product_id,
			'comment_type'    => 'review',
			'comment_parent'  => $mid,
		);
		$this->assertFalse( StaffReplyPolicy::is_validated_staff_reply( $nested ) );
		$this->assertSame( 0, ReviewModeration::hold_new_product_reviews( 1, $nested ) );

		// Top-level still held.
		$this->assertSame(
			0,
			ReviewModeration::hold_new_product_reviews(
				1,
				array(
					'comment_post_ID' => $product_id,
					'comment_type'    => 'review',
					'comment_parent'  => 0,
				)
			)
		);
	}

	public function test_core_held_staff_reply_stays_held(): void {
		wp_set_current_user( $this->admin_id );
		$product_id = $this->upr_create_product();
		$parent_id  = $this->insert_product_review( $product_id, 'Parent', '1' );
		$this->prime_native_reply_request();
		$reply_data = array(
			'comment_post_ID' => $product_id,
			'comment_type'    => 'review',
			'comment_parent'  => $parent_id,
		);
		$this->assertTrue( StaffReplyPolicy::is_validated_staff_reply( $reply_data ) );
		$this->assertSame( 0, ReviewModeration::hold_new_product_reviews( 0, $reply_data ) );
	}

	public function test_moderator_spam_does_not_rewrite_invite(): void {
		wp_set_current_user( $this->admin_id );
		$product_id = $this->upr_create_product();
		$pack       = $this->upr_create_order_with_item( $product_id );
		$cid        = $this->insert_product_review( $product_id, 'Spam invite', '0' );
		update_comment_meta( $cid, '_upr_order_item_id', $pack['order_item_id'] );
		InviteRepository::upsert(
			$pack['order_item_id'],
			array(
				'order_id'          => (int) $pack['order']->get_id(),
				'product_id'        => $product_id,
				'schedule_state'    => ScheduleStates::COMPLETED,
				'review_comment_id' => $cid,
			)
		);
		wp_set_comment_status( $cid, 'spam' );
		$row = InviteRepository::find( $pack['order_item_id'] );
		$this->assertSame( ScheduleStates::COMPLETED, $row['schedule_state'] );
		$this->assertSame( $cid, (int) $row['review_comment_id'] );
	}

	private function insert_product_review( int $product_id, string $content, string $approved = '0' ): int {
		$id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Reviewer',
				'comment_author_email' => 'reviewer@example.com',
				'comment_content'      => $content,
				'comment_type'         => 'review',
				'comment_approved'     => $approved,
				'comment_parent'       => 0,
			)
		);
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	private function as_comments_screen(): void {
		$GLOBALS['pagenow'] = 'edit-comments.php';
		set_current_screen( 'edit-comments' );
	}

	private function grant_moderation_caps( int $user_id ): void {
		$user = new \WP_User( $user_id );
		foreach (
			array(
				'moderate_comments',
				'edit_posts',
				'edit_products',
				'edit_others_products',
				'edit_published_products',
				'edit_shop_orders',
				'edit_others_shop_orders',
			) as $cap
		) {
			$user->add_cap( $cap );
		}
	}

	private function prime_native_reply_request(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}
		$_REQUEST = array(
			'action'      => 'replyto-comment',
			'_ajax_nonce' => wp_create_nonce( 'replyto-comment' ),
		);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function audit_events_for_comment( int $comment_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'upr_audit';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE event_type LIKE %s AND payload_json LIKE %s ORDER BY id ASC",
				'review.%',
				'%"comment_id":' . $comment_id . '%'
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function audit_events_of_type( string $type ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'upr_audit';
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE event_type = %s ORDER BY id ASC", $type ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
