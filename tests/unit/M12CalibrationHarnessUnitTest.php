<?php
/**
 * M12 offline calibration harness unit tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests;

use PHPUnit\Framework\TestCase;
use UniversalProductReviews\Calibration\EvidenceEvaluator;
use UniversalProductReviews\Calibration\WilsonInterval;
use UniversalProductReviews\Calibration\WouldActEvaluator;

require_once dirname( __DIR__, 2 ) . '/scripts/calibration/src/WilsonInterval.php';
require_once dirname( __DIR__, 2 ) . '/scripts/calibration/src/WouldActEvaluator.php';
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
					'state'                     => 'completed',
					'confidence'                => 'high',
					'publication_safety_score'  => 80,
					'reason_codes'              => array( 'spam_pattern' ),
				)
			)
		);
		$this->assertFalse(
			WouldActEvaluator::would_act(
				array(
					'state'                     => 'completed',
					'confidence'                => 'high',
					'publication_safety_score'  => 90,
					'reason_codes'              => array( 'pii_suspected', 'spam_pattern' ),
				)
			)
		);
		$this->assertFalse(
			WouldActEvaluator::would_act(
				array(
					'state'                     => 'completed',
					'confidence'                => 'high',
					'publication_safety_score'  => 99,
					'reason_codes'              => array(),
				)
			)
		);
	}

	public function test_empty_example_fixture_is_nogo(): void {
		$path = dirname( __DIR__, 2 ) . '/scripts/calibration/fixtures/empty-corpus.example.json';
		$doc  = json_decode( (string) file_get_contents( $path ), true );
		$this->assertIsArray( $doc );
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( 'NO-GO', $result['verdict'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_rejects_review_body_fields(): void {
		$doc = array(
			'schema_version'                 => EvidenceEvaluator::SCHEMA_VERSION,
			'holdout_locked_before_tuning'   => true,
			'tuple'                          => $this->example_tuple(),
			'strata'                         => array(
				'legitimate_negative' => array(
					'rows' => array(
						array(
							'id'           => 'x1',
							'human_label'  => 'not_spam',
							'split'        => 'holdout',
							'review_body'  => 'forbidden',
							'assessment'   => array(
								'state'                    => 'completed',
								'confidence'               => 'high',
								'publication_safety_score' => 10,
								'reason_codes'             => array(),
							),
						),
					),
				),
				'technical_spam'      => array( 'rows' => array() ),
			),
		);
		$result = EvidenceEvaluator::evaluate( $doc );
		$this->assertSame( 'NO-GO', $result['verdict'] );
		$this->assertTrue(
			(bool) array_filter(
				$result['errors'],
				static fn( $e ) => is_string( $e ) && false !== strpos( $e, 'must not include review body' )
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function example_tuple(): array {
		return array(
			'provider_kind'                   => 'local',
			'assessor_version'                => 't',
			'heuristic_or_model_fingerprint'  => 't',
			'validator_version'               => 't',
			'assessment_policy_version'       => 't',
			'recommendation_policy_version'   => '2026-08-rec-v1',
			'action_policy_version'           => 't',
		);
	}
}
