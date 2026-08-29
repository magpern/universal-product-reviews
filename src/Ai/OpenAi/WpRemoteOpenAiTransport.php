<?php
/**
 * Production transport: wp_remote_post only (path-allowlisted by CI).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

defined( 'ABSPATH' ) || exit;

final class WpRemoteOpenAiTransport implements OpenAiTransport {

	public function post( string $url, array $headers, string $json_body, int $timeout_seconds ): OpenAiHttpResult {
		if ( OpenAiConstants::ENDPOINT !== $url ) {
			return new OpenAiHttpResult( 0, '', OpenAiHttpResult::ERROR_TRANSPORT );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => max( 1, min( OpenAiConstants::HTTP_TIMEOUT_SECONDS, $timeout_seconds ) ),
				'headers' => $headers,
				'body'    => $json_body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$code = (string) $response->get_error_code();
			$kind = ( false !== stripos( $code, 'timed' ) || false !== stripos( $code, 'timeout' ) )
				? OpenAiHttpResult::ERROR_TIMEOUT
				: OpenAiHttpResult::ERROR_TRANSPORT;
			return new OpenAiHttpResult( 0, '', $kind );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		return new OpenAiHttpResult( $status, $body, OpenAiHttpResult::ERROR_NONE );
	}
}
