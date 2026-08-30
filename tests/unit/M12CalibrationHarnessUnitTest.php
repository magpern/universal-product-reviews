<?php
/**
 * M12 offline calibration / simulation harness unit tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Calibration\EvidenceDocumentParser;
use UniversalProductReviews\Calibration\EvidenceEvaluator;
use UniversalProductReviews\Calibration\WilsonInterval;
use UniversalProductReviews\Calibration\WouldActEvaluator;

require_once dirname( __DIR__, 2 ) . '/scripts/calibration/src/WilsonInterval.php';
require_once dirname( __DIR__, 2 ) . '/scripts/calibration/src/WouldActEvaluator.php';
require_once dirname( __DIR__, 2 ) . '/scripts/calibration/src/EvidenceDocumentParser.php';
require_once dirname( __DIR__, 2 ) . '/scripts/calibration/src/EvidenceEvaluator.php';

final class M12CalibrationHarnessUnitTest extends TestCase {

	public function test_wilson_ucb_zero_failures_shrinks_with_n(): void {
		$small = WilsonInterval::upper_bound_95( 0, 100 );
		$large = WilsonInterval::upper_bound_95( 0, 400 );
		$this->assertLessThan( 0.05, $small );
		$this->assertLessThan( $small, $large );
		$this->assertLessThanOrEqual( 0.01, WilsonInterval::upper_bound_95( 0, 400 ) );
	}

	public function test_would_act_matches_frozen_conjunction(): void {
		$this->assertTrue(
			WouldActEvaluator::would_act(
				array(
					'state'                    => 'completed',
					'confidence'               => 'high',
					'publication_safety_score' => 80,
					'reason_codes'             => array( 'spam_pattern' ),
				)
			)
		);
		$this->assertFalse(
			WouldActEvaluator::would_act(
				array(
					'state'                    => 'completed',
					'confidence'               => 'high',
					'publication_safety_score' => 90,
					'reason_codes'             => array( 'pii_suspected', 'spam_pattern' ),
				)
			)
		);
	}

	public function test_empty_example_fixture_is_nogo(): void {
		$path = dirname( __DIR__, 2 ) . '/scripts/calibration/fixtures/empty-corpus.example.json';
		$doc  = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $doc );
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'] );
		$this->assertFalse( $result['production_enablement_authorised'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_manifest_template_is_nogo(): void {
		$path = dirname( __DIR__, 2 ) . '/scripts/calibration/evidence-kit/templates/manifest.template.json';
		$doc  = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $doc );
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'] );
		$this->assertTrue( $this->has_error_containing( $result, 'template' ) );
	}

	public function test_rejects_review_body_fields(): void {
		$doc = $this->base_doc();
		$doc['strata']['legitimate_negative']['rows'][] = array(
			'id'          => 'sampleid01',
			'human_label' => 'not_spam',
			'split'       => 'holdout',
			'review_body' => 'forbidden',
			'assessment'  => array(
				'state'                    => 'completed',
				'confidence'               => 'high',
				'publication_safety_score' => 10,
				'reason_codes'             => array(),
			),
		);
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'] );
		$this->assertTrue( $this->has_error_containing( $result, 'forbidden field' ) );
	}

	public function test_missing_provenance_is_nogo(): void {
		$doc = $this->base_doc();
		unset( $doc['provenance'] );
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'] );
		$this->assertTrue( $this->has_error_containing( $result, 'provenance missing' ) );
	}

	public function test_insufficient_class_counts_nogo(): void {
		$doc = $this->minimal_authorised_shell();
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'] );
		$this->assertTrue( $this->has_error_containing( $result, 'legitimate_negative corpus size' ) );
		$this->assertTrue( $this->has_error_containing( $result, 'technical_spam corpus size' ) );
	}

	public function test_holdout_contamination_nogo(): void {
		$doc = $this->minimal_authorised_shell();
		$doc['strata']['legitimate_negative']['rows'][] = $this->row( 'legit0001', 'not_spam', 'train', false );
		$doc['holdout_lock']['assignments'] = array( 'legit0001' => 'holdout' );
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'] );
		$this->assertTrue( $this->has_error_containing( $result, 'holdout contamination' ) );
	}

	public function test_duplicate_sample_ids_nogo(): void {
		$doc = $this->minimal_authorised_shell();
		$doc['strata']['legitimate_negative']['rows'][] = $this->row( 'dupesamp1', 'not_spam', 'holdout', false );
		$doc['strata']['technical_spam']['rows'][]      = $this->row( 'dupesamp1', 'technical_spam', 'holdout', false, array( 'spam_pattern' ), 90 );
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'] );
		$this->assertTrue( $this->has_error_containing( $result, 'duplicate sample id' ) );
	}

	public function test_unrecognised_labels_nogo(): void {
		$doc = $this->minimal_authorised_shell();
		$row = $this->row( 'badlabel1', 'not_spam', 'holdout', false );
		$row['human_label'] = 'maybe_spam';
		$doc['strata']['legitimate_negative']['rows'][] = $row;
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'] );
		$this->assertTrue( $this->has_error_containing( $result, 'unrecognised human_label' ) );
	}

	public function test_missing_double_label_evidence_nogo(): void {
		$doc = $this->minimal_authorised_shell();
		$doc['strata']['legitimate_negative']['rows'][] = $this->row( 'legit0001', 'not_spam', 'holdout', true );
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'] );
		$this->assertTrue( $this->has_error_containing( $result, 'double_labelled requires double_label' ) );
	}

	public function test_missing_tuple_fields_nogo(): void {
		$doc = $this->base_doc();
		unset( $doc['tuple']['action_policy_version'] );
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'] );
		$this->assertTrue( $this->has_error_containing( $result, 'tuple.action_policy_version' ) );
	}

	public function test_template_synthetic_example_cannot_produce_either_go(): void {
		foreach ( array( 'example', 'synthetic', 'template', 'incomplete', 'draft' ) as $status ) {
			$doc = $this->minimal_authorised_shell();
			$doc['evidence_status'] = $status;
			$result = EvidenceEvaluator::evaluate( $doc );
			$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'], $status );
			$this->assertFalse( $result['production_enablement_authorised'] );
			$this->assertTrue( $this->has_error_containing( $result, 'cannot produce Simulation GO or Calibration GO' ), $status );
		}
	}

	public function test_synthetic_simulation_never_calibration_go(): void {
		$doc = $this->passing_synthetic_simulation_corpus();
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_SIMULATION, $result['verdict'] );
		$this->assertNotSame( EvidenceEvaluator::VERDICT_CALIBRATION, $result['verdict'] );
		$this->assertFalse( $result['production_enablement_authorised'] );
		$this->assertFalse( $result['authorises']['production_enablement'] );
		$this->assertTrue( $result['authorises']['implementation_masters_default_off'] );
		$this->assertTrue( $result['authorises']['dev_preprod_synthetic_testing'] );
	}

	public function test_authorised_labelled_never_auto_production_permission(): void {
		$doc = $this->passing_calibration_corpus();
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_CALIBRATION, $result['verdict'] );
		$this->assertFalse( $result['production_enablement_authorised'] );
		$this->assertFalse( $result['authorises']['production_enablement'] );
		$this->assertTrue( $result['authorises']['production_enablement_may_be_considered'] );
	}

	public function test_threshold_failure_returns_nogo(): void {
		$doc = $this->minimal_authorised_shell();
		$doc['strata']['legitimate_negative']['rows'][] = $this->row(
			'legit0001',
			'not_spam',
			'holdout',
			true,
			array( 'spam_pattern' ),
			95,
			$this->double_label( 'not_spam', 'not_spam' )
		);
		$doc['holdout_lock']['assignments'] = array( 'legit0001' => 'holdout' );
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( EvidenceEvaluator::VERDICT_NOGO, $result['verdict'] );
	}

	public function test_parser_rejects_placeholder_tuple(): void {
		$parsed = EvidenceDocumentParser::parse( $this->base_doc( array( 'evidence_status' => 'authorised_labelled' ) ) );
		$this->assertFalse( $parsed['ok'] );
		$this->assertNotEmpty(
			array_filter(
				$parsed['errors'],
				static fn( $e ) => is_string( $e ) && false !== strpos( $e, 'placeholder' )
			)
		);
	}

	/**
	 * @param array<string,mixed> $result Result.
	 */
	private function has_error_containing( array $result, string $needle ): bool {
		foreach ( $result['errors'] as $e ) {
			if ( is_string( $e ) && false !== strpos( $e, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $overrides Overrides.
	 * @return array<string,mixed>
	 */
	private function base_doc( array $overrides = array() ): array {
		$doc = array(
			'schema_version'               => EvidenceEvaluator::SCHEMA_VERSION,
			'evidence_status'              => 'example',
			'holdout_locked_before_tuning' => true,
			'tuple'                        => $this->example_tuple(),
			'provenance'                   => array(
				'dataset_id'                   => 'example-dataset',
				'authorising_party'            => 'example-party',
				'consent_or_authorisation_ref' => 'example-ref',
				'source_class'                 => 'synthetic_authorised',
				'labelled_at'                  => '1970-01-01T00:00:00Z',
				'reviewer_count'               => 2,
			),
			'holdout_lock'                 => array(
				'locked_before_tuning' => true,
				'assignment_sha256'    => str_repeat( '0', 64 ),
				'assignments'          => array(),
			),
			'dataset_hashes'               => array(
				'rows_sha256'        => str_repeat( '0', 64 ),
				'labels_sha256'      => str_repeat( '0', 64 ),
				'assessments_sha256' => str_repeat( '0', 64 ),
			),
			'privacy'                      => array(
				'review_bodies_committed'        => false,
				'secrets_committed'              => false,
				'customer_identifiers_committed' => false,
			),
			'strata'                       => array(
				'legitimate_negative' => array( 'rows' => array() ),
				'technical_spam'      => array( 'rows' => array() ),
				'mandatory_human'     => array( 'rows' => array() ),
				'excluded'            => array( 'rows' => array() ),
			),
		);
		return array_replace_recursive( $doc, $overrides );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function minimal_authorised_shell(): array {
		$doc = $this->base_doc(
			array(
				'evidence_status' => 'authorised_labelled',
				'tuple'           => $this->real_tuple(),
				'provenance'      => array(
					'source_class'   => 'operator_authorised_historical',
					'reviewer_count' => 2,
				),
			)
		);
		$doc['holdout_lock']['assignment_sha256'] = str_repeat( 'ab', 32 );
		$doc['dataset_hashes'] = array(
			'rows_sha256'        => str_repeat( 'cd', 32 ),
			'labels_sha256'      => str_repeat( 'ef', 32 ),
			'assessments_sha256' => str_repeat( '12', 32 ),
		);
		return $doc;
	}

	/**
	 * Programmatic privacy-safe synthetic corpus (not committed as evidence).
	 *
	 * @return array<string,mixed>
	 */
	private function passing_synthetic_simulation_corpus(): array {
		$doc = $this->base_doc(
			array(
				'evidence_status' => 'synthetic_simulation',
				'tuple'           => $this->real_tuple(),
				'provenance'      => array(
					'dataset_id'                   => 'sim-unit-test',
					'authorising_party'            => 'unit-test',
					'consent_or_authorisation_ref' => 'unit-test-sim',
					'source_class'                 => 'synthetic_authorised',
					'reviewer_count'               => 1,
				),
			)
		);
		$doc['holdout_lock']['assignment_sha256'] = str_repeat( 'a1', 32 );
		$doc['dataset_hashes'] = array(
			'rows_sha256'        => str_repeat( 'b2', 32 ),
			'labels_sha256'      => str_repeat( 'c3', 32 ),
			'assessments_sha256' => str_repeat( 'd4', 32 ),
		);
		$assignments = array();
		// Need holdout n large enough that Wilson UCB(0,n) ≤ 1% (n≈400).
		for ( $i = 0; $i < 2000; $i++ ) {
			$id    = sprintf( 'legit%04d', $i );
			$split = ( $i % 5 === 0 ) ? 'holdout' : 'train';
			$dbl   = ( $i % 5 === 0 );
			$doc['strata']['legitimate_negative']['rows'][] = $this->row(
				$id,
				'not_spam',
				$split,
				$dbl,
				array(),
				20,
				$dbl ? $this->double_label( 'not_spam', 'not_spam' ) : null
			);
			$assignments[ $id ] = $split;
		}
		for ( $i = 0; $i < 200; $i++ ) {
			$id    = sprintf( 'spam%04dxx', $i );
			$split = ( $i % 5 === 0 ) ? 'holdout' : 'train';
			$dbl   = ( $i % 5 === 0 );
			$doc['strata']['technical_spam']['rows'][] = $this->row(
				$id,
				'technical_spam',
				$split,
				$dbl,
				array( 'spam_pattern' ),
				90,
				$dbl ? $this->double_label( 'technical_spam', 'technical_spam' ) : null
			);
			$assignments[ $id ] = $split;
		}
		$doc['holdout_lock']['assignments'] = $assignments;
		return $doc;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function passing_calibration_corpus(): array {
		$doc = $this->passing_synthetic_simulation_corpus();
		$doc['evidence_status'] = 'authorised_labelled';
		$doc['provenance']['source_class'] = 'operator_authorised_historical';
		$doc['provenance']['reviewer_count'] = 2;
		$doc['provenance']['dataset_id'] = 'cal-unit-test';
		return $doc;
	}

	/**
	 * @param list<string>             $codes Codes.
	 * @param array<string,mixed>|null $dl    Double label.
	 * @return array<string,mixed>
	 */
	private function row(
		string $id,
		string $label,
		string $split,
		bool $double,
		array $codes = array(),
		int $score = 20,
		?array $dl = null
	): array {
		$row = array(
			'id'              => $id,
			'human_label'     => $label,
			'split'           => $split,
			'double_labelled' => $double,
			'assessment'      => array(
				'state'                    => 'completed',
				'confidence'               => 'high',
				'publication_safety_score' => $score,
				'reason_codes'             => $codes,
			),
		);
		if ( null !== $dl ) {
			$row['double_label'] = $dl;
		}
		return $row;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function double_label( string $a, string $b ): array {
		$out = array(
			'labeler_a' => 'revA',
			'labeler_b' => 'revB',
			'label_a'   => $a,
			'label_b'   => $b,
		);
		if ( $a !== $b ) {
			$out['adjudicated_label'] = $a;
			$out['adjudicator']       = 'revC';
		}
		return $out;
	}

	/**
	 * @return array<string,string>
	 */
	private function example_tuple(): array {
		return array(
			'provider_kind'                  => 'local',
			'assessor_version'               => 'example-unset',
			'heuristic_or_model_fingerprint' => 'example-unset',
			'validator_version'              => 'example-unset',
			'assessment_policy_version'      => 'example-unset',
			'recommendation_policy_version'  => '2026-08-rec-v1',
			'action_policy_version'          => 'example-unset',
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function real_tuple(): array {
		return array(
			'provider_kind'                  => 'local',
			'assessor_version'               => 'builtin-2026-08',
			'heuristic_or_model_fingerprint' => 'heuristic-v1',
			'validator_version'              => 'validator-v1',
			'assessment_policy_version'      => 'assess-v1',
			'recommendation_policy_version'  => '2026-08-rec-v1',
			'action_policy_version'          => 'action-v1',
		);
	}
}
