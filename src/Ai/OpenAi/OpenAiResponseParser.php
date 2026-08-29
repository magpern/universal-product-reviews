<?php
/**
 * Parse OpenAI Responses API JSON into AssessmentResult or typed ProviderError.
 *
 * Does not persist raw bodies, ids, or prompts.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

use UniversalProductReviews\Ai\AssessmentResult;
use UniversalProductReviews\Ai\ProviderError;

defined( 'ABSPATH' ) || exit;

final class OpenAiResponseParser {

	/**
	 * @throws ProviderError Typed failure codes only (message = code).
	 */
	public static function parse( OpenAiHttpResult $http ): AssessmentResult {
		if ( OpenAiHttpResult::ERROR_TIMEOUT === $http->error_kind ) {
			throw ProviderError::provider_unavailable();
		}
		if ( OpenAiHttpResult::ERROR_TRANSPORT === $http->error_kind ) {
			throw ProviderError::provider_unavailable();
		}

		$status = $http->status_code;
		if ( $status < 200 || $status >= 300 ) {
			if ( 401 === $status || 403 === $status ) {
				throw ProviderError::credential_missing();
			}
			throw ProviderError::provider_unavailable();
		}

		$decoded = json_decode( $http->body, true );
		if ( ! is_array( $decoded ) ) {
			throw ProviderError::provider_incomplete();
		}

		// Never retain id / request identifiers beyond this stack frame.
		unset( $decoded['id'], $decoded['previous_response_id'] );

		$status_field = isset( $decoded['status'] ) && is_string( $decoded['status'] ) ? $decoded['status'] : '';
		if ( in_array( $status_field, array( 'incomplete', 'failed', 'cancelled' ), true ) ) {
			throw ProviderError::provider_incomplete();
		}

		$json_text = self::extract_output_text( $decoded );
		if ( null === $json_text || '' === $json_text ) {
			throw ProviderError::provider_incomplete();
		}

		$payload = json_decode( $json_text, true );
		if ( ! is_array( $payload ) ) {
			throw ProviderError::provider_incomplete();
		}

		// Refuse free-text / injection extras at the schema object layer.
		$allowed_keys = array( 'state', 'publication_safety_score', 'confidence', 'reason_codes' );
		foreach ( array_keys( $payload ) as $key ) {
			if ( ! in_array( $key, $allowed_keys, true ) ) {
				throw ProviderError::provider_incomplete();
			}
		}

		return OpenAiStructuredOutput::map_to_result( $payload );
	}

	/**
	 * @param array<string, mixed> $decoded Top-level Responses object.
	 */
	private static function extract_output_text( array $decoded ): ?string {
		if ( isset( $decoded['output_text'] ) && is_string( $decoded['output_text'] ) && '' !== $decoded['output_text'] ) {
			return $decoded['output_text'];
		}

		if ( ! isset( $decoded['output'] ) || ! is_array( $decoded['output'] ) ) {
			return null;
		}

		foreach ( $decoded['output'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['type'] ) && 'message' !== $item['type'] ) {
				continue;
			}
			if ( ! isset( $item['content'] ) || ! is_array( $item['content'] ) ) {
				continue;
			}
			foreach ( $item['content'] as $part ) {
				if ( ! is_array( $part ) ) {
					continue;
				}
				$type = isset( $part['type'] ) ? (string) $part['type'] : '';
				if ( in_array( $type, array( 'output_text', 'text' ), true ) && isset( $part['text'] ) && is_string( $part['text'] ) ) {
					return $part['text'];
				}
			}
		}

		return null;
	}
}
