<?php
/**
 * M14 customer seven-day review edits — unit tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit\M14;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Admin\SupportExport;
use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\CustomerEdit\CustomerEditAuthorization;
use UniversalProductReviews\CustomerEdit\CustomerEditAvailability;
use UniversalProductReviews\CustomerEdit\CustomerEditClock;
use UniversalProductReviews\CustomerEdit\CustomerEditEligibility;
use UniversalProductReviews\CustomerEdit\CustomerEditGuard;
use UniversalProductReviews\CustomerEdit\EditClaimRepository;
use UniversalProductReviews\CustomerEdit\EditSessionService;
use UniversalProductReviews\Http\RewriteRules;
use UniversalProductReviews\Moderation\ApproveToHoldCas;

final class M14CustomerEditUnitTest extends TestCase {

	protected function tearDown(): void {
		CustomerEditAuthorization::clear();
		unset( $GLOBALS['upr_test_post_type'], $GLOBALS['upr_test_caps'], $GLOBALS['upr_test_verified_purchase'], $GLOBALS['upr_test_users'], $GLOBALS['upr_test_comments'] );
		parent::tearDown();
	}

	public function test_clock_inclusive_expiry(): void {
		$comment                   = new \WP_Comment();
		$comment->comment_date_gmt = '2026-08-24 12:00:00';
		$expiry                    = CustomerEditClock::expiry_unix( $comment );
		$this->assertSame( strtotime( '2026-08-24 12:00:00 UTC' ) + CustomerEditClock::WINDOW_SECONDS, $expiry );
		$this->assertTrue( CustomerEditClock::is_in_window( $comment, $expiry ) );
		$this->assertFalse( CustomerEditClock::is_in_window( $comment, $expiry + 1 ) );

		$missing                   = new \WP_Comment();
		$missing->comment_date_gmt = '0000-00-00 00:00:00';
		$this->assertNull( CustomerEditClock::expiry_unix( $missing ) );
		$this->assertFalse( CustomerEditClock::is_in_window( $missing ) );
	}

	public function test_status_matrix(): void {
		$hold                     = new \WP_Comment();
		$hold->comment_approved   = '0';
		$approve                  = new \WP_Comment();
		$approve->comment_approved = '1';
		$spam                     = new \WP_Comment();
		$spam->comment_approved   = 'spam';
		$trash                    = new \WP_Comment();
		$trash->comment_approved  = 'trash';
		$this->assertTrue( CustomerEditEligibility::status_allows_edit( $hold ) );
		$this->assertTrue( CustomerEditEligibility::status_allows_edit( $approve ) );
		$this->assertFalse( CustomerEditEligibility::status_allows_edit( $spam ) );
		$this->assertFalse( CustomerEditEligibility::status_allows_edit( $trash ) );
	}

	public function test_payload_allowlist_body_and_rating_only(): void {
		$handler = file_get_contents( dirname( __DIR__, 2 ) . '/src/Http/ReviewEditHandler.php' );
		$this->assertIsString( $handler );
		$this->assertStringContainsString( "'comment_ID'", $handler );
		$this->assertStringContainsString( "'comment_content'", $handler );
		$this->assertStringNotContainsString( "'comment_author'", $handler );
		$this->assertStringNotContainsString( "'comment_author_email'", $handler );
		$this->assertStringNotContainsString( "'_upr_order_item_id'", $handler );
		$this->assertStringNotContainsString( 'comment_author_url', $handler );
	}

	public function test_identity_stripping_without_changing_author_fields(): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		CustomerEditAuthorization::arm( 42, 'claim-token', 1 );
		$comment                         = new \WP_Comment();
		$comment->comment_ID             = 42;
		$comment->comment_post_ID        = 9;
		$comment->comment_parent         = 0;
		$comment->comment_type           = 'review';
		$comment->comment_approved       = '1';
		$comment->user_id                = 7;
		$comment->comment_content        = 'Original';
		$comment->comment_author         = 'Ada';
		$comment->comment_author_email   = 'ada@example.test';
		$comment->comment_author_url     = '';
		$comment->comment_date           = '2026-08-24 12:00:00';
		$comment->comment_date_gmt       = '2026-08-24 12:00:00';
		$out                             = CustomerEditGuard::filter_update_comment_data(
			array(
				'comment_content'      => 'Edited body',
				'comment_author'       => 'Attacker',
				'comment_author_email' => 'evil@example.test',
				'user_id'              => 999,
			),
			$comment,
			array()
		);
		$this->assertIsArray( $out );
		$this->assertSame( 'Edited body', $out['comment_content'] );
		$this->assertSame( 'Ada', $out['comment_author'] );
		$this->assertSame( 'ada@example.test', $out['comment_author_email'] );
		$this->assertSame( 7, (int) $out['user_id'] );
		$this->assertSame( '1', (string) $out['comment_approved'] );
	}

	public function test_c20_cannot_be_filter_forced(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/CustomerEdit/CustomerEditAvailability.php' );
		$this->assertIsString( $src );
		$this->assertStringNotContainsString( 'apply_filters', $src );
		add_filter(
			'upr_product_review_availability',
			static function ( $value ) {
				if ( is_array( $value ) ) {
					$value['can_submit'] = true;
				}
				return $value;
			}
		);
		$resolved = CustomerEditAvailability::resolve( 0, 0 );
		$this->assertFalse( $resolved['can_edit'] );
		$this->assertSame( 'not_eligible', $resolved['reason_code'] );
	}

	public function test_content_edited_in_failure_codes(): void {
		$this->assertTrue( PolicyAllowlist::is_failure_code( 'content_edited' ) );
		$this->assertContains( 'content_edited', PolicyAllowlist::FAILURE_CODES );
	}

	public function test_rewrite_order_edit_and_form_before_token(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/Http/RewriteRules.php' );
		$this->assertIsString( $src );
		$form  = strpos( $src, "add_rewrite_rule( '^upr-review/form/?$'" );
		$edit  = strpos( $src, "add_rewrite_rule( '^upr-review/edit/?$'" );
		$token = strpos( $src, "add_rewrite_rule( '^upr-review/([^/]+)/?$'" );
		$this->assertNotFalse( $form );
		$this->assertNotFalse( $edit );
		$this->assertNotFalse( $token );
		$this->assertLessThan( $token, $form );
		$this->assertLessThan( $token, $edit );
		$this->assertSame( '3', RewriteRules::VERSION );
	}

	public function test_e29_dispatcher_uses_unfiltered_hmac_lookup(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/CustomerEdit/InviteTokenDispatcher.php' );
		$this->assertIsString( $src );
		$this->assertStringContainsString( "find_by_raw( \$raw, 'invite' )", $src );
		$this->assertStringNotContainsString( 'find_active_by_raw', $src );
		$this->assertStringContainsString( 'issue_form_session_for_invite_row', $src );
		$this->assertStringContainsString( 'EditSessionService::issue_serialized', $src );
		$this->assertStringContainsString( "'kind' => 'deny'", $src );
	}

	public function test_e7_wp_error_without_arm(): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		$comment                       = new \WP_Comment();
		$comment->comment_ID           = 5;
		$comment->comment_post_ID      = 9;
		$comment->comment_parent       = 0;
		$comment->comment_type         = 'review';
		$comment->comment_content      = 'Body';
		$this->assertFalse( CustomerEditAuthorization::is_armed() );
		$result = CustomerEditGuard::filter_update_comment_data(
			array( 'comment_content' => 'Hijack' ),
			$comment,
			array()
		);
		$this->assertTrue( is_wp_error( $result ) );
		$this->assertSame( 'upr_edit_forbidden', $result->get_error_code() );
	}

	public function test_e30_cap_constant_and_parent_lock(): void {
		$this->assertSame( 10, EditSessionService::MAX_MINTS_PER_HOUR );
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/CustomerEdit/EditSessionService.php' );
		$this->assertIsString( $src );
		$this->assertStringContainsString( 'START TRANSACTION', $src );
		$this->assertStringContainsString( 'FOR UPDATE', $src );
		$this->assertStringContainsString( 'count_edit_sessions_in_rolling_hour', $src );
		$this->assertStringContainsString( 'revoke_edit_session_children', $src );
		$this->assertStringContainsString( 'SessionCookie::set', $src );
		$set_pos    = strpos( $src, 'SessionCookie::set' );
		$commit_pos = strpos( $src, "COMMIT'" );
		$this->assertNotFalse( $set_pos );
		$this->assertNotFalse( $commit_pos );
		$this->assertLessThan( $set_pos, $commit_pos );
	}

	public function test_hmac_is_sha256_and_never_raw_body(): void {
		$h = EditClaimRepository::hmac_body( 'canonical body' );
		$this->assertSame( 64, strlen( $h ) );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $h );
		$this->assertNotSame( 'canonical body', $h );
		$repo = file_get_contents( dirname( __DIR__, 2 ) . '/src/CustomerEdit/EditClaimRepository.php' );
		$this->assertIsString( $repo );
		$this->assertStringContainsString( 'hash_hmac( \'sha256\'', $repo );
		$this->assertStringContainsString( 'content_written', $repo );
		$this->assertStringContainsString( "AND NOT (phase = %s AND finalized_at IS NULL)", $repo );
	}

	public function test_approve_to_hold_cas_contract(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/Moderation/ApproveToHoldCas.php' );
		$this->assertIsString( $src );
		$this->assertStringContainsString( 'function cas_write', $src );
		$this->assertStringContainsString( 'function deliver_hooks_after_successful_cas', $src );
		$this->assertStringContainsString( 'SystemStatusOrigin::run', $src );
		$this->assertStringNotContainsString( 'AiActionOrigin', $src );
		$this->assertStringNotContainsString( 'wp_set_comment_status( $', $src );
		$this->assertStringContainsString( "do_action( 'wp_set_comment_status'", $src );
	}

	public function test_support_export_schema_unchanged(): void {
		$this->assertSame( 'upr-support-export/v1', SupportExport::SCHEMA_VERSION );
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/SupportExport.php' );
		$this->assertIsString( $src );
		$this->assertStringNotContainsString( 'upr-support-export/v2', $src );
		$this->assertStringNotContainsString( 'finalise_op_id', $src );
		$this->assertStringNotContainsString( 'upr_review_edit_claims', $src );
	}

	public function test_edit_url_not_on_public_builder_interface(): void {
		$iface = file_get_contents( dirname( __DIR__, 2 ) . '/src/Tokens/ReviewLinkBuilder.php' );
		$this->assertIsString( $iface );
		$this->assertStringNotContainsString( 'edit_url', $iface );
	}
}
