<?php
/**
 * Review link builder contract.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tokens;

defined( 'ABSPATH' ) || exit;

interface ReviewLinkBuilder {

	public function invite_exchange_url( string $raw_invite_token ): string;

	public function form_url(): string;
}
