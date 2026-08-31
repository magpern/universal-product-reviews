<?php
/**
 * Decrypt outcome for the OpenAI credential envelope (O9′).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

defined( 'ABSPATH' ) || exit;

enum OpenAiCredentialState {
	case AVAILABLE;
	case INVALIDATED;
	case UNAVAILABLE;
	case ABSENT;
}
