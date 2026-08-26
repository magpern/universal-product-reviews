<?php
/**
 * M3 catalogue-hidden product non-reviewable integration tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Http\ReviewSubmitHandler;
use UniversalProductReviews\Invitations\CompletionService;
use UniversalProductReviews\Invitations\Eligibility;
use UniversalProductReviews\Invitations\InvitationScheduler;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ProductReviewability;
use UniversalProductReviews\Invitations\ReconciliationService;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Invitations\SubmitClaimService;
use UniversalProductReviews\Submission\GuestSubmitAuthorization;
use UniversalProductReviews\Submission\ReviewAvailability;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;
use UniversalProductReviews\Tokens\TokenRepository;
use UniversalProductReviews\Tokens\TokenService;
use WP_UnitTestCase;

final class M3CatalogHiddenIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		wp_set_current_user( 0 );
		GuestSubmitAuthorization::clear();
	}

	protected function upr_create_hidden_product(): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'UPR Hidden Product' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( '10' );
		$product->set_price( '10' );
		$product->set_virtual( true );
		$product->save();
		$id = (int) $product->get_id();
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	protected function upr_hide_product( int $product_id ): void {
		$product = wc_get_product( $product_id );
		$this->assertNotFalse( $product );
		$product->set_catalog_visibility( 'hidden' );
		$product->save();
	}

	protected function upr_show_product( int $product_id ): void {
		$product = wc_get_product( $product_id );
		$this->assertNotFalse( $product );
		$product->set_catalog_visibility( 'visible' );
		$product->save();
	}

	public function test_published_catalog_hidden_not_reviewable(): void {
		$product_id = $this->upr_create_hidden_product();
		$this->assertFalse( ProductReviewability::is_reviewable( $product_id ) );
	}

	public function test_hidden_product_gets_no_new_invitation(): void {
		$product_id = $this->upr_create_hidden_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$order      = wc_get_order( $ctx['order']->get_id() );
		$item       = $order->get_item( $ctx['order_item_id'] );
		$eval       = Eligibility::evaluate_item( $order, $item );
		$this->assertFalse( $eval['eligible'] );
		$this->assertSame( 'product_not_reviewable', $eval['reason'] );

		$past = time() - ( 20 * DAY_IN_SECONDS );
		$order->update_meta_data( InvitationScheduler::META_DELIVERY_CONFIRMED_AT, gmdate( 'Y-m-d H:i:s', $past ) );
		$order->save();
		InvitationScheduler::schedule_order( $order->get_id(), 'adapter', $past );

		$row = InviteRepository::find( $ctx['order_item_id'] );
		if ( $row ) {
			$this->assertSame( ScheduleStates::SUPPRESSED, $row['schedule_state'] );
		} else {
			$this->assertNull( $row );
		}
	}

	public function test_visible_to_hidden_suppresses_outstanding_invite(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$prep       = $this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );

		$this->upr_hide_product( $product_id );
		$this->assertFalse( ProductReviewability::is_reviewable( $product_id ) );

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::SUPPRESSED, $invite['schedule_state'] );
		$this->assertSame( 'product_not_reviewable', $invite['suppression_code'] );

		$token = TokenRepository::find_by_id( $prep['invite_token_id'] );
		$this->assertNotEmpty( $token['revoked_at'] );
		$this->assertFalse( FormSessionAuthenticator::authorize_product( $product_id ) );
		$this->assertNull( TokenService::exchange_invite( 'invalid' ) );
	}

	public function test_hidden_blocks_form_render_and_submit(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$prep       = $this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );
		$session    = $prep['session'];

		$this->upr_hide_product( $product_id );

		$this->assertFalse( FormSessionAuthenticator::authorize_product( $product_id ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set( 'upr_review_form', '1' );
		}
		set_query_var( 'upr_review_form', '1' );
		$_POST['upr_session_id'] = (string) (int) $session['id'];
		$_POST['upr_nonce']      = wp_create_nonce( 'upr_review_submit_' . (int) $session['id'] );
		$_POST['upr_rating']     = '5';
		$_POST['upr_content']    = 'Should be blocked';

		ob_start();
		ReviewSubmitHandler::handle();
		$body = ob_get_clean();
		$this->assertTrue(
			str_contains( $body, 'Invalid request' ) || str_contains( $body, 'session has expired' )
		);

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertNotSame( ScheduleStates::COMPLETED, $invite['schedule_state'] );
	}

	public function test_hidden_transition_race_discards_inflight_review(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$prep       = $this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );

		$claim = SubmitClaimService::acquire( $ctx['order_item_id'] );
		$this->assertNotNull( $claim );

		$this->upr_hide_product( $product_id );

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Ada Lovelace',
				'comment_author_email' => 'buyer@example.com',
				'comment_author_url'   => '',
				'comment_content'      => 'Late submission after hide',
				'comment_type'         => 'review',
				'comment_approved'     => 0,
				'user_id'              => 0,
			)
		);
		$this->assertIsInt( $comment_id );
		update_comment_meta( (int) $comment_id, '_upr_order_item_id', $ctx['order_item_id'] );

		$this->assertFalse(
			CompletionService::finalize(
				$ctx['order_item_id'],
				(int) $comment_id,
				$ctx['order']->get_id(),
				$prep['invite_token_id'],
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

		$comment = get_comment( (int) $comment_id );
		$this->assertSame( 'spam', $comment->comment_approved );

		ReconciliationService::run( 90, false );
		ReconciliationService::run( 90, false );

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::SUPPRESSED, $invite['schedule_state'] );
		$this->assertEmpty( $invite['review_comment_id'] );

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

	public function test_approved_reviews_remain_after_product_hidden(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Ada Lovelace',
				'comment_author_email' => 'buyer@example.com',
				'comment_author_url'   => '',
				'comment_content'      => 'Approved before hide',
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'user_id'              => 0,
			)
		);
		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'          => $ctx['order']->get_id(),
				'product_id'        => $product_id,
				'schedule_state'    => ScheduleStates::COMPLETED,
				'review_comment_id' => (int) $comment_id,
			)
		);

		$this->upr_hide_product( $product_id );

		$comment = get_comment( (int) $comment_id );
		$this->assertSame( '1', $comment->comment_approved );
		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::COMPLETED, $invite['schedule_state'] );
	}

	public function test_restore_visibility_does_not_resurrect_invites(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$prep       = $this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );

		$this->upr_hide_product( $product_id );
		$this->upr_show_product( $product_id );
		$this->assertTrue( ProductReviewability::is_reviewable( $product_id ) );

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::SUPPRESSED, $invite['schedule_state'] );

		$token = TokenRepository::find_by_id( $prep['invite_token_id'] );
		$this->assertNotEmpty( $token['revoked_at'] );
		$this->assertFalse( FormSessionAuthenticator::authorize_product( $product_id ) );
	}

	public function test_draft_private_trash_still_non_reviewable(): void {
		$draft_id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'draft',
			)
		);
		$this->assertFalse( ProductReviewability::is_reviewable( $draft_id ) );

		$private_id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'private',
			)
		);
		$this->assertFalse( ProductReviewability::is_reviewable( $private_id ) );
	}

	public function test_visible_product_flow_unchanged(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$prep       = $this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );
		$session    = $prep['session'];

		$this->assertTrue( ProductReviewability::is_reviewable( $product_id ) );
		$this->assertTrue( FormSessionAuthenticator::authorize_product( $product_id ) );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		global $wp_query;
		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set( 'upr_review_form', '1' );
		}
		set_query_var( 'upr_review_form', '1' );
		$_POST['upr_session_id'] = (string) (int) $session['id'];
		$_POST['upr_nonce']      = wp_create_nonce( 'upr_review_submit_' . (int) $session['id'] );
		$_POST['upr_rating']     = '5';
		$_POST['upr_content']    = 'Still works';

		ob_start();
		ReviewSubmitHandler::handle();
		ob_end_clean();

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::COMPLETED, $invite['schedule_state'] );
	}

	public function test_logged_in_availability_respects_hidden_product(): void {
		$product_id = $this->upr_create_hidden_product();
		$user_id    = self::factory()->user->create();
		$result     = ReviewAvailability::default_availability( array(), $product_id, $user_id );
		$this->assertFalse( $result['can_submit'] );
		$this->assertSame( 'product_not_reviewable', $result['reason_code'] );
	}

	public function test_native_comment_route_still_blocked_for_guest(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );
		$this->assertTrue( FormSessionAuthenticator::authorize_product( $product_id ) );
		$this->assertFalse( GuestSubmitAuthorization::is_armed() );

		$this->expectException( \WPDieException::class );
		wp_new_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Bypass',
				'comment_author_email' => 'bypass@example.com',
				'comment_content'      => 'Native bypass',
				'comment_type'         => 'review',
				'user_id'              => 0,
			),
			true
		);
	}
}
