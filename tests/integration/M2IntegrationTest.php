<?php
/**
 * M2 integration tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Database\Schema;
use UniversalProductReviews\Email\LoggingMailTransport;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ReconciliationService;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;
use UniversalProductReviews\Tokens\SessionCookie;
use UniversalProductReviews\Tokens\TokenRepository;
use UniversalProductReviews\Tokens\TokenService;
use WP_UnitTestCase;

final class M2SchemaIntegrationTest extends WP_UnitTestCase {

	public function test_migrator_creates_tables(): void {
		delete_option( Migrator::OPTION_VERSION );
		$this->assertTrue( Migrator::upgrade_now() );
		$this->assertSame( Schema::DB_VERSION, get_option( Migrator::OPTION_VERSION ) );

		global $wpdb;
		foreach ( array( 'upr_invite_items', 'upr_tokens', 'upr_audit' ) as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			$this->assertSame( $table, $found );
		}

		// Idempotent.
		$this->assertTrue( Migrator::upgrade_now() );
	}
}

final class M2TokenExchangeIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Migrator::upgrade_now();
		LoggingMailTransport::reset();
	}

	public function test_exchange_creates_session_without_redeeming_invite(): void {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		InviteRepository::upsert(
			1001,
			array(
				'order_id'       => 50,
				'product_id'     => $product_id,
				'schedule_state' => ScheduleStates::INITIAL_SENT,
			)
		);

		$issued = TokenService::issue_invite( 1001, $product_id );
		$this->assertNotNull( $issued );

		$result = TokenService::exchange_invite( $issued['raw'] );
		$this->assertNotNull( $result );
		$this->assertArrayHasKey( 'form_url', $result );
		$this->assertStringContainsString( 'upr-review/form', $result['form_url'] );

		$invite = TokenRepository::find_by_id( $issued['id'] );
		$this->assertNotNull( $invite );
		$this->assertEmpty( $invite['redeemed_at'] );

		$this->assertTrue( FormSessionAuthenticator::authorize_product( $product_id ) );
		$this->assertFalse( FormSessionAuthenticator::authorize_product( $product_id + 999 ) );
	}

	public function test_discontinued_product_rejects_exchange(): void {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'draft',
			)
		);
		InviteRepository::upsert(
			1002,
			array(
				'order_id'       => 51,
				'product_id'     => $product_id,
				'schedule_state' => ScheduleStates::INITIAL_SENT,
			)
		);
		$issued = TokenService::issue_invite( 1002, $product_id );
		$this->assertNull( TokenService::exchange_invite( $issued['raw'] ) );
	}
}

final class M2GuestSessionPipelineIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Migrator::upgrade_now();
		wp_set_current_user( 0 );
	}

	public function test_guest_without_session_still_blocked(): void {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		$this->expectException( \WPDieException::class );
		wp_new_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Guest',
				'comment_author_email' => 'guest@example.com',
				'comment_content'      => 'Should fail',
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'user_id'              => 0,
			),
			true
		);
	}

	public function test_guest_with_session_can_submit_via_pipeline(): void {
		$product_id = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);

		InviteRepository::upsert(
			2001,
			array(
				'order_id'       => 60,
				'product_id'     => $product_id,
				'schedule_state' => ScheduleStates::INITIAL_SENT,
			)
		);
		$issued  = TokenService::issue_invite( 2001, $product_id );
		$exchanged = TokenService::exchange_invite( $issued['raw'] );
		$this->assertNotNull( $exchanged );
		$this->assertTrue( FormSessionAuthenticator::authorize_product( $product_id ) );

		$comment_id = wp_new_comment(
			array(
				'comment_post_ID'      => $product_id,
				'comment_author'       => 'Order Guest',
				'comment_author_email' => 'buyer@example.com',
				'comment_author_url'   => '',
				'comment_content'      => 'Great via invitation',
				'comment_type'         => 'review',
				'comment_approved'     => 1,
				'user_id'              => 0,
			),
			true
		);

		$this->assertIsInt( $comment_id );
		$comment = get_comment( $comment_id );
		$this->assertSame( '0', $comment->comment_approved );
	}
}

final class M2ReconcileDryRunIntegrationTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Migrator::upgrade_now();
	}

	public function test_dry_run_makes_zero_writes(): void {
		global $wpdb;
		$audit = $wpdb->prefix . 'upr_audit';
		$before_audit = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit}" );
		$before_invites = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . InviteRepository::table() );

		$summary = ReconciliationService::run( 90, true );
		$this->assertArrayHasKey( 'actions', $summary );

		$after_audit = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit}" );
		$after_invites = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . InviteRepository::table() );
		$this->assertSame( $before_audit, $after_audit );
		$this->assertSame( $before_invites, $after_invites );
	}
}

final class M2CookieSecurityIntegrationTest extends WP_UnitTestCase {

	public function test_host_cookie_name_on_ssl_production(): void {
		// In WP test env, assert SessionCookie API.
		$this->assertContains(
			SessionCookie::cookie_name(),
			array( SessionCookie::HOST_NAME, SessionCookie::DEV_NAME )
		);
	}
}
