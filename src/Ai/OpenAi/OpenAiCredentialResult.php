<?php
/**
 * Result of decrypting the OpenAI credential envelope (O9′).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

defined( 'ABSPATH' ) || exit;

final class OpenAiCredentialResult {

	private OpenAiCredentialState $state;

	private ?string $plaintext;

	public function __construct( OpenAiCredentialState $state, ?string $plaintext = null ) {
		$this->state     = $state;
		$this->plaintext = $plaintext;
	}

	public function state(): OpenAiCredentialState {
		return $this->state;
	}

	public function plaintext(): ?string {
		return $this->plaintext;
	}
}
