<?php
/**
 * Wilson score interval helpers for M12 calibration gates.
 *
 * Offline harness only — no WordPress, providers, or comment access.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Calibration;

/**
 * Computes Wilson score confidence bounds for a binomial proportion.
 */
final class WilsonInterval {

	/** z for approximately 95% two-sided normal confidence. */
	public const Z_95 = 1.959963984540054;

	/**
	 * Wilson 95% upper confidence bound for successes/n.
	 *
	 * @param int $successes Non-negative count.
	 * @param int $n         Trials (must be > 0).
	 */
	public static function upper_bound_95( int $successes, int $n ): float {
		if ( $n <= 0 ) {
			throw new \InvalidArgumentException( 'n must be positive' );
		}
		if ( $successes < 0 || $successes > $n ) {
			throw new \InvalidArgumentException( 'successes out of range' );
		}

		$z  = self::Z_95;
		$z2 = $z * $z;
		$phat = $successes / $n;
		$denom = 1.0 + $z2 / $n;
		$centre = $phat + $z2 / ( 2.0 * $n );
		$margin = $z * sqrt( ( $phat * ( 1.0 - $phat ) + $z2 / ( 4.0 * $n ) ) / $n );

		return ( $centre + $margin ) / $denom;
	}
}
