<?php
/**
 * CLI: evaluate privacy-safe M12 calibration evidence (offline).
 *
 * Usage: php scripts/calibration/evaluate.php path/to/evidence.json
 *
 * Read-only. No WordPress comments, providers, credentials, or status changes.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

$root = dirname( __DIR__, 2 );
require_once $root . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

require_once __DIR__ . '/src/WilsonInterval.php';
require_once __DIR__ . '/src/WouldActEvaluator.php';
require_once __DIR__ . '/src/EvidenceDocumentParser.php';
require_once __DIR__ . '/src/EvidenceEvaluator.php';

use UniversalProductReviews\Calibration\EvidenceEvaluator;

if ( $argc < 2 ) {
	fwrite( STDERR, "Usage: php scripts/calibration/evaluate.php <evidence.json>\n" );
	exit( 2 );
}

$path = $argv[1];
if ( ! is_readable( $path ) ) {
	fwrite( STDERR, "Unreadable evidence file: {$path}\n" );
	exit( 2 );
}

$raw = file_get_contents( $path );
$data = json_decode( (string) $raw, true );
if ( ! is_array( $data ) ) {
	fwrite( STDERR, "Evidence JSON must decode to an object\n" );
	exit( 2 );
}

$result = EvidenceEvaluator::evaluate( $data );
echo wp_json_encode_safe( $result ) . "\n";

exit( 'Calibration GO' === ( $result['verdict'] ?? '' ) ? 0 : 1 );

/**
 * Encode without requiring WordPress.
 *
 * @param mixed $data Data.
 */
function wp_json_encode_safe( $data ): string {
	$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	return false === $json ? '{}' : $json;
}
