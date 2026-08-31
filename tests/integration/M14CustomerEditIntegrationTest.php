<?php
/**
 * M14 customer seven-day review edits — integration tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Admin\SupportExport;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\CustomerEdit\CompletedInviteLookup;
use UniversalProductReviews\CustomerEdit\CustomerEditAuthorization;
use UniversalProductReviews\CustomerEdit\CustomerEditAvailability;
use UniversalProductReviews\CustomerEdit\CustomerEditGuard;
use UniversalProductReviews\CustomerEdit\EditClaimReconciler;
use UniversalProductReviews\CustomerEdit\EditClaimRepository;
use UniversalProductReviews\CustomerEdit\EditFinaliser;
use UniversalProductReviews\CustomerEdit\EditSessionService;
use UniversalProductReviews\CustomerEdit\EditWriteService;
use UniversalProductReviews\CustomerEdit\InviteTokenDispatcher;
use UniversalProductReviews\Database\Schema;
use UniversalProductReviews\Http\ReviewEditHandler;
use UniversalProductReviews\Http\ReviewSubmitHandler;
use UniversalProductReviews\Http\TokenExchangeEndpoint;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Moderation\ApproveToHoldCas;
use UniversalProductReviews\Plugin;
use UniversalProductReviews\Submission\GuestSubmitAuthorization;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;
use UniversalProductReviews\Tokens\SessionCookie;
use UniversalProductReviews\Tokens\TokenRepository;
use UniversalProductReviews\Tokens\TokenService;
use WP_UnitTestCase;

final class M14CustomerEditIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		wp_set_current_user( 0 );
		GuestSubmitAuthorization::clear();
		CustomerEditAuthorization::clear();
		EditSessionService::$after_parent_lock_for_tests = null;
		EditFinaliser::$crash_after_step_for_tests       = null;
		EditWriteService::$crash_after_for_tests         = null;
		delete_option( Options::LOCAL_AI_SHADOW_ENABLED );
		Plugin::reset_for_tests();
		Plugin::init();
		add_filter( 'comment_flood_filter', '__return_false' );
		add_filter( 'wp_is_comment_flood', '__return_false' );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$_GET                      = array();
	}

	public function tear_down(): void {
		GuestSubmitAuthorization::clear();
		CustomerEditAuthorization::clear();
		EditSessionService::$after_parent_lock_for_tests = null;
		EditFinaliser::$crash_after_step_for_tests       = null;
		EditWriteService::$crash_after_for_tests         = null;
		SessionCookie::clear();
		$_POST = array();
		$_GET  = array();
		parent::tear_down();
	}

	public function test_schema_edit_claims_and_unique_keys(): void {
		global $wpdb;
		$claims = $wpdb->prefix . 'upr_review_edit_claims';
		$this->assertSame( $claims, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $claims ) ) );

		$claim_pk = $wpdb->get_results( "SHOW INDEX FROM {$claims} WHERE Key_name = 'PRIMARY'", ARRAY_A );
		$this->assertSame( 'comment_id', (string) ( $claim_pk[0]['Column_name'] ?? '' ) );

		$audit_idx = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}upr_audit WHERE Key_name = 'event_correlation'", ARRAY_A );
		$cols      = array_column( $audit_idx, 'Column_name' );
		$this->assertContains( 'event_type', $cols );
		$this->assertContains( 'correlation_id', $cols );

		$src_idx = $wpdb->get_results( "SHOW INDEX FROM {$wpdb->prefix}upr_moderation_assessments WHERE Key_name = 'source_op_id'", ARRAY_A );
		$this->assertNotEmpty( $src_idx );
		$this->assertSame( 'source_op_id', (string) ( $src_idx[0]['Column_name'] ?? '' ) );
		$this->assertSame( '0', (string) ( $src_idx[0]['Non_unique'] ?? '1' ) );

		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$claims}", 0 );
		$this->assertContains( 'content_changed', $cols );
		$this->assertContains( 'rating_changed', $cols );
		$this->assertContains( 'prior_content_hmac', $cols );
		$this->assertContains( 'prior_rating', $cols );
	}

	public function test_e20_acquire_rejects_recovery_owned_content_written_after_ttl(): void {
		$comment_id = $this->insert_review( $this->upr_create_product(), '0' );
		$hmac       = EditClaimRepository::hmac_body( 'target' );
		$first      = EditClaimRepository::acquire( $comment_id, 'guest_session', $hmac, 5, 'hold' );
		$this->assertNotNull( $first );
		$this->assertTrue( EditClaimRepository::mark_writing( $comment_id, $first['claim_token'], $first['generation'] ) );
		$this->assertTrue(
			EditClaimRepository::mark_content_written( $comment_id, $first['claim_token'], $first['generation'], wp_generate_uuid4() )
		);
		global $wpdb;
		$wpdb->update(
			EditClaimRepository::table(),
			array( 'claim_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'comment_id' => $comment_id ),
			array( '%s' ),
			array( '%d' )
		);
		$this->assertNull( EditClaimRepository::acquire( $comment_id, 'guest_session', $hmac, 4, 'hold' ) );
	}

	public function test_e20_acquire_rejects_recovery_owned_writing_after_ttl(): void {
		$comment_id = $this->insert_review( $this->upr_create_product(), '1' );
		$hmac       = EditClaimRepository::hmac_body( 'target' );
		$first      = EditClaimRepository::acquire( $comment_id, 'guest_session', $hmac, 5, 'approve' );
		$this->assertNotNull( $first );
		$this->assertTrue( EditClaimRepository::mark_writing( $comment_id, $first['claim_token'], $first['generation'] ) );
		global $wpdb;
		$wpdb->update(
			EditClaimRepository::table(),
			array( 'claim_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'comment_id' => $comment_id ),
			array( '%s' ),
			array( '%d' )
		);
		$this->assertNull( EditClaimRepository::acquire( $comment_id, 'guest_session', $hmac, 4, 'hold' ) );
		$stats = EditClaimReconciler::run();
		$this->assertSame( 0, $stats['released'] );
		$row = EditClaimRepository::get( $comment_id );
		$this->assertNotSame( 'claimed', (string) ( $row['phase'] ?? '' ) );
		$this->assertSame( 'abandoned', (string) ( $row['finalise_outcome'] ?? '' ) );
		$this->assertSame( '1', (string) get_comment( $comment_id )->comment_approved );
	}

	public function test_completed_invite_secret_edits_only_and_cannot_resubmit(): void {
		$completed = $this->complete_guest_review();
		$raw       = $completed['raw'];

		$this->assertNull( TokenRepository::find_active_by_raw( $raw, 'invite' ) );
		$this->assertNull( TokenService::exchange_invite( $raw ) );
		$this->assertFalse( GuestSubmitAuthorization::is_armed() );
		$this->assertNull( FormSessionAuthenticator::current_session() );

		$outcome = InviteTokenDispatcher::dispatch( $raw );
		$this->assertSame( 'edit', $outcome['kind'] );
		$this->assertStringContainsString( 'upr-review/edit', $outcome['url'] );
		$this->assertNull( TokenRepository::find_active_by_raw( $raw, 'invite' ) );
		$this->assertFalse( GuestSubmitAuthorization::is_armed() );

		$session = TokenRepository::find_active_by_raw( SessionCookie::get() ?? '', 'edit_session' );
		$this->assertIsArray( $session );
		$this->assertSame( 'edit_session', $session['purpose'] );
		$this->assertSame( $completed['invite_token_id'], (int) $session['parent_token_id'] );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set( 'upr_review_form', '1' );
		}
		set_query_var( 'upr_review_form', '1' );
		$_POST['upr_session_id'] = (string) ( $session['id'] ?? 0 );
		$_POST['upr_nonce']      = wp_create_nonce( 'upr_review_submit_' . (int) ( $session['id'] ?? 0 ) );
		$_POST['upr_rating']     = '3';
		$_POST['upr_content']    = 'Second review must not insert';
		ob_start();
		ReviewSubmitHandler::handle();
		$body = (string) ob_get_clean();
		$this->assertStringContainsString( 'expired', strtolower( $body ) );

		$invite = InviteRepository::find( $completed['order_item_id'] );
		$this->assertSame( ScheduleStates::COMPLETED, $invite['schedule_state'] );
		$this->assertSame( $completed['comment_id'], (int) $invite['review_comment_id'] );
		$this->assertSame( 1, $this->count_reviews_on_product( $completed['product_id'] ) );
	}

	public function test_token_exchange_completed_secret_303_to_edit(): void {
		$completed = $this->complete_guest_review();
		$location  = '';
		add_filter(
			'wp_redirect',
			static function ( $loc ) use ( &$location ) {
				$location = (string) $loc;
				return false;
			}
		);
		ob_start();
		try {
			TokenExchangeEndpoint::handle( $completed['raw'] );
		} catch ( \ErrorException $e ) {
			if ( ! str_contains( $e->getMessage(), 'Cannot modify header' ) ) {
				ob_end_clean();
				throw $e;
			}
		}
		ob_end_clean();
		$this->assertStringContainsString( 'upr-review/edit', $location );
	}

	public function test_unredeemed_invite_still_submits_not_edits(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'        => $ctx['order']->get_id(),
				'product_id'      => $product_id,
				'schedule_state'  => ScheduleStates::INITIAL_SENT,
				'initial_sent_at' => current_time( 'mysql', true ),
			)
		);
		$issued = TokenService::issue_invite( $ctx['order_item_id'], $product_id );
		$this->assertNotNull( $issued );
		$outcome = InviteTokenDispatcher::dispatch( $issued['raw'] );
		$this->assertSame( 'form', $outcome['kind'] );
		$this->assertStringContainsString( 'upr-review/form', $outcome['url'] );
		$this->assertNotNull( TokenRepository::find_active_by_raw( $issued['raw'], 'invite' ) );
	}

	public function test_security_revoked_redeemed_invite_generic_denial(): void {
		$completed = $this->complete_guest_review();
		TokenRepository::revoke( $completed['invite_token_id'] );
		$this->assertNull( CompletedInviteLookup::match_row( TokenRepository::find_by_id( $completed['invite_token_id'] ) ) );

		$outcome = InviteTokenDispatcher::dispatch( $completed['raw'] );
		$this->assertSame( 'deny', $outcome['kind'] );

		ob_start();
		try {
			TokenExchangeEndpoint::handle( $completed['raw'] );
		} catch ( \ErrorException $e ) {
			if ( ! str_contains( $e->getMessage(), 'Cannot modify header' ) ) {
				ob_end_clean();
				throw $e;
			}
		}
		$body = (string) ob_get_clean();
		$this->assertStringContainsString( 'This review invitation is not available.', $body );
		$this->assertSame( 1, $this->count_reviews_on_product( $completed['product_id'] ) );
		$this->assertFalse( GuestSubmitAuthorization::is_armed() );
	}

	public function test_e15_post_complete_suppress_still_allows_guest_edit(): void {
		$completed = $this->complete_guest_review();
		InviteRepository::upsert(
			$completed['order_item_id'],
			array(
				'schedule_state'    => ScheduleStates::SUPPRESSED,
				'suppression_code'  => 'test_post_complete',
			)
		);
		$outcome = InviteTokenDispatcher::dispatch( $completed['raw'] );
		$this->assertSame( 'edit', $outcome['kind'] );
	}

	/**
	 * E30: seed 9 hour-children; first mint while parent is locked re-counts 9; second visit is generic-denied.
	 *
	 * WP PHPUnit maps CREATE TABLE → CREATE TEMPORARY TABLE (session-scoped), so a second mysqli
	 * cannot FOR UPDATE the parent (errno 1146). Interleaving is proven on one InnoDB session:
	 * recount happens after SELECT … FOR UPDATE; the next serialized mint sees count 10 and
	 * must not revoke or insert. Never 11. Exactly one unrevoked edit_session.
	 */
	public function test_e30_ten_per_hour_serialized_reissue(): void {
		$completed = $this->complete_guest_review();
		$parent    = TokenRepository::find_by_id( $completed['invite_token_id'] );
		$this->assertIsArray( $parent );

		for ( $i = 0; $i < 9; $i++ ) {
			$created = TokenRepository::create(
				$completed['order_item_id'],
				'edit_session',
				$completed['product_id'],
				gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
				$completed['invite_token_id']
			);
			$this->assertNotNull( $created );
		}

		$locked_count = -1;
		EditSessionService::$after_parent_lock_for_tests = static function () use ( &$locked_count, $completed ): void {
			$locked_count = TokenRepository::count_edit_sessions_in_rolling_hour( $completed['invite_token_id'] );
		};
		$first = EditSessionService::issue_serialized( $parent );
		EditSessionService::$after_parent_lock_for_tests = null;
		$this->assertNotNull( $first );
		$this->assertSame( 9, $locked_count, 'E30 must re-count hour children while the parent row is locked' );

		$second = EditSessionService::issue_serialized( $parent );
		$this->assertNull( $second );

		$this->assertSame( 10, TokenRepository::count_edit_sessions_in_rolling_hour( $completed['invite_token_id'] ) );
		$this->assertSame( 1, $this->count_unrevoked_edit_sessions( $completed['invite_token_id'] ) );

		$eleventh = InviteTokenDispatcher::dispatch( $completed['raw'] );
		$this->assertSame( 'deny', $eleventh['kind'] );
		$this->assertSame( 10, TokenRepository::count_edit_sessions_in_rolling_hour( $completed['invite_token_id'] ) );
		$this->assertSame( 1, $this->count_unrevoked_edit_sessions( $completed['invite_token_id'] ) );
	}

	public function test_e30_concurrent_from_zero_at_most_one_active(): void {
		$completed = $this->complete_guest_review();
		$parent    = TokenRepository::find_by_id( $completed['invite_token_id'] );
		$this->assertIsArray( $parent );

		$first = EditSessionService::issue_serialized( $parent );
		$this->assertNotNull( $first );

		$second = EditSessionService::issue_serialized( $parent );
		$this->assertNotNull( $second );
		$this->assertSame( 2, TokenRepository::count_edit_sessions_in_rolling_hour( $completed['invite_token_id'] ) );
		$this->assertSame( 1, $this->count_unrevoked_edit_sessions( $completed['invite_token_id'] ) );
	}

	public function test_guest_edit_post_hold_and_identity_unchanged(): void {
		$completed = $this->complete_guest_review();
		$comment   = get_comment( $completed['comment_id'] );
		$this->assertInstanceOf( \WP_Comment::class, $comment );
		$email = (string) $comment->comment_author_email;
		$author = (string) $comment->comment_author;
		wp_set_comment_status( $completed['comment_id'], 'approve' );
		$this->assertSame( '1', (string) get_comment( $completed['comment_id'] )->comment_approved );

		\WC_Comments::clear_transients( $completed['product_id'] );

		InviteTokenDispatcher::dispatch( $completed['raw'] );
		ob_start();
		$this->post_edit( $completed['comment_id'], 4, 'Updated guest review body for M14.' );
		ob_end_clean();
		$fresh = get_comment( $completed['comment_id'] );
		$this->assertSame( '0', (string) $fresh->comment_approved );
		$this->assertSame( 'Updated guest review body for M14.', (string) $fresh->comment_content );
		$this->assertSame( $email, (string) $fresh->comment_author_email );
		$this->assertSame( $author, (string) $fresh->comment_author );
		$this->assertSame( 4, (int) get_comment_meta( $completed['comment_id'], 'rating', true ) );
		$this->assertSame( $completed['order_item_id'], (int) get_comment_meta( $completed['comment_id'], '_upr_order_item_id', true ) );

		$product = wc_get_product( $completed['product_id'] );
		$this->assertSame( 0, (int) $product->get_review_count() );
		$this->assertSame( 0.0, (float) $product->get_average_rating() );

		$audits = $this->audit_rows( 'review.customer_edited' );
		$this->assertCount( 1, $audits );
		$payload = json_decode( (string) $audits[0]['payload_json'], true );
		$this->assertIsArray( $payload );
		$json = wp_json_encode( $payload );
		$this->assertStringNotContainsString( $email, $json );
		$this->assertStringNotContainsString( 'Updated guest', $json );
		$this->assertArrayHasKey( 'finalise_op_id', $payload );
		$this->assertArrayNotHasKey( 'hmac', $payload );
		$this->assertTrue( $payload['content_changed'] );
		$this->assertTrue( $payload['rating_changed'] );
	}

	public function test_logged_in_edit_and_c20(): void {
		$product_id = $this->upr_create_product();
		$user_id    = $this->factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'buyer@example.com',
			)
		);
		$this->grant_purchase( $user_id, $product_id );
		$comment_id = $this->insert_review( $product_id, '1', $user_id, 'Original logged-in review text.' );
		update_comment_meta( $comment_id, 'rating', 5 );
		wp_set_comment_status( $comment_id, 'approve' );

		wp_set_current_user( $user_id );
		$avail = CustomerEditAvailability::resolve( $comment_id, $user_id );
		$this->assertTrue( $avail['can_edit'], wp_json_encode( $avail ) );

		$_GET['comment_id'] = (string) $comment_id;
		$html               = $this->capture_edit_get();
		$this->assertStringContainsString( '<fieldset>', $html );
		$this->assertStringContainsString( 'for="upr_rating"', $html );
		$this->assertStringContainsString( 'aria-required="true"', $html );
		$this->assertStringContainsString( 'name="upr_nonce"', $html );
		$this->assertStringContainsString( 'upr-review/edit', $html );
		$this->assertStringNotContainsString( 'buyer@example.com', $html );
		$this->assertDoesNotMatchRegularExpression( '/upr-review\/[A-Za-z0-9_-]{20,}/', $html );

		ob_start();
		$this->post_edit( $comment_id, 2, 'Logged-in edited review body.' );
		ob_end_clean();
		$this->assertSame( '0', (string) get_comment( $comment_id )->comment_approved );
		$product = wc_get_product( $product_id );
		$this->assertSame( 0, (int) $product->get_review_count() );
	}

	public function test_pending_stays_pending_and_noop(): void {
		$completed = $this->complete_guest_review();
		$this->assertSame( '0', (string) get_comment( $completed['comment_id'] )->comment_approved );
		InviteTokenDispatcher::dispatch( $completed['raw'] );
		$live    = get_comment( $completed['comment_id'] );
		$content = (string) $live->comment_content;
		$rating  = (int) get_comment_meta( $completed['comment_id'], 'rating', true );
		ob_start();
		$this->post_edit( $completed['comment_id'], $rating, $content );
		$body = (string) ob_get_clean();
		$this->assertStringContainsString( 'No changes to save', $body );
		$this->assertSame( '0', (string) get_comment( $completed['comment_id'] )->comment_approved );
		$this->assertSame( 0, count( $this->audit_rows( 'review.customer_edited' ) ) );
	}

	public function test_body_only_edit_audit_flags(): void {
		$completed = $this->complete_guest_review();
		wp_set_comment_status( $completed['comment_id'], 'approve' );
		$rating = (int) get_comment_meta( $completed['comment_id'], 'rating', true );
		InviteTokenDispatcher::dispatch( $completed['raw'] );
		ob_start();
		$this->post_edit( $completed['comment_id'], $rating, 'Body-only customer edit for M14 flags.' );
		ob_end_clean();
		$this->assertSame( '0', (string) get_comment( $completed['comment_id'] )->comment_approved );
		$this->assertSame( $rating, (int) get_comment_meta( $completed['comment_id'], 'rating', true ) );
		$payload = $this->customer_edited_payload( $completed['comment_id'] );
		$this->assertTrue( $payload['content_changed'] );
		$this->assertFalse( $payload['rating_changed'] );
	}

	public function test_rating_only_edit_audit_flags(): void {
		$completed = $this->complete_guest_review();
		wp_set_comment_status( $completed['comment_id'], 'approve' );
		$body = (string) get_comment( $completed['comment_id'] )->comment_content;
		InviteTokenDispatcher::dispatch( $completed['raw'] );
		ob_start();
		$this->post_edit( $completed['comment_id'], 2, $body );
		ob_end_clean();
		$this->assertSame( '0', (string) get_comment( $completed['comment_id'] )->comment_approved );
		$this->assertSame( $body, (string) get_comment( $completed['comment_id'] )->comment_content );
		$this->assertSame( 2, (int) get_comment_meta( $completed['comment_id'], 'rating', true ) );
		$payload = $this->customer_edited_payload( $completed['comment_id'] );
		$this->assertFalse( $payload['content_changed'] );
		$this->assertTrue( $payload['rating_changed'] );
	}

	public function test_two_posts_one_commit_one_409(): void {
		$completed = $this->complete_guest_review();
		wp_set_comment_status( $completed['comment_id'], 'approve' );
		InviteTokenDispatcher::dispatch( $completed['raw'] );

		$hmac = EditClaimRepository::hmac_body( EditClaimRepository::canonical_body( 'First concurrent edit body.' ) );
		$one  = EditClaimRepository::acquire( $completed['comment_id'], 'guest_session', $hmac, 3, 'approve' );
		$this->assertNotNull( $one );
		$two = EditClaimRepository::acquire( $completed['comment_id'], 'guest_session', $hmac, 4, 'approve' );
		$this->assertNull( $two );

		ob_start();
		$this->post_edit( $completed['comment_id'], 2, 'Second overlapping HTTP edit.' );
		$http = (string) ob_get_clean();
		$this->assertStringContainsString( 'This review cannot be updated right now.', $http );
		$this->assertSame( '1', (string) get_comment( $completed['comment_id'] )->comment_approved );
	}

	public function test_direct_comment_and_rating_meta_bypasses_denied(): void {
		$comment_id = $this->insert_review( $this->upr_create_product(), '0' );
		$this->assertFalse( CustomerEditAuthorization::is_armed() );
		$this->assertFalse( current_user_can( 'moderate_comments' ) );

		$result = CustomerEditGuard::filter_update_comment_data(
			array( 'comment_content' => 'Bypass body' ),
			get_comment( $comment_id ),
			array()
		);
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertStringNotContainsString( 'Bypass body', (string) get_comment( $comment_id )->comment_content );

		global $wpdb;
		$wpdb->insert(
			$wpdb->commentmeta,
			array(
				'comment_id' => $comment_id,
				'meta_key'   => 'rating',
				'meta_value' => '5',
			)
		);
		clean_comment_cache( $comment_id );
		$this->assertFalse( add_comment_meta( $comment_id, 'rating', 1 ) );
		$this->assertFalse( update_comment_meta( $comment_id, 'rating', 1 ) );
		$this->assertFalse( delete_comment_meta( $comment_id, 'rating' ) );
		$this->assertSame( 5, (int) get_comment_meta( $comment_id, 'rating', true ) );

		$user_id = $this->factory()->user->create( array( 'role' => 'customer' ) );
		$this->assertFalse( user_can( $user_id, 'edit_comment', $comment_id ) );
	}

	public function test_form_session_cannot_authenticate_edit(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );
		$comment_id = $this->insert_review( $product_id, '0' );
		$_GET['comment_id'] = (string) $comment_id;
		$html               = $this->capture_edit_get();
		$this->assertStringContainsString( 'This review cannot be edited.', $html );
	}

	public function test_approve_to_hold_operator_spam_wins(): void {
		$comment_id = $this->insert_review( $this->upr_create_product(), '1' );
		wp_set_comment_status( $comment_id, 'approve' );
		clean_comment_cache( $comment_id );
		$this->assertSame( 1, ApproveToHoldCas::cas_write( $comment_id ) );
		clean_comment_cache( $comment_id );
		$this->assertSame( '0', (string) get_comment( $comment_id )->comment_approved );
		$this->assertSame( 0, ApproveToHoldCas::cas_write( $comment_id ) );

		$cid = $this->insert_review( $this->upr_create_product(), '1' );
		wp_set_comment_status( $cid, 'approve' );
		wp_set_comment_status( $cid, 'spam' );
		clean_comment_cache( $cid );
		$this->assertSame( 0, ApproveToHoldCas::cas_write( $cid ) );
		clean_comment_cache( $cid );
		$this->assertSame( 'spam', (string) get_comment( $cid )->comment_approved );

		$tid = $this->insert_review( $this->upr_create_product(), '1' );
		wp_set_comment_status( $tid, 'approve' );
		wp_set_comment_status( $tid, 'trash' );
		clean_comment_cache( $tid );
		$this->assertSame( 0, ApproveToHoldCas::cas_write( $tid ) );
		clean_comment_cache( $tid );
		$this->assertSame( 'trash', (string) get_comment( $tid )->comment_approved );
	}

	public function test_operator_spam_interleave_during_finalise_never_restores_hold(): void {
		$completed = $this->complete_guest_review();
		wp_set_comment_status( $completed['comment_id'], 'approve' );
		$hmac    = EditClaimRepository::hmac_body( (string) get_comment( $completed['comment_id'] )->comment_content );
		$rating  = (int) get_comment_meta( $completed['comment_id'], 'rating', true );
		$claimed = EditClaimRepository::acquire( $completed['comment_id'], 'guest_session', $hmac, $rating, 'approve' );
		$this->assertNotNull( $claimed );
		$op = wp_generate_uuid4();
		$this->assertTrue( EditClaimRepository::mark_writing( $completed['comment_id'], $claimed['claim_token'], $claimed['generation'] ) );
		$this->assertTrue( EditClaimRepository::mark_content_written( $completed['comment_id'], $claimed['claim_token'], $claimed['generation'], $op ) );
		wp_set_comment_status( $completed['comment_id'], 'spam' );
		$outcome = EditFinaliser::run( $completed['comment_id'], $claimed['claim_token'], $claimed['generation'] );
		$this->assertSame( 'abandoned', $outcome );
		$this->assertSame( 'spam', (string) get_comment( $completed['comment_id'] )->comment_approved );
		$this->assertSame( 0, count( $this->audit_rows( 'review.customer_edited' ) ) );
		$this->assertSame( 0, $this->count_content_edited_skips( $op ) );
	}

	public function test_fingerprint_mismatch_abandons_on_reconcile(): void {
		$comment_id = $this->insert_review( $this->upr_create_product(), '0' );
		update_comment_meta( $comment_id, 'rating', 5 );
		$claimed = EditClaimRepository::acquire( $comment_id, 'logged_in', EditClaimRepository::hmac_body( 'expected' ), 5, 'hold' );
		$this->assertNotNull( $claimed );
		$this->assertTrue( EditClaimRepository::mark_writing( $comment_id, $claimed['claim_token'], $claimed['generation'] ) );
		$this->assertTrue( EditClaimRepository::mark_content_written( $comment_id, $claimed['claim_token'], $claimed['generation'], wp_generate_uuid4() ) );
		$stats = EditClaimReconciler::run();
		$this->assertSame( 1, $stats['abandoned'] );
		$row = EditClaimRepository::get( $comment_id );
		$this->assertSame( 'abandoned', (string) ( $row['finalise_outcome'] ?? '' ) );
	}

	public function test_writing_mismatch_abandons_without_status_write(): void {
		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		$comment_id = $this->insert_review( $this->upr_create_product(), '1', 0, 'Original approved body for writing recovery.' );
		wp_set_comment_status( $comment_id, 'approve' );
		clean_comment_cache( $comment_id );
		$original     = (string) get_comment( $comment_id )->comment_content;
		$prior_hmac   = EditClaimRepository::hmac_body( $original );
		$prior_rating = (int) get_comment_meta( $comment_id, 'rating', true );
		$target_hmac  = EditClaimRepository::hmac_body( 'Claimed target body that was never written.' );
		$claimed      = EditClaimRepository::acquire(
			$comment_id,
			'logged_in',
			$target_hmac,
			2,
			'approve',
			true,
			true,
			$prior_hmac,
			$prior_rating
		);
		$this->assertNotNull( $claimed );
		$this->assertTrue( EditClaimRepository::mark_writing( $comment_id, $claimed['claim_token'], $claimed['generation'] ) );

		$external_body = 'External operator rewrite of the review body.';
		global $wpdb;
		$wpdb->update(
			$wpdb->comments,
			array( 'comment_content' => $external_body ),
			array( 'comment_ID' => $comment_id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_comment_cache( $comment_id );
		$this->assertSame( '1', (string) get_comment( $comment_id )->comment_approved );

		$status_hooks     = 0;
		$transition_hooks = 0;
		add_action(
			'wp_set_comment_status',
			static function ( $id ) use ( &$status_hooks, $comment_id ): void {
				if ( (int) $id === $comment_id ) {
					++$status_hooks;
				}
			},
			10,
			1
		);
		add_action(
			'transition_comment_status',
			static function ( $new, $old, $comment ) use ( &$transition_hooks, $comment_id ): void {
				if ( $comment instanceof \WP_Comment && (int) $comment->comment_ID === $comment_id ) {
					++$transition_hooks;
				}
			},
			10,
			3
		);

		EditClaimReconciler::run();

		clean_comment_cache( $comment_id );
		$fresh = get_comment( $comment_id );
		$this->assertSame( '1', (string) $fresh->comment_approved );
		$this->assertSame( $external_body, (string) $fresh->comment_content );
		$this->assertSame( $prior_rating, (int) get_comment_meta( $comment_id, 'rating', true ) );
		$this->assertSame( 0, $status_hooks );
		$this->assertSame( 0, $transition_hooks );

		$row = EditClaimRepository::get( $comment_id );
		$this->assertSame( 'abandoned', (string) ( $row['finalise_outcome'] ?? '' ) );
		$this->assertNotEmpty( $row['finalized_at'] );
		$this->assertSame( '', (string) ( $row['finalise_op_id'] ?? '' ) );
		$mine = array_values(
			array_filter(
				$this->audit_rows( 'review.customer_edited' ),
				static function ( array $row ) use ( $comment_id ): bool {
					$payload = json_decode( (string) $row['payload_json'], true );
					return is_array( $payload ) && (int) ( $payload['comment_id'] ?? 0 ) === $comment_id;
				}
			)
		);
		$this->assertCount( 0, $mine );
		$skips = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}upr_moderation_assessments WHERE comment_id = %d",
				$comment_id
			)
		);
		$this->assertSame( 0, $skips );
		$this->assertSame( 0, $this->count_assess_jobs_for_comment( $comment_id ) );
	}

	public function test_crash_after_each_e33_step_is_exactly_once(): void {
		update_option( Options::LOCAL_AI_SHADOW_ENABLED, 'yes', false );
		for ( $step = 1; $step <= 7; $step++ ) {
			$this->assert_crash_recover_exactly_once( $step );
		}
	}

	public function test_crash_after_body_write_rolls_back_and_is_not_unwritten(): void {
		$this->assert_write_crash_rolls_back( EditWriteService::CRASH_AFTER_BODY );
	}

	public function test_crash_after_rating_write_rolls_back_and_is_not_unwritten(): void {
		$this->assert_write_crash_rolls_back( EditWriteService::CRASH_AFTER_RATING );
	}

	public function test_crash_before_content_written_cas_rolls_back_and_is_not_unwritten(): void {
		$this->assert_write_crash_rolls_back( EditWriteService::CRASH_BEFORE_CONTENT_WRITTEN_CAS );
	}

	public function test_support_export_contract_unchanged(): void {
		$payload = SupportExport::build();
		$this->assertSame( 'upr-support-export/v1', $payload['schema_version'] );
		$json = wp_json_encode( $payload );
		$this->assertIsString( $json );
		$this->assertStringNotContainsString( 'finalise_op_id', $json );
		$this->assertStringNotContainsString( 'claim_token', $json );
		$this->assertStringNotContainsString( 'review body', $json );
		$this->assertSame( Schema::DB_VERSION, $payload['schema_target'] );
	}

	/**
	 * @return array{raw:string,invite_token_id:int,comment_id:int,order_item_id:int,product_id:int,order_id:int}
	 */
	private function complete_guest_review(): array {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'        => $ctx['order']->get_id(),
				'product_id'      => $product_id,
				'schedule_state'  => ScheduleStates::INITIAL_SENT,
				'initial_sent_at' => current_time( 'mysql', true ),
			)
		);
		$issued = TokenService::issue_invite( $ctx['order_item_id'], $product_id );
		$this->assertNotNull( $issued );
		$raw = (string) $issued['raw'];
		$this->assertNotNull( TokenService::exchange_invite( $raw ) );
		$session = FormSessionAuthenticator::current_session();
		$this->assertNotNull( $session );

		global $wp_query;
		$_SERVER['REQUEST_METHOD'] = 'POST';
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set( 'upr_review_form', '1' );
		}
		set_query_var( 'upr_review_form', '1' );
		$_POST['upr_session_id'] = (string) (int) $session['id'];
		$_POST['upr_nonce']      = wp_create_nonce( 'upr_review_submit_' . (int) $session['id'] );
		$_POST['upr_rating']     = '5';
		$_POST['upr_content']    = 'Excellent product via invitation for M14 ' . wp_generate_uuid4();
		ob_start();
		ReviewSubmitHandler::handle();
		$submit_body = (string) ob_get_clean();

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::COMPLETED, $invite['schedule_state'], $submit_body );
		$comment_id = (int) $invite['review_comment_id'];
		$this->assertGreaterThan( 0, $comment_id );

		$parent = TokenRepository::find_by_id( (int) $issued['id'] );
		$this->assertNotEmpty( $parent['redeemed_at'] ?? null );
		$this->assertEmpty( $parent['revoked_at'] ?? null );

		GuestSubmitAuthorization::clear();
		CustomerEditAuthorization::clear();
		wp_set_current_user( 0 );
		$_POST = array();
		set_query_var( 'upr_review_form', '' );
		SessionCookie::clear();

		return array(
			'raw'             => $raw,
			'invite_token_id' => (int) $issued['id'],
			'comment_id'      => $comment_id,
			'order_item_id'   => $ctx['order_item_id'],
			'product_id'      => $product_id,
			'order_id'        => (int) $ctx['order']->get_id(),
		);
	}

	private function capture_edit_get(): string {
		ob_start();
		try {
			ReviewEditHandler::handle_get();
		} catch ( \ErrorException $e ) {
			if ( ! str_contains( $e->getMessage(), 'Cannot modify header' ) ) {
				ob_end_clean();
				throw $e;
			}
		}
		return (string) ob_get_clean();
	}

	private function grant_purchase( int $user_id, int $product_id ): void {
		$user = get_userdata( $user_id );
		$this->assertNotFalse( $user );
		$product = wc_get_product( $product_id );
		$this->assertNotFalse( $product );
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$order->set_billing_email( $user->user_email );
		$order->add_product( $product, 1 );
		$order->set_status( 'completed' );
		$order->calculate_totals( false );
		$order->save();
	}

	private function insert_review( int $product_id, string $approved, int $user_id = 0, string $content = 'Synthetic M14 review.' ): int {
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Synthetic Reviewer',
				'comment_author_email' => 'synthetic@example.test',
				'comment_content'      => $content,
				'comment_type'         => 'review',
				'comment_approved'     => $approved,
				'comment_parent'       => 0,
				'user_id'              => $user_id,
			)
		);
		$this->assertIsInt( $comment_id );
		global $wpdb;
		$wpdb->replace(
			$wpdb->commentmeta,
			array(
				'comment_id' => (int) $comment_id,
				'meta_key'   => 'rating',
				'meta_value' => '5',
			),
			array( '%d', '%s', '%s' )
		);
		return (int) $comment_id;
	}

	private function post_edit( int $comment_id, int $rating, string $content ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['upr_comment_id']   = (string) $comment_id;
		$_POST['upr_nonce']        = wp_create_nonce( 'upr_review_edit_' . $comment_id );
		$_POST['upr_rating']       = (string) $rating;
		$_POST['upr_content']      = $content;
		ReviewEditHandler::handle_post();
	}

	private function count_reviews_on_product( int $product_id ): int {
		$comments = get_comments(
			array(
				'post_id' => $product_id,
				'type'    => 'review',
				'count'   => true,
				'status'  => 'all',
			)
		);
		return (int) $comments;
	}

	private function count_unrevoked_edit_sessions( int $parent_id ): int {
		global $wpdb;
		$n = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . TokenRepository::table() . ' WHERE parent_token_id = %d AND purpose = %s AND revoked_at IS NULL',
				$parent_id,
				'edit_session'
			)
		);
		return (int) $n;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function customer_edited_payload( int $comment_id ): array {
		$mine = array_values(
			array_filter(
				$this->audit_rows( 'review.customer_edited' ),
				static function ( array $row ) use ( $comment_id ): bool {
					$payload = json_decode( (string) $row['payload_json'], true );
					return is_array( $payload ) && (int) ( $payload['comment_id'] ?? 0 ) === $comment_id;
				}
			)
		);
		$this->assertCount( 1, $mine );
		$payload = json_decode( (string) $mine[0]['payload_json'], true );
		$this->assertIsArray( $payload );
		return $payload;
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function audit_rows( string $event_type ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}upr_audit WHERE event_type = %s ORDER BY id ASC",
				$event_type
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	private function count_content_edited_skips( string $op_id ): int {
		global $wpdb;
		$n = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}upr_moderation_assessments WHERE failure_code = %s AND source_op_id = %s",
				'content_edited',
				$op_id
			)
		);
		return (int) $n;
	}

	private function count_assess_jobs_for_op( string $op_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			return 0;
		}
		$n = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE hook = %s AND args LIKE %s",
				'upr_assess_review',
				'%' . $wpdb->esc_like( $op_id ) . '%'
			)
		);
		return (int) $n;
	}

	private function count_assess_jobs_for_comment( int $comment_id ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $found !== $table ) {
			return 0;
		}
		$n = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE hook = %s AND args LIKE %s",
				'upr_assess_review',
				$wpdb->esc_like( '[' . $comment_id . ',' ) . '%'
			)
		);
		return (int) $n;
	}

	private function assert_crash_recover_exactly_once( int $step ): void {
		$completed = $this->complete_guest_review();
		wp_set_comment_status( $completed['comment_id'], 'approve' );
		$comment = get_comment( $completed['comment_id'] );
		$hmac    = EditClaimRepository::hmac_body( (string) $comment->comment_content );
		$rating  = (int) get_comment_meta( $completed['comment_id'], 'rating', true );
		$claimed = EditClaimRepository::acquire( $completed['comment_id'], 'guest_session', $hmac, $rating, 'approve' );
		$this->assertNotNull( $claimed );
		$op = wp_generate_uuid4();
		$this->assertTrue( EditClaimRepository::mark_writing( $completed['comment_id'], $claimed['claim_token'], $claimed['generation'] ) );
		$this->assertTrue( EditClaimRepository::mark_content_written( $completed['comment_id'], $claimed['claim_token'], $claimed['generation'], $op ) );

		EditFinaliser::$crash_after_step_for_tests = $step;
		try {
			EditFinaliser::run( $completed['comment_id'], $claimed['claim_token'], $claimed['generation'] );
			if ( $step < 7 ) {
				$this->fail( 'expected crash after step ' . $step );
			}
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'upr_edit_finalise_crash_' . $step, $e->getMessage() );
		}
		EditFinaliser::$crash_after_step_for_tests = null;

		global $wpdb;
		$wpdb->update(
			EditClaimRepository::table(),
			array( 'finalise_lease_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 120 ) ),
			array( 'comment_id' => $completed['comment_id'] ),
			array( '%s' ),
			array( '%d' )
		);

		EditClaimReconciler::run();
		EditClaimReconciler::run();

		$this->assertSame( 1, $this->count_content_edited_skips( $op ), 'step ' . $step . ' skip' );
		$audits = array_values(
			array_filter(
				$this->audit_rows( 'review.customer_edited' ),
				static function ( array $row ) use ( $op ): bool {
					$payload = json_decode( (string) $row['payload_json'], true );
					return is_array( $payload ) && ( $payload['finalise_op_id'] ?? '' ) === $op;
				}
			)
		);
		$this->assertCount( 1, $audits, 'step ' . $step . ' audit' );
		$jobs = $this->count_assess_jobs_for_op( $op );
		$this->assertLessThanOrEqual( 1, $jobs, 'step ' . $step . ' jobs' );
		$this->assertSame( '0', (string) get_comment( $completed['comment_id'] )->comment_approved );
	}

	private function assert_write_crash_rolls_back( string $step ): void {
		$completed = $this->complete_guest_review();
		wp_set_comment_status( $completed['comment_id'], 'approve' );
		$original_body   = (string) get_comment( $completed['comment_id'] )->comment_content;
		$original_rating = (int) get_comment_meta( $completed['comment_id'], 'rating', true );

		InviteTokenDispatcher::dispatch( $completed['raw'] );
		EditWriteService::$crash_after_for_tests = $step;
		ob_start();
		try {
			$this->post_edit( $completed['comment_id'], 2, 'Crash-window edited body for M14.' );
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$this->fail( 'handler must catch write crash: ' . $e->getMessage() );
		}
		$http = (string) ob_get_clean();
		EditWriteService::$crash_after_for_tests = null;

		$this->assertStringContainsString( 'Could not save your review.', $http );
		clean_comment_cache( $completed['comment_id'] );
		wp_cache_delete( $completed['comment_id'], 'comment_meta' );
		update_meta_cache( 'comment', array( $completed['comment_id'] ) );
		$this->assertSame( $original_body, (string) get_comment( $completed['comment_id'] )->comment_content, $step );
		$this->assertSame( $original_rating, (int) get_comment_meta( $completed['comment_id'], 'rating', true ), $step );
		$this->assertSame( '1', (string) get_comment( $completed['comment_id'] )->comment_approved, $step );

		$row = EditClaimRepository::get( $completed['comment_id'] );
		$this->assertIsArray( $row );
		$this->assertSame( 'writing', (string) $row['phase'] );
		$this->assertEmpty( $row['finalized_at'] );

		global $wpdb;
		$wpdb->update(
			EditClaimRepository::table(),
			array( 'claim_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 3600 ) ),
			array( 'comment_id' => $completed['comment_id'] ),
			array( '%s' ),
			array( '%d' )
		);
		$this->assertNull(
			EditClaimRepository::acquire( $completed['comment_id'], 'guest_session', EditClaimRepository::hmac_body( 'other' ), 3, 'approve' )
		);

		$stats = EditClaimReconciler::run();
		$this->assertSame( 0, $stats['released'], $step );
		$row = EditClaimRepository::get( $completed['comment_id'] );
		$this->assertSame( 'abandoned', (string) ( $row['finalise_outcome'] ?? '' ), $step );
		$this->assertNotEmpty( $row['finalized_at'] );
		$this->assertSame( $original_body, (string) get_comment( $completed['comment_id'] )->comment_content, $step );
		$this->assertSame( '1', (string) get_comment( $completed['comment_id'] )->comment_approved, $step );
		$mine = array_values(
			array_filter(
				$this->audit_rows( 'review.customer_edited' ),
				static function ( array $row ) use ( $completed ): bool {
					$payload = json_decode( (string) $row['payload_json'], true );
					return is_array( $payload ) && (int) ( $payload['comment_id'] ?? 0 ) === $completed['comment_id'];
				}
			)
		);
		$this->assertCount( 0, $mine, $step );
	}
}
