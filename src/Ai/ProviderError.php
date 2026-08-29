<?php
/**
 * Typed OpenAI / assessment provider failures (no secret-bearing messages).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai;

defined( 'ABSPATH' ) || exit;

final class ProviderError extends \RuntimeException {

	public const CREDENTIAL_MISSING   = 'credential_missing';
	public const MODEL_INVALID        = 'model_invalid';
	public const INPUT_TOO_LARGE      = 'input_too_large';
	public const PROVIDER_INCOMPLETE  = 'provider_incomplete';
	public const PROVIDER_UNAVAILABLE = 'provider_unavailable';
	public const BUDGET_EXCEEDED      = 'budget_exceeded';

	/** @var list<string> */
	public const CODES = array(
		self::CREDENTIAL_MISSING,
		self::MODEL_INVALID,
		self::INPUT_TOO_LARGE,
		self::PROVIDER_INCOMPLETE,
		self::PROVIDER_UNAVAILABLE,
		self::BUDGET_EXCEEDED,
	);

	private string $failure_code;

	public function __construct( string $code ) {
		if ( ! in_array( $code, self::CODES, true ) ) {
			$code = self::PROVIDER_UNAVAILABLE;
		}
		$this->failure_code = $code;
		parent::__construct( $code, 0, null );
	}

	public function failure_code(): string {
		return $this->failure_code;
	}

	public static function credential_missing(): self {
		return new self( self::CREDENTIAL_MISSING );
	}

	public static function model_invalid(): self {
		return new self( self::MODEL_INVALID );
	}

	public static function input_too_large(): self {
		return new self( self::INPUT_TOO_LARGE );
	}

	public static function provider_incomplete(): self {
		return new self( self::PROVIDER_INCOMPLETE );
	}

	public static function provider_unavailable(): self {
		return new self( self::PROVIDER_UNAVAILABLE );
	}

	public static function budget_exceeded(): self {
		return new self( self::BUDGET_EXCEEDED );
	}
}
