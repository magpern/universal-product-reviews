<?php
/**
 * Diagnostics tab renderer (D1–D11 cached ops + uncached I1–I5 readiness).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

use UniversalProductReviews\Admin\Diagnostics\DiagnosticsService;
use UniversalProductReviews\Admin\Diagnostics\IntegrationReadiness;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsPage {

	public static function render(): void {
		$results = array();
		try {
			$results = DiagnosticsService::run();
		} catch ( \Throwable $e ) {
			$results = array();
		}

		$readiness = array();
		try {
			$readiness = IntegrationReadiness::run();
		} catch ( \Throwable $e ) {
			$readiness = array();
		}

		$controls_url = add_query_arg(
			array(
				'page' => SettingsPage::MENU_SLUG,
				'tab'  => 'controls',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="upr-diagnostics">
			<h2><?php echo esc_html__( 'Diagnostics', 'universal-product-reviews' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'D1–D11 operator checks (cached ≤ 60s). Unavailable means evidence could not be loaded (not critical).', 'universal-product-reviews' ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( $controls_url ); ?>"><?php echo esc_html__( 'Safe actions (Controls)', 'universal-product-reviews' ); ?></a>
			</p>

			<?php self::render_table( $results, __( 'No operator diagnostics available.', 'universal-product-reviews' ) ); ?>

			<h2><?php echo esc_html__( 'Integration readiness', 'universal-product-reviews' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'I1–I5 advisory wiring signals (always fresh; not cached). Not operational proof. Not included in support export.', 'universal-product-reviews' ); ?>
			</p>
			<?php self::render_table( $readiness, __( 'Integration readiness unavailable.', 'universal-product-reviews' ) ); ?>
		</div>
		<?php
	}

	/**
	 * @param list<array{id?:string,status?:string,severity?:string,message?:string,evidence_code?:string}> $rows
	 */
	private static function render_table( array $rows, string $empty_message ): void {
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html( $empty_message ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'ID', 'universal-product-reviews' ); ?></th>
					<th><?php echo esc_html__( 'Status', 'universal-product-reviews' ); ?></th>
					<th><?php echo esc_html__( 'Severity', 'universal-product-reviews' ); ?></th>
					<th><?php echo esc_html__( 'Message', 'universal-product-reviews' ); ?></th>
					<th><?php echo esc_html__( 'Evidence', 'universal-product-reviews' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( (string) ( $row['id'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['status'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['severity'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $row['message'] ?? '' ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $row['evidence_code'] ?? '' ) ); ?></code></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
