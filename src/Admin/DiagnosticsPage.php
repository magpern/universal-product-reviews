<?php
/**
 * Diagnostics tab renderer.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

use UniversalProductReviews\Admin\Diagnostics\DiagnosticsService;

defined( 'ABSPATH' ) || exit;

final class DiagnosticsPage {

	public static function render(): void {
		$results = array();
		try {
			$results = DiagnosticsService::run();
		} catch ( \Throwable $e ) {
			$results = array();
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
				<?php echo esc_html__( 'D1–D11 operator checks. Unavailable means evidence could not be loaded (not critical).', 'universal-product-reviews' ); ?>
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( $controls_url ); ?>"><?php echo esc_html__( 'Safe actions (Controls)', 'universal-product-reviews' ); ?></a>
			</p>

			<?php if ( empty( $results ) ) : ?>
				<p><?php echo esc_html__( 'Diagnostics unavailable.', 'universal-product-reviews' ); ?></p>
			<?php else : ?>
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
						<?php foreach ( $results as $row ) : ?>
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
			<?php endif; ?>
		</div>
		<?php
	}
}
