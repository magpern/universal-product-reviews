<?php
/**
 * Bounded HTTP result for OpenAI Responses calls (no secret fields).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

defined( 'ABSPATH' ) || exit;

final class OpenAiHttpResult {

	public const ERROR_NONE    = 'none';
	public const ERROR_TIMEOUT = 'timeout';
	public const ERROR_TRANSPORT = 'transport';

	public function __construct(
		public readonly int $status_code,
		public readonly string $body,
		public readonly string $error_kind = self::ERROR_NONE
	) {
	}

	public function is_ok(): bool {
		return self::ERROR_NONE === $this->error_kind && $this->status_code >= 200 && $this->status_code < 300;
	}
}
