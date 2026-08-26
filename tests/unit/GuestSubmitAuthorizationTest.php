<?php
/**
 * Unit tests for guest submit authorization context.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit\Submission;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Submission\GuestSubmitAuthorization;

final class GuestSubmitAuthorizationTest extends TestCase {

	protected function tearDown(): void {
		GuestSubmitAuthorization::clear();
		parent::tearDown();
	}

	public function test_disarmed_by_default(): void {
		$this->assertFalse( GuestSubmitAuthorization::is_armed() );
		$this->assertFalse( GuestSubmitAuthorization::allows_product( 1 ) );
	}

	public function test_arm_allows_exact_product_only(): void {
		GuestSubmitAuthorization::arm( 10, 20, 30, 'claim-token' );
		$this->assertTrue( GuestSubmitAuthorization::allows_product( 10 ) );
		$this->assertTrue( GuestSubmitAuthorization::allows_product( 10, 20 ) );
		$this->assertFalse( GuestSubmitAuthorization::allows_product( 11 ) );
		$this->assertFalse( GuestSubmitAuthorization::allows_product( 10, 99 ) );
		GuestSubmitAuthorization::clear();
		$this->assertFalse( GuestSubmitAuthorization::allows_product( 10 ) );
	}
}
