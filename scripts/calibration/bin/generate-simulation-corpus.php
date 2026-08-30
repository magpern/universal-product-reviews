#!/usr/bin/env php
<?php
/**
 * Generate privacy-safe m12-sim-v1 synthetic_simulation corpus for Simulation GO.
 *
 * Offline only. No WordPress, providers, credentials, or customer data.
 *
 * Usage: php scripts/calibration/bin/generate-simulation-corpus.php [out.json]
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

$out = $argv[1] ?? ( dirname( __DIR__ ) . '/fixtures/m12-sim-v1.synthetic.json' );

const LEGIT_N = 2000;
const SPAM_N  = 250;

/**
 * Opaque deterministic id.
 */
function sid( string $prefix, int $i ): string {
	return sprintf( '%s%06d', $prefix, $i );
}

/**
 * Canonical JSON for hashing (sorted keys recursively).
 *
 * @param mixed $data Data.
 */
function canonical_json( $data ): string {
	if ( is_array( $data ) ) {
		if ( array_is_list( $data ) ) {
			$mapped = array();
			foreach ( $data as $v ) {
				$mapped[] = json_decode( canonical_json( $v ), true );
			}
			return json_encode( $mapped, JSON_UNESCAPED_SLASHES );
		}
		$keys = array_keys( $data );
		sort( $keys );
		$obj = array();
		foreach ( $keys as $k ) {
			$obj[ $k ] = json_decode( canonical_json( $data[ $k ] ), true );
		}
		return json_encode( $obj, JSON_UNESCAPED_SLASHES );
	}
	return json_encode( $data, JSON_UNESCAPED_SLASHES );
}

function sha256_hex( string $s ): string {
	return hash( 'sha256', $s );
}

function double_label( string $label ): array {
	return array(
		'labeler_a' => 'synth-labeler-a',
		'labeler_b' => 'synth-labeler-b',
		'label_a'   => $label,
		'label_b'   => $label,
	);
}

/**
 * Varied synthetic assessment maps (no review bodies).
 *
 * @return array{0: array<string,mixed>, 1: string} assessment + human_label
 */
function spam_assessment( int $i ): array {
	$codes = array(
		array( 'spam_pattern' ),
		array( 'link_abuse' ),
		array( 'fraud_suspected' ),
		array( 'impersonation_suspected' ),
		array( 'spam_pattern', 'link_abuse' ),
	);
	$code = $codes[ $i % count( $codes ) ];
	return array(
		array(
			'state'                    => 'completed',
			'confidence'               => 'high',
			'publication_safety_score' => 80 + ( $i % 21 ),
			'reason_codes'             => $code,
			// Scenario tags (not bodies): technical spam variants for Simulation GO.
			'simulation_scenario'      => array(
				'seo_flood',
				'credential_phish_plain_text',
				'promo_blast',
				'impersonation_support',
				'prompt_injection_inert',
			)[ $i % 5 ],
		),
		'technical_spam',
	);
}

/**
 * @return array{0: array<string,mixed>, 1: string}
 */
function legit_assessment( int $i ): array {
	// Legitimate critical reviews: low/mid risk, no spam-family codes.
	$scenarios = array(
		'quality_complaint',
		'shipping_delay_criticism',
		'price_disagreement',
		'competitor_comparison',
		'prompt_injection_inert_as_customer_text',
	);
	return array(
		array(
			'state'                    => 'completed',
			'confidence'               => ( 0 === $i % 7 ) ? 'medium' : 'high',
			'publication_safety_score' => 5 + ( $i % 35 ),
			'reason_codes'             => array(),
			'simulation_scenario'      => $scenarios[ $i % count( $scenarios ) ],
		),
		'not_spam',
	);
}

$assignments = array();
$legit_rows  = array();
$spam_rows   = array();

for ( $i = 0; $i < LEGIT_N; $i++ ) {
	$id    = sid( 'simleg', $i );
	$split = ( 0 === $i % 5 ) ? 'holdout' : 'train';
	$dbl   = ( 0 === $i % 5 );
	[ $assessment, $label ] = legit_assessment( $i );
	$row = array(
		'id'              => $id,
		'human_label'     => $label,
		'split'           => $split,
		'double_labelled' => $dbl,
		'assessment'      => $assessment,
	);
	if ( $dbl ) {
		$row['double_label'] = double_label( $label );
	}
	$legit_rows[]         = $row;
	$assignments[ $id ] = $split;
}

for ( $i = 0; $i < SPAM_N; $i++ ) {
	$id    = sid( 'simspm', $i );
	$split = ( 0 === $i % 5 ) ? 'holdout' : 'train';
	$dbl   = ( 0 === $i % 5 );
	[ $assessment, $label ] = spam_assessment( $i );
	$row = array(
		'id'              => $id,
		'human_label'     => $label,
		'split'           => $split,
		'double_labelled' => $dbl,
		'assessment'      => $assessment,
	);
	if ( $dbl ) {
		$row['double_label'] = double_label( $label );
	}
	$spam_rows[]          = $row;
	$assignments[ $id ] = $split;
}

