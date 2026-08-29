<?php
/**
 * Salt-stable opaque provider fingerprint.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

final class ProviderFingerprint {

	public static function for_builtin( string $policy_version = PolicyAllowlist::POLICY_VERSION ): string {
		return hash(
			'sha256',
			implode(
				"\n",
				array(
					'local',
					$policy_version,
					PolicyAllowlist::PROVIDER_STABLE_ID,
					PolicyAllowlist::CONFIG_REVISION,
				)
			)
		);
	}

	public static function for_openai(
		string $model,
		int $max_tokens,
		string $guidance_hash,
		string $phrases_hash,
		string $policy_version = PolicyAllowlist::POLICY_VERSION
	): string {
		return hash(
			'sha256',
			implode(
				"\n",
				array(
					'openai',
					$policy_version,
					PolicyAllowlist::OPENAI_PROVIDER_STABLE_ID,
					$model,
					(string) $max_tokens,
					$guidance_hash,
					$phrases_hash,
					PolicyAllowlist::CONFIG_REVISION,
				)
			)
		);
	}
}
