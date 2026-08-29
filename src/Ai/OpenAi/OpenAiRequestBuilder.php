<?php
/**
 * Builds OpenAI Responses API request bodies (store:false, no tools/conversation).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

use UniversalProductReviews\Ai\PolicyAllowlist;
use UniversalProductReviews\Ai\ProviderError;
use UniversalProductReviews\Config\Options;

defined( 'ABSPATH' ) || exit;

final class OpenAiRequestBuilder {

	/**
	 * @param list<string> $allowed_phrases
	 * @param list<string> $disallowed_phrases
	 * @return array{url: string, headers: array<string, string>, body: string, model: string, max_output_tokens: int}
	 */
	public static function build(
		string $api_key,
		string $review_text,
		string $model,
		int $max_output_tokens,
		string $operator_guidance,
		array $allowed_phrases,
		array $disallowed_phrases,
		string $policy_version = PolicyAllowlist::POLICY_VERSION
	): array {
		if ( '' === trim( $api_key ) ) {
			throw ProviderError::credential_missing();
		}

		$model = Options::sanitize_model_manual( $model );
		if ( '' === $model ) {
			// Dropdown IDs also match the manual charset; reject empty/invalid.
			throw ProviderError::model_invalid();
		}
		if ( ! in_array( $model, Options::OPENAI_SUGGESTED_MODELS, true )
			&& 1 !== preg_match( '/^[a-zA-Z0-9._:-]{1,64}$/', $model )
		) {
			throw ProviderError::model_invalid();
		}

		if ( strlen( $review_text ) > Options::REVIEW_TEXT_MAX_CHARS ) {
			throw ProviderError::input_too_large();
		}

		$max_output_tokens = max(
			Options::OPENAI_MAX_OUTPUT_TOKENS_MIN,
			min( Options::OPENAI_MAX_OUTPUT_TOKENS_MAX, $max_output_tokens )
		);

		$guidance = wp_strip_all_tags( $operator_guidance );
		if ( strlen( $guidance ) > Options::GUIDANCE_MAX_CHARS ) {
			$guidance = substr( $guidance, 0, Options::GUIDANCE_MAX_CHARS );
		}

		$allowed_phrases    = Options::sanitize_phrase_list( $allowed_phrases );
		$disallowed_phrases = Options::sanitize_phrase_list( $disallowed_phrases );

		$user_payload = array(
			'task'                => OpenAiConstants::SCHEMA_NAME,
			'policy_version'      => $policy_version,
			'operator_guidance'   => $guidance,
			'allowed_phrases'     => $allowed_phrases,
			'disallowed_phrases'  => $disallowed_phrases,
			'review_text'         => $review_text,
		);

		$user_json = wp_json_encode( $user_payload );
		if ( ! is_string( $user_json ) ) {
			throw ProviderError::provider_unavailable();
		}

		$request = array(
			'model'             => $model,
			'max_output_tokens' => $max_output_tokens,
			'store'             => false,
			'input'             => array(
				array(
					'role'    => 'system',
					'content' => OpenAiConstants::SYSTEM_INSTRUCTION,
				),
				array(
					'role'    => 'user',
					'content' => $user_json,
				),
			),
			'text'              => array(
				'format' => array(
					'type'   => 'json_schema',
					'name'   => OpenAiConstants::SCHEMA_NAME,
					'strict' => true,
					'schema' => OpenAiStructuredOutput::json_schema(),
				),
			),
		);

		self::assert_safe_request( $request );

		$body = wp_json_encode( $request );
		if ( ! is_string( $body ) || '' === $body ) {
			throw ProviderError::provider_unavailable();
		}

		return array(
			'url'               => OpenAiConstants::ENDPOINT,
			'headers'           => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'              => $body,
			'model'             => $model,
			'max_output_tokens' => $max_output_tokens,
		);
	}

	/**
	 * @param array<string, mixed> $request Request array before JSON encode.
	 */
	public static function assert_safe_request( array $request ): void {
		if ( ! array_key_exists( 'store', $request ) || true === $request['store'] ) {
			throw ProviderError::provider_unavailable();
		}
		if ( false !== $request['store'] ) {
			throw ProviderError::provider_unavailable();
		}
		foreach ( array( 'tools', 'conversation', 'previous_response_id' ) as $forbidden ) {
			if ( array_key_exists( $forbidden, $request ) ) {
				throw ProviderError::provider_unavailable();
			}
		}
	}

	/**
	 * Decode built JSON and assert safety invariants (for tests / CI helpers).
	 *
	 * @return array<string, mixed>
	 */
	public static function decode_body( string $json_body ): array {
		$decoded = json_decode( $json_body, true );
		if ( ! is_array( $decoded ) ) {
			throw ProviderError::provider_unavailable();
		}
		self::assert_safe_request( $decoded );
		return $decoded;
	}
}
