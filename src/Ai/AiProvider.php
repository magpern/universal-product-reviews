<?php
/**
 * C19 — selected AI provider enum (no secrets).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

final class AiProvider {

	public const LOCAL  = 'local';
	public const OPENAI = 'openai';

	/**
	 * Stable public contract: 'local' | 'openai'.
	 */
	public static function selected(): string {
		return Options::ai_provider();
	}

	public static function is_openai(): bool {
		return self::OPENAI === self::selected();
	}
}
