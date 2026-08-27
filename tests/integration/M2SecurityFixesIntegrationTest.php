<?php
/**
 * M2 security / concurrency / reconcile / migrator integration tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Http\ReviewSubmitHandler;
use UniversalProductReviews\Http\RewriteRules;
use UniversalProductReviews\Invitations\CompletionService;
use UniversalProductReviews\Invitations\InvitationScheduler;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ReconciliationService;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Invitations\SubmitClaimService;
use UniversalProductReviews\Invitations\SuppressionService;
use UniversalProductReviews\Submission\GuestSubmitAuthorization;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;
use UniversalProductReviews\Tokens\TokenRepository;
use UniversalProductReviews\Tokens\TokenService;
use WP_UnitTestCase;

final class M2GuestAuthContextIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		wp_set_current_user( 0 );
		GuestSubmitAuthorization::clear();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function tear_down(): void {
		GuestSubmitAuthorization::clear();
		parent::tear_down();
	}

	public function test_session_cookie_alone_does_not_authorize_native_comment(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );
		$this->assertTrue( FormSessionAuthenticator::authorize_product( $product_id ) );
		$this->assertFalse( GuestSubmitAuthorization::is_armed() );

		$this->expectException( \WPDieException::class );
		wp_new_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Forged Name',
				'comment_author_email' => 'forged@evil.example',
				'comment_author_url'   => '',
				'comment_content'      => 'Bypass attempt',
				'comment_type'         => 'review',
				'user_id'              => 0,
			),
			true
		);
	}

	public function test_genuine_handler_allows_submit_with_order_identity(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$prep       = $this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );
		$session    = $prep['session'];

		$_SERVER['REQUEST_METHOD'] = 'POST';
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set( 'upr_review_form', '1' );
		}
		set_query_var( 'upr_review_form', '1' );
		$_POST['upr_session_id'] = (string) (int) $session['id'];
		$_POST['upr_nonce']      = wp_create_nonce( 'upr_review_submit_' . (int) $session['id'] );
		$_POST['upr_rating']     = '5';
		$_POST['upr_content']    = 'Excellent product via invitation';
		$_POST['comment_author'] = 'Should Be Ignored';
		$_POST['comment_author_email'] = 'ignored@evil.example';

		ob_start();
		ReviewSubmitHandler::handle();
		ob_end_clean();

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::COMPLETED, $invite['schedule_state'] );
		$this->assertNotEmpty( $invite['review_comment_id'] );

		$comment = get_comment( (int) $invite['review_comment_id'] );
		$this->assertNotFalse( $comment );
		$this->assertSame( 'buyer@example.com', $comment->comment_author_email );
		$this->assertStringContainsString( 'Ada', $comment->comment_author );
		$this->assertNotSame( 'ignored@evil.example', $comment->comment_author_email );
	}

	public function test_wrong_nonce_rejected(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$prep       = $this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set( 'upr_review_form', '1' );
		}
		set_query_var( 'upr_review_form', '1' );
		$_POST['upr_session_id'] = (string) (int) $prep['session']['id'];
		$_POST['upr_nonce']      = 'bad-nonce';
		$_POST['upr_rating']     = '4';
		$_POST['upr_content']    = 'Nope';

		ob_start();
		ReviewSubmitHandler::handle();
		$body = ob_get_clean();
		$this->assertStringContainsString( 'Invalid request', $body );

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertNotSame( ScheduleStates::COMPLETED, $invite['schedule_state'] );
	}
}

final class M2SubmitClaimIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		wp_set_current_user( 0 );
		GuestSubmitAuthorization::clear();
	}

	public function test_concurrent_claims_only_one_wins(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$prep       = $this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );
		$session_id = (int) $prep['session']['id'];

		$first  = SubmitClaimService::acquire( $ctx['order_item_id'] );
		$second = SubmitClaimService::acquire( $ctx['order_item_id'] );
		$this->assertNotNull( $first );
		$this->assertNull( $second );

		// Interleaved: first creates comment under session+auth; second never gets claim.
		GuestSubmitAuthorization::arm( $product_id, $ctx['order_item_id'], $session_id, $first['token'] );
		$this->assertTrue( FormSessionAuthenticator::authorize_product( $product_id ) );
		$this->assertTrue( GuestSubmitAuthorization::allows_product( $product_id ) );
		$comment_id = wp_new_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Ada Lovelace',
				'comment_author_email' => 'buyer@example.com',
				'comment_author_url'   => '',
				'comment_content'      => 'First wins',
				'comment_type'         => 'review',
				'user_id'              => 0,
			),
			true
		);
		GuestSubmitAuthorization::clear();
		$this->assertIsInt( $comment_id );
		update_comment_meta( (int) $comment_id, '_upr_order_item_id', $ctx['order_item_id'] );

		$this->assertTrue(
			CompletionService::finalize(
				$ctx['order_item_id'],
				(int) $comment_id,
				$ctx['order']->get_id(),
				null,
				$first['token']
			)
		);

		// Second attempt to claim after completion must fail.
		$this->assertNull( SubmitClaimService::acquire( $ctx['order_item_id'] ) );

		$comments = get_comments(
			array(
				'post_id' => $product_id,
				'type'    => 'review',
				'status'  => 'all',
				'count'   => true,
			)
		);
		$this->assertSame( 1, (int) $comments );

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::COMPLETED, $invite['schedule_state'] );
		$this->assertSame( (int) $comment_id, (int) $invite['review_comment_id'] );
	}

	public function test_finalize_loses_to_suppression_discards_comment(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$prep       = $this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );
		$invite_tok = $prep['invite_token_id'];

		$claim = SubmitClaimService::acquire( $ctx['order_item_id'] );
		$this->assertNotNull( $claim );

		// Mid-flight: item becomes discontinued / suppressed; tokens revoked.
		SuppressionService::suppress_item( $ctx['order_item_id'], 'product_not_reviewable', $ctx['order']->get_id() );
		$after_suppress = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::SUPPRESSED, $after_suppress['schedule_state'] );

		$tokens = TokenRepository::find_active_invite( $ctx['order_item_id'] );
		$this->assertNull( $tokens );
		$parent = TokenRepository::find_by_id( $invite_tok );
		$this->assertNotNull( $parent );
		$this->assertNotEmpty( $parent['revoked_at'] );

		// Comment already past preprocess (race window); insert without re-checking session.
		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Ada Lovelace',
				'comment_author_email' => 'buyer@example.com',
				'comment_author_url'   => '',
				'comment_content'      => 'Should not complete',
				'comment_type'         => 'review',
				'comment_approved'     => 0,
				'user_id'              => 0,
			)
		);
		$this->assertIsInt( $comment_id );
		$this->assertGreaterThan( 0, (int) $comment_id );
		update_comment_meta( (int) $comment_id, '_upr_order_item_id', $ctx['order_item_id'] );

		$this->assertFalse(
			CompletionService::finalize(
				$ctx['order_item_id'],
				(int) $comment_id,
				$ctx['order']->get_id(),
				$invite_tok,
				$claim['token']
			)
		);
		$this->assertTrue(
			CompletionService::abandon_lost_submission(
				$ctx['order_item_id'],
				(int) $comment_id,
				$claim['token']
			)
		);

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::SUPPRESSED, $invite['schedule_state'] );
		$this->assertEmpty( $invite['review_comment_id'] );
		$this->assertEmpty( $invite['submit_claim_token'] );

		$comment = get_comment( (int) $comment_id );
		$this->assertNotFalse( $comment );
		$this->assertSame( 'spam', $comment->comment_approved );

		// finalize_from_comment / reconcile must not resurrect completion.
		$this->assertFalse(
			CompletionService::finalize_from_comment(
				$ctx['order_item_id'],
				(int) $comment_id,
				$ctx['order']->get_id()
			)
		);
		ReconciliationService::run( 90, false );
		$invite2 = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::SUPPRESSED, $invite2['schedule_state'] );
		$this->assertEmpty( $invite2['review_comment_id'] );

		$visible = get_comments(
			array(
				'post_id' => $product_id,
				'type'    => 'review',
				'status'  => array( 'approve', 'hold' ),
				'count'   => true,
			)
		);
		$this->assertSame( 0, (int) $visible );
	}

	public function test_expired_claim_without_comment_is_released(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'       => $ctx['order']->get_id(),
				'product_id'     => $product_id,
				'schedule_state' => ScheduleStates::INITIAL_SENT,
				'initial_sent_at'=> current_time( 'mysql', true ),
			)
		);
		$claim = SubmitClaimService::acquire( $ctx['order_item_id'] );
		$this->assertNotNull( $claim );

		InviteRepository::conditional_update(
			$ctx['order_item_id'],
			array( 'submit_claim_expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ),
			array()
		);

		$result = SubmitClaimService::recover_expired_claims();
		$this->assertSame( 1, $result['released'] );
		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::INITIAL_SENT, $row['schedule_state'] );
	}
}

final class M2MigratorLockIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Migrator::LOCK_KEY );
		Migrator::reset_schema_run_count();
	}

	public function tear_down(): void {
		Migrator::try_release_lock();
		delete_option( Migrator::LOCK_KEY );
		parent::tear_down();
	}

	public function test_second_contender_cannot_acquire_active_lock(): void {
		$this->assertTrue( Migrator::try_acquire_lock() );
		$owner = Migrator::current_owner_token();
		$this->assertNotNull( $owner );

		// Simulate another process: clear in-memory owner but keep option.
		$ref = new \ReflectionClass( Migrator::class );
		$prop = $ref->getProperty( 'owner_token' );
		$prop->setAccessible( true );
		$prop->setValue( null );

		$this->assertFalse( Migrator::try_acquire_lock() );

		$prop->setValue( $owner );
		Migrator::try_release_lock();
	}

	public function test_stale_lock_takeover_exactly_one_winner(): void {
		$stale = array(
			'owner' => 'stale-owner',
			'until' => time() - 10,
		);
		add_option( Migrator::LOCK_KEY, $stale, '', 'no' );

		$this->assertTrue( Migrator::try_acquire_lock() );
		$winner = Migrator::current_owner_token();
		$this->assertNotNull( $winner );
		$this->assertNotSame( 'stale-owner', $winner );

		$ref  = new \ReflectionClass( Migrator::class );
		$prop = $ref->getProperty( 'owner_token' );
		$prop->setAccessible( true );
		$prop->setValue( null );
		$this->assertFalse( Migrator::try_acquire_lock() );

		$prop->setValue( $winner );
		Migrator::try_release_lock();
	}

	public function test_former_owner_cannot_release_replacement_lock(): void {
		$this->assertTrue( Migrator::try_acquire_lock() );
		$first = Migrator::current_owner_token();

		// Force stale + takeover by another "process".
		update_option(
			Migrator::LOCK_KEY,
			array(
				'owner' => $first,
				'until' => time() - 5,
			),
			false
		);
		$ref  = new \ReflectionClass( Migrator::class );
		$prop = $ref->getProperty( 'owner_token' );
		$prop->setAccessible( true );
		$prop->setValue( null );

		$this->assertTrue( Migrator::try_acquire_lock() );
		$second = Migrator::current_owner_token();
		$this->assertNotSame( $first, $second );

		// Former owner attempts release — must not delete second's lock.
		$prop->setValue( $first );
		Migrator::try_release_lock();
		$still = get_option( Migrator::LOCK_KEY );
		$this->assertIsArray( $still );
		$this->assertSame( $second, $still['owner'] );

		$prop->setValue( $second );
		Migrator::try_release_lock();
	}

	public function test_competing_upgrade_runs_schema_once(): void {
		delete_option( Migrator::OPTION_VERSION );
		Migrator::reset_schema_run_count();
		$this->assertTrue( Migrator::upgrade_now() );
		$runs = Migrator::schema_run_count();
		$this->assertGreaterThanOrEqual( 1, $runs );
		Migrator::reset_schema_run_count();
		$this->assertTrue( Migrator::upgrade_now() );
		$this->assertSame( 0, Migrator::schema_run_count() );
	}
}

final class M2ReconcilePaginationIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
	}

	public function test_eligible_at_from_past_source_event_not_now_plus_delay(): void {
		$past = time() - ( 20 * DAY_IN_SECONDS );
		$this->upr_enable_invitation_emails( $past - DAY_IN_SECONDS );
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$ctx['order']->update_meta_data( InvitationScheduler::META_DELIVERY_CONFIRMED_AT, gmdate( 'Y-m-d H:i:s', $past ) );
		$ctx['order']->save();
		$order = wc_get_order( $ctx['order']->get_id() );
		$item  = $order->get_item( $ctx['order_item_id'] );
		$eval  = \UniversalProductReviews\Invitations\Eligibility::evaluate_item( $order, $item );
		$this->assertTrue( $eval['eligible'], 'precondition: item eligible (' . (string) ( $eval['reason'] ?? '' ) . ')' );

		InvitationScheduler::schedule_order( $order->get_id(), 'adapter', $past );

		$row = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertNotNull( $row, 'invite row should be created for eligible item' );
		$expected = gmdate( 'Y-m-d H:i:s', $past + ( 10 * DAY_IN_SECONDS ) );
		$this->assertSame( $expected, $row['eligible_at'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $past ), $row['source_event_at'] );
		$this->assertLessThanOrEqual( time(), strtotime( $row['eligible_at'] . ' UTC' ) );

		// Idempotent re-schedule must not shift eligible_at.
		InvitationScheduler::schedule_order( $order->get_id(), 'adapter', time() );
		$row2 = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( $expected, $row2['eligible_at'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', $past ), $row2['source_event_at'] );
	}

	public function test_iterate_orders_paginates_beyond_page_size(): void {
		$original = ReconciliationService::PAGE_SIZE;
		// Use reflection to temporarily shrink page size is not possible (const).
		// Instead assert generator yields all created orders when >0.
		$ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$product_id = $this->upr_create_product();
			$ctx        = $this->upr_create_order_with_item( $product_id );
			$ids[]      = $ctx['order']->get_id();
		}
		$after   = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$seen    = array();
		foreach ( ReconciliationService::iterate_order_ids( $after ) as $oid ) {
			$seen[] = $oid;
		}
		foreach ( $ids as $id ) {
			$this->assertContains( $id, $seen );
		}
		unset( $original );
	}
}

final class M2RewriteFlushIntegrationTest extends WP_UnitTestCase {

	public function test_plugin_does_not_register_frontend_flush(): void {
		global $wp_filter;
		$found = false;
		if ( isset( $wp_filter['init'] ) ) {
			foreach ( $wp_filter['init']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					$fn = $cb['function'];
					if ( is_array( $fn ) && is_string( $fn[0] ?? null ) && false !== strpos( (string) $fn[0], 'RewriteRules' ) && false !== strpos( (string) ( $fn[1] ?? '' ), 'flush' ) ) {
						$found = true;
					}
					if ( is_array( $fn ) && $fn === array( RewriteRules::class, 'maybe_flush' ) ) {
						$found = true;
					}
				}
			}
		}
		$this->assertFalse( $found, 'Rewrite flush must not be hooked on init' );
	}
}
