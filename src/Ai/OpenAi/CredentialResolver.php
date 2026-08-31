<?php
/**
 * OpenAI credential resolution: constant → environment → encrypted store (O9′).
 *
 * Never logs, returns, or serialises the secret outside require_secret().
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

defined( 'ABSPATH' ) || exit;

final class CredentialResolver {

	public const SOURCE_CONSTANT    = 'constant';
	public const SOURCE_ENVIRONMENT = 'environment';
	public const SOURCE_STORED      = 'stored';
	public const SOURCE_MISSING     = 'missing';

	/** @var string|null Test seam: non-null string = secret; empty string = force missing. */
	private static ?string $test_secret = null;

	/** @var string|null Test seam source when $test_secret is set. */
	private static ?string $test_source = null;

	/**
	 * Test seam only — never use in production paths.
	 *
	 * @param string|null $secret Null clears override; '' forces missing; non-empty sets secret.
	 */
	public static function set_test_credential( ?string $secret, ?string $source = null ): void {
		self::$test_secret = $secret;
		self::$test_source = $source;
	}

	/**
	 * @return array{present: bool, source: string, stored_undecryptable: bool}
	 */
	public static function status(): array {
		$resolved = self::resolve_internal();
		return array(
			'present'              => '' !== $resolved['secret'],
			'source'               => $resolved['source'],
			'stored_undecryptable' => $resolved['stored_undecryptable'],
		);
	}

	/**
	 * Returns the secret for authorised OpenAI HTTP only. Callers must not log or persist it.
	 *
	 * @throws \UniversalProductReviews\Ai\ProviderError When missing.
	 */
	public static function require_secret(): string {
		$resolved = self::resolve_internal();
		if ( '' === $resolved['secret'] ) {
			throw \UniversalProductReviews\Ai\ProviderError::credential_missing();
		}
		return $resolved['secret'];
	}

	/**
	 * @return array{secret: string, source: string, stored_undecryptable: bool}
	 */
	private static function resolve_internal(): array {
		if ( null !== self::$test_secret ) {
			$source = self::$test_source ?? ( '' === self::$test_secret ? self::SOURCE_MISSING : self::SOURCE_CONSTANT );
			return array(
				'secret'               => self::$test_secret,
				'source'               => '' === self::$test_secret ? self::SOURCE_MISSING : $source,
				'stored_undecryptable' => false,
			);
		}

		if ( defined( 'UPR_OPENAI_API_KEY' ) ) {
			$constant = trim( (string) constant( 'UPR_OPENAI_API_KEY' ) );
			if ( '' !== $constant ) {
				return array(
					'secret'               => $constant,
					'source'               => self::SOURCE_CONSTANT,
					'stored_undecryptable' => OpenAiCredentialStore::is_undecryptable(),
				);
			}
		}

		$env = getenv( 'UPR_OPENAI_API_KEY' );
		if ( false !== $env ) {
			$env = trim( (string) $env );
			if ( '' !== $env ) {
				return array(
					'secret'               => $env,
					'source'               => self::SOURCE_ENVIRONMENT,
					'stored_undecryptable' => OpenAiCredentialStore::is_undecryptable(),
				);
			}
		}

		$result = OpenAiCredentialStore::decrypt();
		if ( OpenAiCredentialState::AVAILABLE === $result->state() && null !== $result->plaintext() && '' !== $result->plaintext() ) {
			return array(
				'secret'               => $result->plaintext(),
				'source'               => self::SOURCE_STORED,
				'stored_undecryptable' => false,
			);
		}

		$undecryptable = OpenAiCredentialState::ABSENT !== $result->state();

		return array(
			'secret'               => '',
			'source'               => self::SOURCE_MISSING,
			'stored_undecryptable' => $undecryptable,
		);
	}
}
