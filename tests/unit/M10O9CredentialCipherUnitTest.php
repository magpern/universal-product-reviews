<?php
/**
 * M10 O9′: OpenAI credential cipher envelope tests (no network).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Unit;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Ai\OpenAi\OpenAiCredentialCipher;
use UniversalProductReviews\Ai\OpenAi\OpenAiCredentialState;

final class M10O9CredentialCipherUnitTest extends TestCase {

	private function cipher_with_fixed_auth_key( string $hex64 ): OpenAiCredentialCipher {
		return new class( $hex64 ) extends OpenAiCredentialCipher {
			public function __construct( private string $hex ) {}
			protected function resolve_wp_auth_salts(): ?string {
				$raw = hex2bin( $this->hex );
				return false === $raw ? null : $raw;
			}
			protected function site_salt_material(): string {
				return 'test-site-salt-material';
			}
		};
	}

	public function test_round_trip_under_auth_source(): void {
		$cipher = $this->cipher_with_fixed_auth_key( str_repeat( 'ab', 32 ) );
		$plain  = 'sk-test-secret-value-with spaces';
		$stored = $cipher->encrypt( $plain );
		$this->assertStringStartsWith( 'upr1:', $stored );
		$this->assertStringNotContainsString( '+', $stored );
		$this->assertStringNotContainsString( '/', $stored );
		$this->assertStringNotContainsString( '=', $stored );
		$this->assertStringNotContainsString( $plain, $stored );

		$result = $cipher->decrypt( $stored );
		$this->assertSame( OpenAiCredentialState::AVAILABLE, $result->state() );
		$this->assertSame( $plain, $result->plaintext() );
	}

	public function test_key_source_only_flip_fails_closed_due_to_aad(): void {
		// Both key sources resolve to the identical 32-byte key so a source-byte
		// flip cannot fail from key-material mismatch — only from AAD binding.
		$identical_key = str_repeat( "\x11", 32 );
		$cipher        = new class( $identical_key ) extends OpenAiCredentialCipher {
			/** @var string */
			public string $resolved_for_site = '';

			public function __construct( private string $key ) {}
			protected function resolve_wp_auth_salts(): ?string {
				return $this->key;
			}
			protected function site_salt_material(): string {
				return 'unused-when-resolve_key_for_source-overridden';
			}
			protected function resolve_key_for_source( int $source ): string {
				if ( self::KEY_SOURCE_SITE === $source ) {
					$this->resolved_for_site = $this->key;
				}
				return $this->key;
			}
		};

		$stored = $cipher->encrypt( 'sk-flip-aad-only' );
		$parsed = $cipher->parse_envelope( $stored );
		$this->assertNotNull( $parsed );
		$this->assertSame( OpenAiCredentialCipher::KEY_SOURCE_AUTH, $parsed['source'] );

		$flipped  = chr( OpenAiCredentialCipher::KEY_SOURCE_SITE ) . $parsed['nonce'] . $parsed['tag'] . $parsed['ciphertext'];
		$tampered = OpenAiCredentialCipher::ENVELOPE_PREFIX . $cipher->base64url_encode_nopad( $flipped );
		$result   = $cipher->decrypt( $tampered );
		$this->assertSame( OpenAiCredentialState::INVALIDATED, $result->state() );
		$this->assertNull( $result->plaintext() );
		$this->assertSame( $identical_key, $cipher->resolved_for_site, 'decrypt used identical key bytes for flipped source' );

		// Untampered envelope still decrypts under the same cipher.
		$this->assertSame( OpenAiCredentialState::AVAILABLE, $cipher->decrypt( $stored )->state() );
	}

	public function test_salt_rotation_invalidates_without_erasing_bytes(): void {
		$key_a  = str_repeat( 'ab', 32 );
		$key_b  = str_repeat( 'cd', 32 );
		$before = $this->cipher_with_fixed_auth_key( $key_a );
		$after  = $this->cipher_with_fixed_auth_key( $key_b );
		$stored = $before->encrypt( 'sk-rotate' );
		$copy   = $stored;

		$result = $after->decrypt( $stored );
		$this->assertSame( OpenAiCredentialState::INVALIDATED, $result->state() );
		$this->assertSame( $copy, $stored );

		$still = $before->decrypt( $stored );
		$this->assertSame( OpenAiCredentialState::AVAILABLE, $still->state() );
		$this->assertSame( 'sk-rotate', $still->plaintext() );
	}

	/**
	 * @dataProvider invalid_envelope_provider
	 */
	public function test_invalid_envelopes_fail_closed( string $fixture ): void {
		$cipher = $this->cipher_with_fixed_auth_key( str_repeat( 'ab', 32 ) );
		$result = $cipher->decrypt( $fixture );
		$this->assertContains(
			$result->state(),
			array( OpenAiCredentialState::INVALIDATED, OpenAiCredentialState::ABSENT )
		);
		$this->assertNull( $result->plaintext() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function invalid_envelope_provider(): array {
		$cipher  = $this->cipher_with_fixed_auth_key( str_repeat( 'ab', 32 ) );
		$valid   = $cipher->encrypt( 'sk-valid-for-mutation' );
		$parsed  = $cipher->parse_envelope( $valid );
		$this->assertNotNull( $parsed );
		$payload = chr( $parsed['source'] ) . $parsed['nonce'] . $parsed['tag'] . $parsed['ciphertext'];

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$std = strtr( base64_encode( $payload ), '-_', '+/' );

		return array(
			'empty remainder'    => array( 'upr1:' ),
			'standard plus'      => array( 'upr1:' . $std ),
			'padded'             => array( 'upr1:' . $cipher->base64url_encode_nopad( $payload ) . '=' ),
			'whitespace'         => array( 'upr1:abc def' ),
			'upr2'               => array( 'upr2:' . $cipher->base64url_encode_nopad( $payload ) ),
			'UPR1'               => array( 'UPR1:' . $cipher->base64url_encode_nopad( $payload ) ),
			'usc1'               => array( 'usc1:' . $cipher->base64url_encode_nopad( $payload ) ),
			'truncated short'    => array( 'upr1:' . $cipher->base64url_encode_nopad( substr( $payload, 0, 10 ) ) ),
			'unknown source 0'   => array( 'upr1:' . $cipher->base64url_encode_nopad( "\x00" . substr( $payload, 1 ) ) ),
			'unknown source 3'   => array( 'upr1:' . $cipher->base64url_encode_nopad( "\x03" . substr( $payload, 1 ) ) ),
			'unknown source 255' => array( 'upr1:' . $cipher->base64url_encode_nopad( "\xff" . substr( $payload, 1 ) ) ),
			'tampered tag'       => array( 'upr1:' . $cipher->base64url_encode_nopad( chr( $parsed['source'] ) . $parsed['nonce'] . str_repeat( "\x00", 16 ) . $parsed['ciphertext'] ) ),
			'oversized'          => array( 'upr1:' . str_repeat( 'A', 1100 ) ),
			'empty string'       => array( '' ),
		);
	}

	public function test_wrong_aad_fails_closed(): void {
		$cipher = new class( str_repeat( 'ab', 32 ) ) extends OpenAiCredentialCipher {
			public function __construct( private string $hex ) {}
			protected function resolve_wp_auth_salts(): ?string {
				$raw = hex2bin( $this->hex );
				return false === $raw ? null : $raw;
			}
			protected function site_salt_material(): string {
				return 'site';
			}
			public function encrypt_with_bad_aad( string $plaintext ): string {
				$key     = hex2bin( $this->hex );
				$source  = self::KEY_SOURCE_AUTH;
				$nonce   = random_bytes( self::NONCE_LENGTH );
				$tag     = '';
				$ct      = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, 'wrong.aad' . chr( $source ), self::TAG_LENGTH );
				$payload = chr( $source ) . $nonce . $tag . $ct;
				return self::ENVELOPE_PREFIX . $this->base64url_encode_nopad( $payload );
			}
		};
		$bad = $cipher->encrypt_with_bad_aad( 'sk-aad' );
		$this->assertSame( OpenAiCredentialState::INVALIDATED, $cipher->decrypt( $bad )->state() );
	}
}
