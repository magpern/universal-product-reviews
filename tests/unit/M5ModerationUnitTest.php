<?php
/**
 * M5 unit coverage: context, origin marker, audit classification, staff-reply policy.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit\Moderation;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Moderation\ModerationAudit;
use UniversalProductReviews\Moderation\ReviewContext;
use UniversalProductReviews\Moderation\ReviewModeration;
use UniversalProductReviews\Moderation\StaffReplyPolicy;
use UniversalProductReviews\Moderation\SystemStatusOrigin;

final class ReviewContextUnitTest extends TestCase {

	protected function tearDown(): void {
		unset( $GLOBALS['upr_test_post_type'], $GLOBALS['upr_test_comment_meta'] );
		parent::tearDown();
	}

	public function test_source_matrix_invitation_via_meta(): void {
		$GLOBALS['upr_test_post_type'] = 'product';
		$comment                       = array(
			'comment_ID'      => 11,
			'comment_post_ID' => 5,
			'comment_type'    => 'review',
		);
		$GLOBALS['upr_test_comment_meta'][11]['_upr_order_item_id'] = 99;
		$this->assertSame( ReviewContext::SOURCE_INVITATION, ReviewContext::source_key( $comment, null, 99 ) );
		$this->assertSame( ReviewContext::LABEL_INVITATION, ReviewContext::source_label( ReviewContext::SOURCE_INVITATION ) );
	}

	public function test_source_matrix_invitation_via_invite_row(): void {
		$comment = array(
			'comment_ID'      => 12,
			'comment_post_ID' => 5,
			'comment_type'    => 'review',
		);
		$invite  = array(
			'review_comment_id' => 12,
			'order_item_id'     => 88,
			'order_id'          => 7,
		);
		$this->assertSame( ReviewContext::SOURCE_INVITATION, ReviewContext::source_key( $comment, $invite, 0 ) );
	}

	public function test_source_matrix_unlinked(): void {
		$comment = array(
			'comment_ID'      => 13,
			'comment_post_ID' => 5,
			'comment_type'    => 'review',
		);
		$this->assertSame( ReviewContext::SOURCE_UNLINKED, ReviewContext::source_key( $comment, null, 0 ) );
		$this->assertSame( ReviewContext::LABEL_UNLINKED, ReviewContext::source_label( ReviewContext::SOURCE_UNLINKED ) );
	}

	public function test_dual_linked_still_invitation(): void {
		$comment = array(
			'comment_ID'      => 14,
			'comment_post_ID' => 5,
			'comment_type'    => 'review',
		);
		$invite  = array( 'review_comment_id' => 14, 'order_item_id' => 1 );
		$this->assertSame( ReviewContext::SOURCE_INVITATION, ReviewContext::source_key( $comment, $invite, 1 ) );
	}
}

final class SystemStatusOriginUnitTest extends TestCase {

	protected function tearDown(): void {
		SystemStatusOrigin::reset_for_tests();
		parent::tearDown();
	}

	public function test_nesting_safe_depth(): void {
		$this->assertFalse( SystemStatusOrigin::is_active() );
		$inner_active = false;
		$result       = SystemStatusOrigin::run(
			static function () use ( &$inner_active ) {
				$inner_active = SystemStatusOrigin::is_active();
				return SystemStatusOrigin::run(
					static function () {
						return SystemStatusOrigin::is_active() ? 'ok' : 'fail';
					}
				);
			}
		);
		$this->assertTrue( $inner_active );
		$this->assertSame( 'ok', $result );
		$this->assertFalse( SystemStatusOrigin::is_active() );
	}

	public function test_finally_restores_on_throw(): void {
		try {
			SystemStatusOrigin::run(
				static function () {
					throw new \RuntimeException( 'boom' );
				}
			);
			$this->fail( 'expected exception' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertFalse( SystemStatusOrigin::is_active() );
	}
}

final class ModerationAuditClassifyUnitTest extends TestCase {

	protected function tearDown(): void {
		SystemStatusOrigin::reset_for_tests();
		unset( $GLOBALS['upr_test_caps'], $GLOBALS['upr_test_is_admin'] );
		parent::tearDown();
	}

	public function test_operator_event(): void {
		$GLOBALS['upr_test_is_admin'] = true;
		$GLOBALS['upr_test_caps']     = array( 'moderate_comments' => true );
		$this->assertSame( ModerationAudit::EVENT_STATUS_CHANGED, ModerationAudit::classify_event( 'approve' ) );
		$this->assertSame( 'operator', ModerationAudit::classify_origin( 'approve' ) );
	}

	public function test_system_spam_event(): void {
		SystemStatusOrigin::run(
			function () {
				$this->assertSame( ModerationAudit::EVENT_SYSTEM_SPAM, ModerationAudit::classify_event( 'spam' ) );
				$this->assertSame( 'upr_system', ModerationAudit::classify_origin( 'spam' ) );
			}
		);
	}

	public function test_external_system_event(): void {
		$GLOBALS['upr_test_is_admin'] = false;
		$GLOBALS['upr_test_caps']     = array();
		$this->assertSame( ModerationAudit::EVENT_SYSTEM_STATUS_CHANGED, ModerationAudit::classify_event( 'approve' ) );
		$this->assertSame( 'external_system', ModerationAudit::classify_origin( 'approve' ) );
	}

	public function test_normalise_status(): void {
		$this->assertSame( 'hold', ModerationAudit::normalise_status( '0' ) );
		$this->assertSame( 'approve', ModerationAudit::normalise_status( '1' ) );
		$this->assertSame( 'spam', ModerationAudit::normalise_status( 'spam' ) );
	}
}

final class StaffReplyPolicyUnitTest extends TestCase {

	protected function tearDown(): void {
		unset(
			$GLOBALS['upr_test_post_type'],
			$GLOBALS['upr_test_caps'],
			$GLOBALS['upr_test_comments'],
			$GLOBALS['upr_test_nonce_ok'],
			$_REQUEST
		);
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
			// Cannot undefine; tests set request action carefully.
		}
		parent::tearDown();
	}

	public function test_missing_nonce_fails_closed(): void {
		$_REQUEST = array( 'action' => 'replyto-comment' );
		$GLOBALS['upr_test_doing_ajax'] = true;
		$GLOBALS['upr_test_caps']       = array(
			'moderate_comments' => true,
			'edit_post'         => true,
		);
		$GLOBALS['upr_test_nonce_ok']   = false;
		$this->assertFalse(
			StaffReplyPolicy::is_validated_staff_reply(
				array(
					'comment_post_ID' => 1,
					'comment_parent'  => 2,
					'comment_type'    => 'review',
				)
			)
		);
	}

	public function test_frontend_action_fails_closed(): void {
		$_REQUEST = array( 'action' => '' );
		$GLOBALS['upr_test_doing_ajax'] = false;
		$this->assertFalse( StaffReplyPolicy::is_exact_native_reply_request() );
	}

	public function test_hold_still_applies_without_staff_reply(): void {
		$GLOBALS['upr_test_post_type']  = 'product';
		$GLOBALS['upr_test_doing_ajax'] = false;
		$_REQUEST                       = array();
		$result                         = ReviewModeration::hold_new_product_reviews(
			1,
			array(
				'comment_post_ID' => 10,
				'comment_type'    => 'review',
				'comment_parent'  => 0,
			)
		);
		$this->assertSame( 0, $result );
	}
}

final class StatusApiPolicyUnitTest extends TestCase {

	public function test_direct_status_apis_only_in_system_status_origin(): void {
		$root           = dirname( __DIR__, 2 );
		$allowlist_file = $root . '/scripts/ci/status-api-allowlist.txt';
		$this->assertFileExists( $allowlist_file );

		$allowed = array();
		foreach ( file( $allowlist_file, FILE_IGNORE_NEW_LINES ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || str_starts_with( $line, '#' ) ) {
				continue;
			}
			$allowed[] = $line;
		}

		$pattern  = '/wp_set_comment_status|wp_spam_comment|wp_unspam_comment|wp_trash_comment|wp_untrash_comment/';
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS )
		);
		$violations = array();
		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$rel = 'src/' . str_replace( '\\', '/', substr( $file->getPathname(), strlen( $root . '/src/' ) ) );
			if ( 'src/Moderation/SystemStatusOrigin.php' === $rel || in_array( $rel, $allowed, true ) ) {
				continue;
			}
			$contents = (string) file_get_contents( $file->getPathname() );
			if ( preg_match( $pattern, $contents ) ) {
				$violations[] = $rel;
			}
		}
		$this->assertSame( array(), $violations, 'direct status APIs outside SystemStatusOrigin' );
	}

	public function test_policy_detects_probe_file(): void {
		$root = dirname( __DIR__, 2 );
		$tmp  = $root . '/src/Moderation/_upr_ci_probe_status.php';
		file_put_contents( $tmp, "<?php\nwp_set_comment_status( 1, 'spam' );\n" );
		$cmd = 'bash ' . escapeshellarg( $root . '/scripts/ci/check.sh' ) . ' 2>&1';
		exec( $cmd, $output, $code );
		@unlink( $tmp );
		$joined = implode( "\n", $output );
		$this->assertNotSame( 0, $code, $joined );
		$this->assertStringContainsString( 'direct comment status API', $joined );
	}
}
