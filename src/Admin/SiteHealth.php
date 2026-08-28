<?php
/**
 * WordPress Site Health tests for UPR (no sensitive details).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

use UniversalProductReviews\Admin\Diagnostics\IntegrationReadiness;
use UniversalProductReviews\Ai\ModerationOpsRepository;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\WooCommerce\WooCommerceGate;

defined( 'ABSPATH' ) || exit;

final class SiteHealth {

	public static function register(): void {
		add_filter( 'site_status_tests', array( self::class, 'register_tests' ) );
	}

	/**
	 * @param array<string, mixed> $tests
	 * @return array<string, mixed>
	 */
	public static function register_tests( array $tests ): array {
		if ( ! isset( $tests['direct'] ) || ! is_array( $tests['direct'] ) ) {
			$tests['direct'] = array();
		}

		$tests['direct']['upr_schema'] = array(
			'label' => __( 'Universal Product Reviews schema', 'universal-product-reviews' ),
			'test'  => array( self::class, 'test_schema' ),
		);
		$tests['direct']['upr_woocommerce'] = array(
			'label' => __( 'Universal Product Reviews WooCommerce', 'universal-product-reviews' ),
			'test'  => array( self::class, 'test_woocommerce' ),
		);
		$tests['direct']['upr_action_scheduler'] = array(
			'label' => __( 'Universal Product Reviews Action Scheduler', 'universal-product-reviews' ),
			'test'  => array( self::class, 'test_action_scheduler' ),
		);
		$tests['direct']['upr_emergency_pause'] = array(
			'label' => __( 'Universal Product Reviews emergency pause', 'universal-product-reviews' ),
			'test'  => array( self::class, 'test_emergency_pause' ),
		);
		$tests['direct']['upr_invitation_emails'] = array(
			'label' => __( 'Universal Product Reviews invitation emails', 'universal-product-reviews' ),
			'test'  => array( self::class, 'test_invitation_emails' ),
		);
		$tests['direct']['upr_integration_readiness'] = array(
			'label' => __( 'Universal Product Reviews integration readiness', 'universal-product-reviews' ),
			'test'  => array( self::class, 'test_integration_readiness' ),
		);
		$tests['direct']['upr_local_ai_shadow'] = array(
			'label' => __( 'Universal Product Reviews local AI shadow', 'universal-product-reviews' ),
			'test'  => array( self::class, 'test_local_ai_shadow' ),
		);

		return $tests;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function test_schema(): array {
		$ok = ! Migrator::needs_upgrade();

		return array(
			'label'       => __( 'UPR database schema', 'universal-product-reviews' ),
			'status'      => $ok ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'Performance', 'universal-product-reviews' ),
				'color' => $ok ? 'blue' : 'orange',
			),
			'description' => $ok
				? '<p>' . esc_html__( 'UPR database schema version matches the plugin target and required tables exist.', 'universal-product-reviews' ) . '</p>'
				: '<p>' . esc_html__( 'UPR database schema is behind target or tables are missing. Run the controlled database upgrade from Product Reviews → Controls.', 'universal-product-reviews' ) . '</p>',
			'actions'     => '',
			'test'        => 'upr_schema',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function test_woocommerce(): array {
		$ok = WooCommerceGate::is_active();

		return array(
			'label'       => __( 'WooCommerce for UPR', 'universal-product-reviews' ),
			'status'      => $ok ? 'good' : 'critical',
			'badge'       => array(
				'label' => __( 'Security', 'universal-product-reviews' ),
				'color' => $ok ? 'blue' : 'red',
			),
			'description' => $ok
				? '<p>' . esc_html__( 'WooCommerce is active.', 'universal-product-reviews' ) . '</p>'
				: '<p>' . esc_html__( 'WooCommerce must be active for Universal Product Reviews.', 'universal-product-reviews' ) . '</p>',
			'actions'     => '',
			'test'        => 'upr_woocommerce',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function test_action_scheduler(): array {
		$ok = function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' );

		return array(
			'label'       => __( 'Action Scheduler for UPR', 'universal-product-reviews' ),
			'status'      => $ok ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'Performance', 'universal-product-reviews' ),
				'color' => $ok ? 'blue' : 'orange',
			),
			'description' => $ok
				? '<p>' . esc_html__( 'Action Scheduler APIs required by UPR are present.', 'universal-product-reviews' ) . '</p>'
				: '<p>' . esc_html__( 'Action Scheduler enqueue APIs are missing. Invitation jobs may not schedule.', 'universal-product-reviews' ) . '</p>',
			'actions'     => '',
			'test'        => 'upr_action_scheduler',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function test_emergency_pause(): array {
		$paused = Options::invitation_emergency_pause();

		return array(
			'label'       => __( 'UPR emergency pause', 'universal-product-reviews' ),
			'status'      => $paused ? 'recommended' : 'good',
			'badge'       => array(
				'label' => __( 'Security', 'universal-product-reviews' ),
				'color' => $paused ? 'orange' : 'blue',
			),
			'description' => $paused
				? '<p>' . esc_html__( 'Emergency pause is active. Invitation emails will not send.', 'universal-product-reviews' ) . '</p>'
				: '<p>' . esc_html__( 'Emergency pause is off.', 'universal-product-reviews' ) . '</p>',
			'actions'     => '',
			'test'        => 'upr_emergency_pause',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function test_invitation_emails(): array {
		$enabled = Options::invitation_emails_enabled();

		return array(
			'label'       => __( 'UPR invitation emails', 'universal-product-reviews' ),
			'status'      => $enabled ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'Performance', 'universal-product-reviews' ),
				'color' => 'blue',
			),
			'description' => $enabled
				? '<p>' . esc_html__( 'Invitation emails are enabled.', 'universal-product-reviews' ) . '</p>'
				: '<p>' . esc_html__( 'Invitation emails are disabled (informational; fail-closed default).', 'universal-product-reviews' ) . '</p>',
			'actions'     => '',
			'test'        => 'upr_invitation_emails',
		);
	}

	/**
	 * Non-critical advisory summary of I1–I5 (no sensitive evidence).
	 *
	 * @return array<string, mixed>
	 */
	public static function test_integration_readiness(): array {
		$codes = array();
		try {
			foreach ( IntegrationReadiness::run() as $row ) {
				$codes[] = (string) ( $row['id'] ?? '' ) . '=' . (string) ( $row['evidence_code'] ?? '' );
			}
		} catch ( \Throwable $e ) {
			$codes = array( 'unavailable' );
		}

		$summary = implode( '; ', $codes );

		return array(
			'label'       => __( 'UPR integration readiness', 'universal-product-reviews' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'universal-product-reviews' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Advisory wiring signals only (I1–I5). Not operational proof of delivery or mail.', 'universal-product-reviews' ) . '</p><p><code>' . esc_html( $summary ) . '</code></p>',
			'actions'     => '',
			'test'        => 'upr_integration_readiness',
		);
	}

	/**
	 * Informational summary of shadow enablement and moderation ops circuit evidence.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_local_ai_shadow(): array {
		$enabled = Options::local_ai_shadow_enabled();
		$codes   = array( $enabled ? 'shadow_enabled' : 'shadow_disabled' );

		try {
			$ops = ModerationOpsRepository::summarize();
			if ( empty( $ops['ok'] ) ) {
				$codes[] = 'ops_unavailable';
			} elseif ( ! empty( $ops['circuit_open'] ) ) {
				$codes[] = 'circuit_open';
			} elseif ( ! empty( $ops['rate_limited'] ) ) {
				$codes[] = 'rate_limited';
			} else {
				$codes[] = 'ops_ok';
			}
		} catch ( \Throwable $e ) {
			$codes[] = 'ops_unavailable';
		}

		$summary = implode( '; ', $codes );

		return array(
			'label'       => __( 'UPR local AI shadow', 'universal-product-reviews' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Performance', 'universal-product-reviews' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'Advisory local-only shadow assessments (informational). Does not auto-moderate reviews.', 'universal-product-reviews' ) . '</p><p><code>' . esc_html( $summary ) . '</code></p>',
			'actions'     => '',
			'test'        => 'upr_local_ai_shadow',
		);
	}
}
