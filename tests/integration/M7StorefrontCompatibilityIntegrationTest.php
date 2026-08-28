<?php
/**
 * M7 storefront contract characterization — integration tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Http\ReviewFormEndpoint;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Submission\GuestSubmissionGuard;
use UniversalProductReviews\Submission\NativePdpForm;
use UniversalProductReviews\Submission\NativeSubmissionGuard;
use UniversalProductReviews\Submission\ReviewAvailability;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;
use UniversalProductReviews\Tokens\TokenService;
use WP_UnitTestCase;

final class M7StorefrontCompatibilityIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	/** @var string */
	private $prev_verification;

	/** @var string */
	private $prev_reviews;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
		$this->prev_verification = (string) get_option( 'woocommerce_review_rating_verification_required', 'no' );
		$this->prev_reviews      = (string) get_option( 'woocommerce_enable_reviews', 'yes' );
		update_option( 'woocommerce_review_rating_verification_required', 'yes' );
		update_option( 'woocommerce_enable_reviews', 'yes' );
		wp_set_current_user( 0 );
	}

	public function tear_down(): void {
		remove_all_filters( 'upr_product_review_availability' );
		wp_set_current_user( 0 );
		update_option( 'woocommerce_review_rating_verification_required', $this->prev_verification );
		update_option( 'woocommerce_enable_reviews', $this->prev_reviews );
		parent::tear_down();
	}

	public function test_resolve_fail_closed_on_malformed_filter(): void {
		add_filter(
			'upr_product_review_availability',
			static fn() => 'not-an-array',
			999,
			3
		);
		$result = ReviewAvailability::resolve( 1, 1 );
		$this->assertFalse( $result['can_submit'] );
	}

	public function test_verified_purchaser_c9_c10_align_with_enforcement(): void {
		$product_id = $this->upr_create_product();
		$user_id    = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'm7-buyer@example.com',
			)
		);
		$this->upr_grant_purchase( $user_id, $product_id );
		wp_set_current_user( $user_id );

		$availability = ReviewAvailability::resolve( $product_id, $user_id );
		$this->assertTrue( ReviewAvailability::is_allowed( $availability ) );
		$this->assertTrue( NativePdpForm::should_render( $product_id ) );
		$this->assertTrue( ReviewAvailability::allows_submit( $product_id, $user_id ) );

		$approved = apply_filters(
			'pre_comment_approved',
			1,
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'M7 Buyer',
				'comment_author_email' => 'm7-buyer@example.com',
				'comment_content'      => 'Verified path.',
				'comment_type'         => 'review',
				'user_ID'              => $user_id,
			)
		);
		$this->assertSame( 0, $approved, 'moderation hold unchanged' );
	}

	public function test_reviews_disabled_c9_c10_deny(): void {
		update_option( 'woocommerce_enable_reviews', 'no' );
		$product_id = $this->upr_create_product();
		$user_id    = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $user_id );

		$availability = ReviewAvailability::resolve( $product_id, $user_id );
		$this->assertSame( 'reviews_disabled', $availability['reason_code'] );
		$this->assertFalse( NativePdpForm::should_render( $product_id ) );
	}

	public function test_catalog_hidden_c9_c10_deny(): void {
		$product_id = $this->upr_create_hidden_product();
		$user_id    = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'm7-hidden-buyer@example.com',
			)
		);
		$this->upr_grant_purchase( $user_id, $product_id );
		wp_set_current_user( $user_id );

		$availability = ReviewAvailability::resolve( $product_id, $user_id );
		$this->assertSame( 'product_not_reviewable', $availability['reason_code'] );
		$this->assertFalse( NativePdpForm::should_render( $product_id ) );
	}

	public function test_guest_native_c10_false_even_with_session(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		$this->upr_prepare_session_invite( $ctx['order_item_id'], $product_id, (int) $ctx['order']->get_id() );
		wp_set_current_user( 0 );

		$this->assertTrue( FormSessionAuthenticator::authorize_product( $product_id ) );
		$this->assertFalse( NativePdpForm::should_render( $product_id ) );
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
		$product->set_name( 'UPR Hidden Product M7' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_regular_price( '10' );
		$product->set_price( '10' );
		$product->set_virtual( true );
		$product->save();
		return (int) $product->get_id();
	}
}

final class M7SubmissionGuardBootstrapIntegrationTest extends WP_UnitTestCase {

