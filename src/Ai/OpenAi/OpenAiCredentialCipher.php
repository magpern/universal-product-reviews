<?php
/**
 * AES-256-GCM envelope for the OpenAI API key (M10 O9′).
 *
 * Normative format: docs/roadmap/m10-o9-encrypted-openai-credential-amendment.md §4.
 * Never logs envelope, plaintext, nonce, tag, or key_source.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

defined( 'ABSPATH' ) || exit;

/**
 * Not final: unit tests override key-resolution hooks for salt-rotation scenarios.
 */
class OpenAiCredentialCipher {

	public const ENVELOPE_PREFIX   = 'upr1:';
	public const AAD_PURPOSE       = 'upr.openai.api_key';
	public const CIPHER            = 'aes-256-gcm';
	public const NONCE_LENGTH      = 12;
	public const TAG_LENGTH        = 16;
	public const KEY_SOURCE_AUTH   = 1;
	public const KEY_SOURCE_SITE   = 2;
	public const PLAINTEXT_MIN     = 1;
	public const PLAINTEXT_MAX     = 512;
	public const PAYLOAD_MIN       = 30;
	public const PAYLOAD_MAX       = 541;
	public const ENVELOPE_HARD_CAP = 1024;

	private const WP_DEFAULT_SALT_PLACEHOLDER = 'put your unique phrase here';

	/**
	 * Encrypts plaintext under the currently resolved key source.
	 *
	 * @throws \RuntimeException When no key material can be resolved or OpenSSL fails.
	 */
	public function encrypt( string $plaintext ): string {
		if ( strlen( $plaintext ) < self::PLAINTEXT_MIN || strlen( $plaintext ) > self::PLAINTEXT_MAX ) {
			throw new \RuntimeException( 'Plaintext length out of bounds.' );
		}
		if ( $this->contains_forbidden_octets( $plaintext ) ) {
			throw new \RuntimeException( 'Plaintext contains forbidden octets.' );
		}

		list( $key, $source ) = $this->resolve_key_for_encrypt();
		$nonce                = random_bytes( self::NONCE_LENGTH );
		$tag                  = '';
		$aad                  = self::AAD_PURPOSE . chr( $source );

		$ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, self::TAG_LENGTH );
		if ( false === $ciphertext || self::TAG_LENGTH !== strlen( $tag ) ) {
			throw new \RuntimeException( 'Encryption failed.' );
		}

