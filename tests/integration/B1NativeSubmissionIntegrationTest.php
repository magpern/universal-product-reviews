<?php
/**
 * B1 native submission enforcement — integration tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Http\ReviewSubmitHandler;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Submission\GuestSubmitAuthorization;
use UniversalProductReviews\Submission\NativePdpForm;
use UniversalProductReviews\Submission\NativeSubmissionGuard;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;
use WP_UnitTestCase;

final class B1NativeSubmissionIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	/** @var string */
	private $prev_verification;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		$this->prev_verification = (string) get_option( 'woocommerce_review_rating_verification_required', 'no' );
		update_option( 'woocommerce_review_rating_verification_required', 'yes' );
		update_option( 'woocommerce_enable_reviews', 'yes' );
		wp_set_current_user( 0 );
		GuestSubmitAuthorization::clear();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	public function tear_down(): void {
		GuestSubmitAuthorization::clear();
		wp_set_current_user( 0 );
		update_option( 'woocommerce_review_rating_verification_required', $this->prev_verification );
		parent::tear_down();
	}

	private function comment_count( int $product_id ): int {
		return (int) get_comments(
			array(
				'post_id' => $product_id,
				'count'   => true,
			)
		);
	}

	private function attempt_native_review( int $product_id, int $user_id, string $content ): void {
		wp_set_current_user( $user_id );
		$_POST['comment_post_ID'] = (string) $product_id;
		$_POST['rating']          = '5';
		$_POST['comment']         = $content;
		try {
			wp_new_comment(
				array(
					'comment_post_ID'      => $product_id,
					'comment_author'       => 'Reviewer',
					'comment_author_email' => 'reviewer@example.com',
					'comment_author_url'   => '',
					'comment_content'      => $content,
					'comment_type'         => 'review',
					'user_ID'              => $user_id,
					'user_id'              => $user_id,
				),
				true
			);
		} finally {
			unset( $_POST['comment_post_ID'], $_POST['rating'], $_POST['comment'] );
		}
	}

	public function test_guest_native_without_arm_denied(): void {
		$product_id = $this->upr_create_product();
		$before     = $this->comment_count( $product_id );

		$died = false;
		try {
			$this->attempt_native_review( $product_id, 0, 'Guest native attempt' );
		} catch ( \WPDieException $e ) {
			$died = true;
			$this->assertNotEmpty( $e->getMessage() );
		}

		$this->assertTrue( $died );
		$this->assertSame( $before, $this->comment_count( $product_id ) );
	}

	public function test_guest_session_without_arm_denied_on_native_route(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );
		$this->assertTrue( FormSessionAuthenticator::authorize_product( $product_id ) );
		$this->assertFalse( GuestSubmitAuthorization::is_armed() );
		$before = $this->comment_count( $product_id );

		$died = false;
		try {
			$this->attempt_native_review( $product_id, 0, 'Session alone bypass' );
		} catch ( \WPDieException $e ) {
			$died = true;
		}

		$this->assertTrue( $died );
		$this->assertSame( $before, $this->comment_count( $product_id ) );
	}

	public function test_armed_m2_submit_still_allowed_with_order_identity(): void {
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
		$_POST['upr_session_id']       = (string) (int) $session['id'];
		$_POST['upr_nonce']            = wp_create_nonce( 'upr_review_submit_' . (int) $session['id'] );
		$_POST['upr_rating']           = '5';
		$_POST['upr_content']          = 'Excellent product via invitation B1';
		$_POST['comment_author']       = 'Should Be Ignored';
		$_POST['comment_author_email'] = 'ignored@evil.example';

		ob_start();
		ReviewSubmitHandler::handle();
		ob_end_clean();

		$invite = InviteRepository::find( $ctx['order_item_id'] );
		$this->assertSame( ScheduleStates::COMPLETED, $invite['schedule_state'] );
		$comment = get_comment( (int) $invite['review_comment_id'] );
		$this->assertNotFalse( $comment );
		$this->assertSame( 'buyer@example.com', $comment->comment_author_email );
		$this->assertSame( '0', (string) $comment->comment_approved, 'moderation hold unchanged' );
	}

	public function test_logged_in_non_purchaser_denied(): void {
		$product_id = $this->upr_create_product();
		$user_id    = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'nonbuyer-b1@example.com',
			)
		);
		$before = $this->comment_count( $product_id );

		$died = false;
		try {
			$this->attempt_native_review( $product_id, $user_id, 'Non purchaser review' );
		} catch ( \WPDieException $e ) {
			$died = true;
		}

		$this->assertTrue( $died );
		$this->assertSame( $before, $this->comment_count( $product_id ) );
		$this->assertFalse( NativePdpForm::should_render( $product_id ) );
	}

	public function test_logged_in_verified_purchaser_allowed_with_hold(): void {
		$product_id = $this->upr_create_product();
		$user_id    = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'buyer-b1@example.com',
			)
		);
		$this->upr_grant_purchase( $user_id, $product_id );
		wp_set_current_user( $user_id );
		$this->assertTrue( NativePdpForm::should_render( $product_id ) );

		$this->attempt_native_review( $product_id, $user_id, 'Verified purchaser review B1' );

		$comments = get_comments(
			array(
				'post_id' => $product_id,
				'type'    => 'review',
				'status'  => 'all',
			)
		);
		$this->assertNotEmpty( $comments );
		$comment = $comments[0];
		$this->assertSame( '0', (string) $comment->comment_approved, 'moderation hold unchanged' );
	}

	public function test_catalog_hidden_denied_for_guest_and_verified_purchaser(): void {
		$product_id = $this->upr_create_hidden_product();
		$user_id    = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'buyer-hidden-b1@example.com',
			)
		);
		$this->upr_grant_purchase( $user_id, $product_id );

		$before = $this->comment_count( $product_id );

		$guest_died = false;
		try {
			$this->attempt_native_review( $product_id, 0, 'Guest on hidden' );
		} catch ( \WPDieException $e ) {
			$guest_died = true;
		}
		$this->assertTrue( $guest_died );

		$buyer_died = false;
		try {
			$this->attempt_native_review( $product_id, $user_id, 'Buyer on hidden' );
		} catch ( \WPDieException $e ) {
			$buyer_died = true;
		}
		$this->assertTrue( $buyer_died );
		$this->assertSame( $before, $this->comment_count( $product_id ) );

		wp_set_current_user( 0 );
		$this->assertFalse( NativePdpForm::should_render( $product_id ) );
		wp_set_current_user( $user_id );
		$this->assertFalse( NativePdpForm::should_render( $product_id ) );
	}

	public function test_display_helper_guest_with_form_session_is_false(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, $ctx['order']->get_id() );
		wp_set_current_user( 0 );
		$this->assertTrue( FormSessionAuthenticator::authorize_product( $product_id ) );
		$this->assertFalse( NativePdpForm::should_render( $product_id ) );
	}

	public function test_no_comments_open_filter_registered(): void {
		$this->assertFalse(
			has_filter( 'comments_open', array( NativeSubmissionGuard::class, 'reject_unavailable_product_reviews' ) )
		);
		global $wp_filter;
		$callbacks = $wp_filter['comments_open']->callbacks ?? array();
		foreach ( $callbacks as $priority => $group ) {
			foreach ( $group as $entry ) {
				$fn = $entry['function'] ?? null;
				if ( is_array( $fn ) && is_string( $fn[0] ?? null ) && str_starts_with( $fn[0], 'UniversalProductReviews\\' ) ) {
					$this->fail( 'UPR must not register comments_open callbacks; found ' . $fn[0] );
				}
				if ( is_array( $fn ) && is_object( $fn[0] ?? null ) && str_starts_with( get_class( $fn[0] ), 'UniversalProductReviews\\' ) ) {
					$this->fail( 'UPR must not register comments_open callbacks; found ' . get_class( $fn[0] ) );
				}
			}
		}
		$this->assertTrue( true );
	}

	private function upr_grant_purchase( int $user_id, int $product_id ): void {
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

	private function upr_create_hidden_product(): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'UPR Hidden Product B1' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( '10' );
		$product->set_price( '10' );
		$product->set_virtual( true );
		$product->save();
		return (int) $product->get_id();
	}
}
