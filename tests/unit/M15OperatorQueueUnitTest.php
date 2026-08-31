<?php
/**
 * M15 operator queue unit / static policy tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Moderation\OperatorQueueKeepHold;

final class M15OperatorQueueUnitTest extends TestCase {

	public function test_ai_package_has_no_status_api_calls(): void {
		$root = dirname( __DIR__, 2 ) . '/src/Ai';
		$hits = array();
		$it   = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );
		foreach ( $it as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}
			$src = file_get_contents( $file->getPathname() );
			if ( false === $src ) {
				continue;
			}
			if ( preg_match( '/wp_set_comment_status\s*\(|wp_spam_comment\s*\(|wp_trash_comment\s*\(|wp_unspam_comment\s*\(|wp_untrash_comment\s*\(/', $src ) ) {
				$hits[] = $file->getPathname();
			}
		}
		$this->assertSame( array(), $hits, 'src/Ai must not call comment status APIs' );
	}

	public function test_keep_hold_source_has_no_status_api(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/src/Moderation/OperatorQueueKeepHold.php' );
		$this->assertIsString( $src );
		$this->assertDoesNotMatchRegularExpression(
			'/wp_set_comment_status\s*\(|wp_spam_comment\s*\(|wp_trash_comment\s*\(/',
			$src
		);
		$this->assertStringContainsString( "EVENT_DEFERRED = 'review.operator_deferred'", $src );
		$this->assertStringNotContainsString( 'review.operator_queue_decision', $src );
		$this->assertStringNotContainsString( "'assessment_id'", $src );
		$this->assertStringNotContainsString( "'policy_version'", $src );
	}

	public function test_queue_labels_forbid_deny_as_action(): void {
		$files = array(
			dirname( __DIR__, 2 ) . '/src/Moderation/CommentListEnhancements.php',
			dirname( __DIR__, 2 ) . '/src/Moderation/OperatorQueueKeepHold.php',
			dirname( __DIR__, 2 ) . '/src/Moderation/QueueAssessmentPresenter.php',
		);
		foreach ( $files as $file ) {
			$src = file_get_contents( $file );
			$this->assertIsString( $src );
			// Operator-facing Deny action label forbidden; internal "denied" in other packages ok.
			$this->assertDoesNotMatchRegularExpression(
				"/__\(\s*'Deny'|__\(\s*\"Deny\"|esc_html__\(\s*'Deny'/",
				$src,
				basename( $file ) . ' must not expose Deny as queue action'
			);
		}
	}

	public function test_deferred_event_constant(): void {
		$this->assertSame( 'review.operator_deferred', OperatorQueueKeepHold::EVENT_DEFERRED );
		$this->assertSame( 'upr_queue_keep_hold', OperatorQueueKeepHold::ACTION );
	}

	public function test_presenter_never_outputs_approve_wording_for_actions(): void {
		foreach ( array( 'needs_human', 'likely_publishable', 'likely_spam', 'likely_abuse', 'mandatory_human' ) as $action ) {
			$label = \UniversalProductReviews\Moderation\QueueAssessmentPresenter::overall_label( $action );
			$this->assertFalse(
				\UniversalProductReviews\Moderation\QueueAssessmentPresenter::label_contains_forbidden_approval_wording( $label ),
				$action
			);
		}
	}
}