		$payload = chr( $source ) . $nonce . $tag . $ciphertext;
		return self::ENVELOPE_PREFIX . $this->base64url_encode_nopad( $payload );
	}

	/**
	 * Decrypts a stored envelope. Never throws; never modifies storage.
	 */
	public function decrypt( string $stored ): OpenAiCredentialResult {
		if ( '' === $stored ) {
			return new OpenAiCredentialResult( OpenAiCredentialState::ABSENT );
		}

		if ( strlen( $stored ) > self::ENVELOPE_HARD_CAP ) {
			return new OpenAiCredentialResult( OpenAiCredentialState::INVALIDATED );
		}

		$parsed = $this->parse_envelope( $stored );
		if ( null === $parsed ) {
			return new OpenAiCredentialResult( OpenAiCredentialState::INVALIDATED );
		}

		try {
			$key = $this->resolve_key_for_source( $parsed['source'] );
		} catch ( \RuntimeException $e ) {
			return new OpenAiCredentialResult( OpenAiCredentialState::UNAVAILABLE );
		}

		$aad       = self::AAD_PURPOSE . chr( $parsed['source'] );
		$plaintext = openssl_decrypt(
			$parsed['ciphertext'],
			self::CIPHER,
			$key,
			OPENSSL_RAW_DATA,
			$parsed['nonce'],
			$parsed['tag'],
			$aad
		);

		if ( false === $plaintext ) {
			return new OpenAiCredentialResult( OpenAiCredentialState::INVALIDATED );
		}

		$len = strlen( $plaintext );
		if ( $len < self::PLAINTEXT_MIN || $len > self::PLAINTEXT_MAX || $this->contains_forbidden_octets( $plaintext ) ) {
			return new OpenAiCredentialResult( OpenAiCredentialState::INVALIDATED );
		}

		return new OpenAiCredentialResult( OpenAiCredentialState::AVAILABLE, $plaintext );
	}

	/**
	 * @return array{source: int, nonce: string, tag: string, ciphertext: string}|null
	 */
	public function parse_envelope( string $stored ): ?array {
		if ( ! str_starts_with( $stored, self::ENVELOPE_PREFIX ) ) {
			return null;
		}

		$remainder = substr( $stored, strlen( self::ENVELOPE_PREFIX ) );
		if ( '' === $remainder || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $remainder ) ) {
			return null;
		}

		$decoded = $this->base64url_decode_strict( $remainder );
		if ( null === $decoded ) {
			return null;
		}

		$len = strlen( $decoded );
		if ( $len < self::PAYLOAD_MIN || $len > self::PAYLOAD_MAX ) {
			return null;
		}

		$source = ord( $decoded[0] );
		if ( self::KEY_SOURCE_AUTH !== $source && self::KEY_SOURCE_SITE !== $source ) {
			return null;
		}

		return array(
			'source'     => $source,
			'nonce'      => substr( $decoded, 1, self::NONCE_LENGTH ),
			'tag'        => substr( $decoded, 1 + self::NONCE_LENGTH, self::TAG_LENGTH ),
			'ciphertext' => substr( $decoded, 1 + self::NONCE_LENGTH + self::TAG_LENGTH ),
		);
	}

	public function base64url_encode_nopad( string $binary ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- envelope transport, not obfuscation.
		return rtrim( strtr( base64_encode( $binary ), '+/', '-_' ), '=' );
	}

	/**
	 * @return string|null Decoded bytes, or null if non-canonical / invalid.
	 */
	public function base64url_decode_strict( string $remainder ): ?string {
		$pad_len = ( 4 - ( strlen( $remainder ) % 4 ) ) % 4;
		$padded  = $remainder . str_repeat( '=', $pad_len );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- envelope transport, not obfuscation.
		$decoded = base64_decode( strtr( $padded, '-_', '+/' ), true );
		if ( false === $decoded ) {
			return null;
		}
		if ( $this->base64url_encode_nopad( $decoded ) !== $remainder ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * @return array{0: string, 1: int} Raw key bytes and key_source.
	 */
	protected function resolve_key_for_encrypt(): array {
		$auth = $this->resolve_wp_auth_salts();
		if ( null !== $auth ) {
			return array( $auth, self::KEY_SOURCE_AUTH );
		}
		return array( hash( 'sha256', $this->site_salt_material(), true ), self::KEY_SOURCE_SITE );
	}

	/**
	 * @throws \RuntimeException When the recorded source cannot resolve.
	 */
	protected function resolve_key_for_source( int $source ): string {
		if ( self::KEY_SOURCE_AUTH === $source ) {
			$key = $this->resolve_wp_auth_salts();
			if ( null === $key ) {
				throw new \RuntimeException( 'Auth salts unavailable.' );
			}
			return $key;
		}
		if ( self::KEY_SOURCE_SITE === $source ) {
			return hash( 'sha256', $this->site_salt_material(), true );
		}
		throw new \RuntimeException( 'Unknown key source.' );
	}

	protected function resolve_wp_auth_salts(): ?string {
		$constants    = array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY' );
		$concatenated = '';
		foreach ( $constants as $constant ) {
			if ( ! defined( $constant ) ) {
				return null;
			}
			$value = constant( $constant );
			if ( ! is_string( $value ) || '' === $value || str_contains( $value, self::WP_DEFAULT_SALT_PLACEHOLDER ) ) {
				return null;
			}
			$concatenated .= $value;
		}
		return hash( 'sha256', $concatenated, true );
	}

	protected function site_salt_material(): string {
		return (string) wp_salt( 'auth' );
	}

	private function contains_forbidden_octets( string $value ): bool {
		$len = strlen( $value );
		for ( $i = 0; $i < $len; $i++ ) {
			$o = ord( $value[ $i ] );
			if ( 0 === $o || $o <= 0x1F || 0x7F === $o ) {
				return true;
			}
		}
		return false;
	}
}