	public function test_guest_and_native_guards_single_registration_at_expected_priorities(): void {
		$guest_priority = has_filter(
			'preprocess_comment',
			array( GuestSubmissionGuard::class, 'block_guest_product_reviews' )
		);
		$native_priority = has_filter(
			'preprocess_comment',
			array( NativeSubmissionGuard::class, 'reject_unavailable_product_reviews' )
		);

		$this->assertSame( GuestSubmissionGuard::FILTER_PRIORITY, (int) $guest_priority );
		$this->assertSame( NativeSubmissionGuard::FILTER_PRIORITY, (int) $native_priority );

		$wc_priority = has_filter( 'preprocess_comment', array( 'WC_Comments', 'update_comment_type' ) );
		$this->assertSame( 1, (int) $wc_priority );
		$this->assertLessThan( (int) $native_priority, (int) $guest_priority );
		$this->assertLessThan( (int) $wc_priority, (int) $guest_priority );

		global $wp_filter;
		$guest_count  = 0;
		$native_count = 0;
		if ( isset( $wp_filter['preprocess_comment'] ) ) {
			foreach ( $wp_filter['preprocess_comment']->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $fn ) {
					$cb = $fn['function'] ?? null;
					if ( is_array( $cb ) && GuestSubmissionGuard::class === ( $cb[0] ?? null ) && 'block_guest_product_reviews' === ( $cb[1] ?? null ) ) {
						++$guest_count;
					}
					if ( is_array( $cb ) && NativeSubmissionGuard::class === ( $cb[0] ?? null ) && 'reject_unavailable_product_reviews' === ( $cb[1] ?? null ) ) {
						++$native_count;
					}
				}
			}
		}
		$this->assertSame( 1, $guest_count, 'exactly one GuestSubmissionGuard registration' );
		$this->assertSame( 1, $native_count, 'exactly one NativeSubmissionGuard registration' );
	}

	public function test_no_comments_open_filter_from_upr(): void {
		global $wp_filter;
		if ( ! isset( $wp_filter['comments_open'] ) ) {
			$this->assertTrue( true );
			return;
		}
		foreach ( $wp_filter['comments_open']->callbacks as $callbacks ) {
			foreach ( $callbacks as $fn ) {
				$cb = $fn['function'] ?? null;
				if ( is_array( $cb ) && is_string( $cb[0] ?? null ) && str_starts_with( $cb[0], 'UniversalProductReviews\\' ) ) {
					$this->fail( 'UPR must not register comments_open callbacks' );
				}
				if ( is_array( $cb ) && is_object( $cb[0] ?? null ) && str_starts_with( get_class( $cb[0] ), 'UniversalProductReviews\\' ) ) {
					$this->fail( 'UPR must not register comments_open callbacks' );
				}
			}
		}
		$this->assertTrue( true );
	}
}

final class M7ReviewFormEndpointIntegrationTest extends WP_UnitTestCase {
	use M2TestHelpers;

	public function set_up(): void {
		parent::set_up();
		$this->upr_ensure_schema();
	}

	public function test_expired_session_returns_403_plain_language(): void {
		if ( function_exists( 'header_remove' ) ) {
			header_remove();
		}
		ob_start();
		ReviewFormEndpoint::handle_get();
		$html = (string) ob_get_clean();

		$this->assertSame( 403, http_response_code() );
		$this->assertStringContainsString( 'expired', strtolower( $html ) );
		$this->assertStringContainsString( '<h1', $html );
	}

	public function test_form_markup_accessibility_and_security_invariants(): void {
		$product_id = $this->upr_create_product();
		$ctx        = $this->upr_create_order_with_item( $product_id );
		InviteRepository::upsert(
			$ctx['order_item_id'],
			array(
				'order_id'        => (int) $ctx['order']->get_id(),
				'product_id'      => $product_id,
				'schedule_state'  => ScheduleStates::INITIAL_SENT,
				'initial_sent_at' => current_time( 'mysql', true ),
			)
		);
		$issued    = TokenService::issue_invite( $ctx['order_item_id'], $product_id );
		$this->assertNotNull( $issued );
		$raw_token = (string) $issued['raw'];
		$this->assertNotNull( TokenService::exchange_invite( $raw_token ) );
		$session = FormSessionAuthenticator::current_session();
		$this->assertNotNull( $session );
		$session_id = (int) $session['id'];

		if ( function_exists( 'header_remove' ) ) {
			header_remove();
		}
		ob_start();
		ReviewFormEndpoint::handle_get();
		$html = (string) ob_get_clean();

		$this->assertSame( 200, http_response_code() );
		$this->assertMatchesRegularExpression( '/<html[^>]*\blang=/', $html );
		$this->assertStringContainsString( '<h1', $html );
		$this->assertStringContainsString( '<fieldset>', $html );
		$this->assertStringContainsString( '<legend>', $html );
		$this->assertStringContainsString( 'for="upr_rating"', $html );
		$this->assertStringContainsString( 'id="upr_rating"', $html );
		$this->assertStringContainsString( 'for="upr_content"', $html );
		$this->assertStringContainsString( 'aria-required="true"', $html );
		$this->assertStringContainsString( 'name="upr_nonce"', $html );
		$this->assertStringContainsString( 'name="upr_session_id"', $html );
		$this->assertStringContainsString( 'value="' . $session_id . '"', $html );
		$this->assertStringContainsString( 'upr-review/form', $html );
		$this->assertStringNotContainsString( 'buyer@example.com', $html );
		$this->assertStringNotContainsString( (string) $ctx['order']->get_id(), $html );
		$this->assertStringNotContainsString( $raw_token, $html );
		$this->assertDoesNotMatchRegularExpression( '/upr-review\/[A-Za-z0-9_-]{20,}/', $html );

		$headers = headers_list();
		$joined  = implode( "\n", $headers );
		$this->assertStringContainsString( 'Referrer-Policy: no-referrer', $joined );
	}
}
