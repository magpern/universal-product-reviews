<?php
/**
 * M2 unit tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit\M2;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Email\LoggingMailTransport;
use UniversalProductReviews\Email\MailTransportFactory;
use UniversalProductReviews\Email\WpMailTransport;
use UniversalProductReviews\Invitations\ProductReviewability;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Tokens\SessionCookie;
use UniversalProductReviews\Tokens\TokenHasher;

final class TokenHasherTest extends TestCase {

	public function test_hash_is_deterministic_hmac(): void {
		$raw  = 'test-raw-token-value';
		$hash = TokenHasher::hash( $raw );
		$this->assertSame( 64, strlen( $hash ) );
		$this->assertTrue( TokenHasher::equals( $raw, $hash ) );
		$this->assertFalse( TokenHasher::equals( 'other', $hash ) );
	}

	public function test_generate_raw_entropy(): void {
		$a = TokenHasher::generate_raw();
		$b = TokenHasher::generate_raw();
		$this->assertNotSame( $a, $b );
		$this->assertGreaterThanOrEqual( 40, strlen( $a ) );
	}
}

final class ScheduleStatesTest extends TestCase {

	public function test_terminal_states(): void {
		$this->assertTrue( ScheduleStates::is_terminal( ScheduleStates::COMPLETED ) );
		$this->assertTrue( ScheduleStates::is_terminal( ScheduleStates::SUPPRESSED ) );
		$this->assertFalse( ScheduleStates::is_terminal( ScheduleStates::SCHEDULED ) );
		$this->assertFalse( ScheduleStates::is_terminal( ScheduleStates::INITIAL_SENDING ) );
	}
}

final class SessionCookieTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['upr_test_env'], $GLOBALS['upr_test_ssl'] );
		parent::tearDown();
	}

	public function test_production_requires_host_prefix(): void {
		$GLOBALS['upr_test_env'] = 'production';
		$GLOBALS['upr_test_ssl'] = false;
		$this->assertTrue( SessionCookie::use_host_prefix() );
		$this->assertSame( SessionCookie::HOST_NAME, SessionCookie::cookie_name() );
	}

	public function test_staging_requires_host_prefix(): void {
		$GLOBALS['upr_test_env'] = 'staging';
		$GLOBALS['upr_test_ssl'] = false;
		$this->assertTrue( SessionCookie::use_host_prefix() );
	}

	public function test_local_non_ssl_exception(): void {
		$GLOBALS['upr_test_env'] = 'local';
		$GLOBALS['upr_test_ssl'] = false;
		$this->assertFalse( SessionCookie::use_host_prefix() );
		$this->assertSame( SessionCookie::DEV_NAME, SessionCookie::cookie_name() );
	}

	public function test_local_ssl_uses_host_prefix(): void {
		$GLOBALS['upr_test_env'] = 'local';
		$GLOBALS['upr_test_ssl'] = true;
		$this->assertTrue( SessionCookie::use_host_prefix() );
	}
}

final class MailTransportFactoryTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['upr_test_env'] );
		parent::tearDown();
	}

	public function test_production_uses_wp_mail_transport(): void {
		$GLOBALS['upr_test_env'] = 'production';
		$this->assertInstanceOf( WpMailTransport::class, MailTransportFactory::make() );
	}

	public function test_non_production_uses_logging_transport(): void {
		$GLOBALS['upr_test_env'] = 'local';
		$this->assertInstanceOf( LoggingMailTransport::class, MailTransportFactory::make() );
	}
}

final class ProductReviewabilityTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['upr_test_posts'] );
		parent::tearDown();
	}

	public function test_published_product_reviewable(): void {
		$GLOBALS['upr_test_posts'][11] = (object) array(
			'ID'          => 11,
			'post_type'   => 'product',
			'post_status' => 'publish',
		);
		$this->assertTrue( ProductReviewability::is_reviewable( 11 ) );
	}

	public function test_draft_not_reviewable(): void {
		$GLOBALS['upr_test_posts'][12] = (object) array(
			'ID'          => 12,
			'post_type'   => 'product',
			'post_status' => 'draft',
		);
		$this->assertFalse( ProductReviewability::is_reviewable( 12 ) );
	}
}
