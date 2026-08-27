<?php
/**
 * Invitation email controls — unit coverage.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Admin\SettingsPage;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Invitations\InvitationAuthorisation;

final class InvitationEmailControlsUnitTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['upr_test_options']    = array();
		$GLOBALS['upr_test_filters']    = array();
		$GLOBALS['upr_test_transients'] = array();
		$GLOBALS['upr_test_caps']       = array();
	}

	protected function tearDown(): void {
		$GLOBALS['upr_test_options']    = array();
		$GLOBALS['upr_test_filters']    = array();
		$GLOBALS['upr_test_transients'] = array();
		$GLOBALS['upr_test_caps']       = array();
		parent::tearDown();
	}

	public function test_fresh_defaults_fail_closed(): void {
		$this->assertFalse( Options::invitation_emails_enabled() );
		$this->assertFalse( Options::invitation_emergency_pause() );

		$decision = InvitationAuthorisation::evaluate(
			array(
				'order_id'      => 1,
				'order_item_id' => 2,
				'product_id'    => 3,
				'operation'     => InvitationAuthorisation::OP_SCHEDULE,
			)
		);
		$this->assertSame( InvitationAuthorisation::DECISION_EMAIL_DISABLED, $decision['decision'] );
	}

	public function test_pause_precedes_enable_and_skips_host_filter(): void {
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMAILS_ENABLED ]  = 'yes';
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMERGENCY_PAUSE ] = 'yes';
		$host_called = false;
		add_filter(
			InvitationAuthorisation::FILTER,
			static function ( $decision ) use ( &$host_called ) {
				$host_called = true;
				$decision['decision'] = InvitationAuthorisation::DECISION_ALLOW;
				return $decision;
			}
		);

		$decision = InvitationAuthorisation::evaluate(
			array(
				'order_id'      => 1,
				'order_item_id' => 2,
				'product_id'    => 3,
				'operation'     => InvitationAuthorisation::OP_INITIAL_SEND,
			)
		);
		$this->assertSame( InvitationAuthorisation::DECISION_PAUSED, $decision['decision'] );
		$this->assertFalse( $host_called );
	}

	public function test_host_cannot_override_email_disabled(): void {
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMAILS_ENABLED ] = 'no';
		add_filter(
			InvitationAuthorisation::FILTER,
			static function ( $decision ) {
				$decision['decision'] = InvitationAuthorisation::DECISION_ALLOW;
				return $decision;
			}
		);
		$decision = InvitationAuthorisation::evaluate(
			array(
				'order_id'      => 1,
				'order_item_id' => 2,
				'product_id'    => 3,
				'operation'     => InvitationAuthorisation::OP_REMINDER_SEND,
			)
		);
		$this->assertSame( InvitationAuthorisation::DECISION_EMAIL_DISABLED, $decision['decision'] );
	}

	public function test_host_not_authorised_when_otherwise_allowed(): void {
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMAILS_ENABLED ] = 'yes';
		add_filter(
			InvitationAuthorisation::FILTER,
			static function () {
				return array(
					'decision'    => InvitationAuthorisation::DECISION_NOT_AUTHORISED,
					'reason_code' => 'pilot_order_not_allowlisted',
				);
			}
		);
		$decision = InvitationAuthorisation::evaluate(
			array(
				'order_id'      => 10,
				'order_item_id' => 20,
				'product_id'    => 30,
				'operation'     => InvitationAuthorisation::OP_SCHEDULE,
			)
		);
		$this->assertSame( InvitationAuthorisation::DECISION_NOT_AUTHORISED, $decision['decision'] );
		$this->assertSame( 'pilot_order_not_allowlisted', $decision['reason_code'] );
		$this->assertFalse( InvitationAuthorisation::is_allowed(
			array(
				'order_id'      => 10,
				'order_item_id' => 20,
				'product_id'    => 30,
				'operation'     => InvitationAuthorisation::OP_SCHEDULE,
			)
		) );
	}

	public function test_host_allow_when_enabled(): void {
		$GLOBALS['upr_test_options'][ Options::INVITATION_EMAILS_ENABLED ] = 'yes';
		$this->assertTrue(
			InvitationAuthorisation::is_allowed(
				array(
					'order_id'      => 1,
					'order_item_id' => 2,
					'product_id'    => 3,
					'operation'     => InvitationAuthorisation::OP_INITIAL_SEND,
				)
			)
		);
	}

	public function test_settings_sanitize_enabled_defaults_no(): void {
		$this->assertSame( 'no', SettingsPage::sanitize_enabled( null ) );
		$this->assertSame( 'no', SettingsPage::sanitize_enabled( 'no' ) );
		$this->assertSame( 'yes', SettingsPage::sanitize_enabled( 'yes' ) );
	}

	public function test_settings_capability_constant_is_manage_woocommerce(): void {
		// SettingsPage::add_menu registers manage_woocommerce; assert via reflection of menu registration intent.
		$ref  = new \ReflectionMethod( SettingsPage::class, 'add_menu' );
		$file = file_get_contents( (string) $ref->getFileName() );
		$this->assertNotFalse( $file );
		$this->assertStringContainsString( "'manage_woocommerce'", $file );
		$this->assertStringContainsString( 'Enable review invitation emails', $file );
		$this->assertStringContainsString( 'Emergency pause invitations', $file );
	}
}