// Mandatory-human synthetic rows (must not would-act).
$mh_rows = array();
for ( $i = 0; $i < 40; $i++ ) {
	$id = sid( 'simmnh', $i );
	$mh_rows[] = array(
		'id'              => $id,
		'human_label'     => 'mandatory_human',
		'split'           => ( 0 === $i % 5 ) ? 'holdout' : 'train',
		'double_labelled' => false,
		'assessment'      => array(
			'state'                    => 'completed',
			'confidence'               => 'high',
			'publication_safety_score' => 90,
			'reason_codes'             => array( 'pii_suspected' ),
			'simulation_scenario'      => 'mandatory_human_hold',
		),
	);
	$assignments[ $id ] = ( 0 === $i % 5 ) ? 'holdout' : 'train';
}

ksort( $assignments );
$assignment_sha = sha256_hex( canonical_json( $assignments ) );

$rows_for_hash = array(
	'legitimate_negative' => $legit_rows,
	'technical_spam'      => $spam_rows,
	'mandatory_human'     => $mh_rows,
	'excluded'            => array(),
);
$labels_only = array();
$assess_only = array();
foreach ( array_merge( $legit_rows, $spam_rows, $mh_rows ) as $r ) {
	$labels_only[ $r['id'] ] = $r['human_label'];
	$assess_only[ $r['id'] ] = $r['assessment'];
}
ksort( $labels_only );
ksort( $assess_only );

$doc = array(
	'schema_version'               => 'm12-cal-v1',
	'evidence_status'              => 'synthetic_simulation',
	'description'                  => 'm12-sim-v1 privacy-safe synthetic Simulation GO fixture. AI/hand-authored scenarios only. NOT real-world Calibration evidence. No customer PII, bodies, URLs, or credentials.',
	'holdout_locked_before_tuning' => true,
	'tuple'                        => array(
		'provider_kind'                  => 'local',
		'assessor_version'               => 'builtin-local-2026-08',
		'heuristic_or_model_fingerprint' => 'upr-local-heuristic-v1',
		'validator_version'              => 'assessment-validator-v1',
		'assessment_policy_version'      => '2026-08-ps-v1',
		'recommendation_policy_version'  => '2026-08-rec-v1',
		'action_policy_version'          => '2026-08-act-v1',
	),
	'provenance'                   => array(
		'dataset_id'                   => 'm12-sim-v1',
		'authorising_party'            => 'upr-maintainers-simulation',
		'consent_or_authorisation_ref' => 'm12-simulation-go-synthetic-fixtures',
		'source_class'                 => 'synthetic_authorised',
		'source_description'           => 'Synthetic technical-spam and legitimate-critical scenarios for Simulation GO only. Includes inert prompt-injection plain-text scenario tags. Not customer data.',
		'labelled_at'                  => gmdate( 'c' ),
		'reviewer_count'               => 1,
	),
	'holdout_lock'                 => array(
		'locked_before_labelling_complete' => true,
		'locked_before_tuning'             => true,
		'assignment_sha256'                => $assignment_sha,
		'assignments'                      => $assignments,
	),
	'dataset_hashes'               => array(
		'rows_sha256'        => sha256_hex( canonical_json( $rows_for_hash ) ),
		'labels_sha256'      => sha256_hex( canonical_json( $labels_only ) ),
		'assessments_sha256' => sha256_hex( canonical_json( $assess_only ) ),
	),
	'privacy'                      => array(
		'review_bodies_committed'        => false,
		'secrets_committed'              => false,
		'customer_identifiers_committed' => false,
		'note'                           => 'Opaque ids + assessment field maps + simulation_scenario tags only.',
	),
	'strata'                       => array(
		'legitimate_negative' => array( 'rows' => $legit_rows ),
		'technical_spam'      => array( 'rows' => $spam_rows ),
		'mandatory_human'     => array( 'rows' => $mh_rows ),
		'excluded'            => array( 'rows' => array() ),
	),
);

$dir = dirname( $out );
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0755, true );
}

$json = json_encode( $doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( false === $json ) {
	fwrite( STDERR, "json_encode failed\n" );
	exit( 1 );
}
file_put_contents( $out, $json . "\n" );
fwrite( STDOUT, "Wrote {$out}\n" );
fwrite( STDOUT, 'legit=' . LEGIT_N . ' spam=' . SPAM_N . " mh=40\n" );
fwrite( STDOUT, 'assignment_sha256=' . $assignment_sha . "\n" );
