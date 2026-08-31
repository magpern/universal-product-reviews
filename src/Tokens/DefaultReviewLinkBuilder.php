<?php
/**
 * Default review link builder (no public filter receives raw token).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tokens;

defined( 'ABSPATH' ) || exit;

final class DefaultReviewLinkBuilder implements ReviewLinkBuilder {

	public function invite_exchange_url( string $raw_invite_token ): string {
		$path = 'upr-review/' . rawurlencode( $raw_invite_token ) . '/';
		return home_url( user_trailingslashit( $path ) );
	}

	public function form_url(): string {
		$base = apply_filters( 'upr_review_form_base_url', home_url( user_trailingslashit( 'upr-review/form' ) ) );
		return (string) $base;
	}

	public function edit_url(): string {
		return home_url( user_trailingslashit( 'upr-review/edit' ) );
	}

	public static function resolve(): ReviewLinkBuilder {
		$builder = apply_filters( 'upr_review_link_builder', new self() );
		return $builder instanceof ReviewLinkBuilder ? $builder : new self();
	}
}
