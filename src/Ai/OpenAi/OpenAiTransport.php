<?php
/**
 * Narrow HTTP transport for OpenAI Responses API.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

defined( 'ABSPATH' ) || exit;

interface OpenAiTransport {

	/**
	 * POST JSON to the fixed OpenAI endpoint.
	 *
	 * @param array<string, string> $headers Request headers (may include Authorization).
	 */
	public function post( string $url, array $headers, string $json_body, int $timeout_seconds ): OpenAiHttpResult;
}
