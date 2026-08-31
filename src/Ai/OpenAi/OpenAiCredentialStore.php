<?php
/**
 * Option storage for the OpenAI API key ciphertext (M10 O9′).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

defined( 'ABSPATH' ) || exit;

final class OpenAiCredentialStore {

	public const OPTION = 'upr_openai_api_key_ciphertext';

	public const FIELD = 'upr_openai_api_key';

	/**
	 * Raw stored envelope string, or empty when absent.
	 * Never returned to UI callers — use decrypt_status()/CredentialResolver.
	 */
	public static function get_envelope(): string {
		$raw = get_option( self::OPTION, '' );
		return is_string( $raw ) ? $raw : '';
	}

	public static function exists(): bool {
		return '' !== self::get_envelope();
	}

	/**
	 * Decrypt without exposing to non-OpenAI callers. Read path never deletes.
	 */
	public static function decrypt( ?OpenAiCredentialCipher $cipher = null ): OpenAiCredentialResult {
		$envelope = self::get_envelope();
		if ( '' === $envelope ) {
			return new OpenAiCredentialResult( OpenAiCredentialState::ABSENT );
		}
		$cipher = $cipher ?? new OpenAiCredentialCipher();
		return $cipher->decrypt( $envelope );
	}

	/**
	 * Whether a ciphertext row exists but cannot be decrypted (UI operational copy only).
	 */
	public static function is_undecryptable( ?OpenAiCredentialCipher $cipher = null ): bool {
		$envelope = self::get_envelope();
		if ( '' === $envelope ) {
			return false;
		}
		$state = self::decrypt( $cipher )->state();
		return OpenAiCredentialState::AVAILABLE !== $state;
	}

	/**
	 * Persist ciphertext. First save uses add_option with autoload=false;
	 * replace uses update_option without flipping autoload on.
	 */
	public static function save_envelope( string $envelope ): bool {
		if ( ! self::row_exists() ) {
			return add_option( self::OPTION, $envelope, '', false );
		}
		// Null autoload (WP 6.4+) / omit change: preserve existing non-autoload.
		return update_option( self::OPTION, $envelope, null );
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Autoload flag from wp_options for tests / proofs. Returns '' if missing.
	 */
	public static function autoload_flag(): string {
		global $wpdb;
		$flag = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION
			)
		);
		return is_string( $flag ) ? $flag : '';
	}

	private static function row_exists(): bool {
		global $wpdb;
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				self::OPTION
			)
		);
		return null !== $id && false !== $id;
	}
}
