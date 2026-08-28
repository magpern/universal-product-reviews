<?php
/**
 * AI shadow assessment eligibility (held top-level in-scope product reviews).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Moderation\ModerationAudit;
use UniversalProductReviews\Moderation\ReviewContext;

defined( 'ABSPATH' ) || exit;

final class Eligibility {

	/**
	 * @param \WP_Comment|int $comment Comment object or ID.
	 */
	public static function is_ai_assessable( $comment ): bool {
		if ( is_int( $comment ) ) {
			$loaded = get_comment( $comment );
			if ( ! $loaded ) {
				return false;
			}
			$comment = $loaded;
		}

		if ( ! ReviewContext::is_in_scope( $comment ) ) {
			return false;
		}

		$data = ReviewContext::comment_to_array( $comment );
		if ( (int) ( $data['comment_parent'] ?? 0 ) !== 0 ) {
			return false;
		}

		return self::is_held_status( self::approval_status( $comment ) );
	}

	/**
	 * Normalised held check (0 / hold / unapproved).
	 */
	public static function is_held_status( string $status ): bool {
		return 'hold' === ModerationAudit::normalise_status( $status );
	}

	/**
	 * @param \WP_Comment|object|array<string, mixed> $comment Comment.
	 */
	public static function approval_status( $comment ): string {
		if ( is_array( $comment ) ) {
			return isset( $comment['comment_approved'] ) ? (string) $comment['comment_approved'] : '';
		}
		if ( $comment instanceof \WP_Comment ) {
			return (string) $comment->comment_approved;
		}
		if ( is_object( $comment ) && isset( $comment->comment_approved ) ) {
			return (string) $comment->comment_approved;
		}
		return '';
	}
}
