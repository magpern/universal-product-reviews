<?php
/**
 * Overview tab renderer.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Invitations\EmergencyPause;

defined( 'ABSPATH' ) || exit;

final class OverviewPage {

	public static function render(): void {
		$enabled = Options::invitation_emails_enabled();
		$paused  = Options::invitation_emergency_pause();
		$meta    = EmergencyPause::meta();
		$boundary_set = Options::invitation_scheduling_boundary_unix() > 0;

		$open = array( 'ok' => false, 'by_state' => array(), 'total_open' => 0 );
		try {
			$open = OverviewRepository::open_workload_counts();
		} catch ( \Throwable $e ) {
			$open = array( 'ok' => false, 'by_state' => array(), 'total_open' => 0 );
		}

		$lifecycle = array( 'ok' => false, 'by_state' => array() );
		try {
			$lifecycle = OverviewRepository::recent_lifecycle_counts();
		} catch ( \Throwable $e ) {
			$lifecycle = array( 'ok' => false, 'by_state' => array() );
		}

		$last = array( 'found' => false );
		try {
			$last = OverviewRepository::last_reconcile_completed();
		} catch ( \Throwable $e ) {
			$last = array( 'found' => false );
		}

		$audit = array();
		try {
			$audit = OverviewRepository::recent_audit_allowlisted();
		} catch ( \Throwable $e ) {
			$audit = array();
		}

		$controls_url = add_query_arg(
			array(
				'page' => SettingsPage::MENU_SLUG,
				'tab'  => 'controls',
			),
			admin_url( 'admin.php' )
		);
		$diag_url     = add_query_arg(
			array(
				'page' => SettingsPage::MENU_SLUG,
				'tab'  => 'diagnostics',
			),
			admin_url( 'admin.php' )
		);
		?>
		<div class="upr-overview">
			<h2><?php echo esc_html__( 'Overview', 'universal-product-reviews' ); ?></h2>

			<h3><?php echo esc_html__( 'Control status', 'universal-product-reviews' ); ?></h3>
			<ul>
				<li>
					<?php
					echo $enabled
						? esc_html__( 'Invitation emails: enabled', 'universal-product-reviews' )
						: esc_html__( 'Invitation emails: disabled', 'universal-product-reviews' );
					?>
				</li>
				<li>
					<?php
					echo $paused
						? esc_html__( 'Emergency pause: active', 'universal-product-reviews' )
						: esc_html__( 'Emergency pause: off', 'universal-product-reviews' );
					?>
					<?php if ( $paused && $meta['changed_at'] > 0 ) : ?>
						<span class="description">
							(<?php echo esc_html( sprintf( /* translators: %d: unix timestamp */ __( 'since %d', 'universal-product-reviews' ), $meta['changed_at'] ) ); ?>)
						</span>
					<?php endif; ?>
				</li>
				<li>
					<?php echo esc_html__( 'Sending not authorised: host filter may still deny (not toggled here).', 'universal-product-reviews' ); ?>
				</li>
				<li>
					<?php
					echo $boundary_set
						? esc_html__( 'Scheduling boundary: set', 'universal-product-reviews' )
						: esc_html__( 'Scheduling boundary: unset', 'universal-product-reviews' );
					?>
				</li>
			</ul>

			<p>
				<a class="button" href="<?php echo esc_url( $controls_url ); ?>"><?php echo esc_html__( 'Controls', 'universal-product-reviews' ); ?></a>
				<a class="button" href="<?php echo esc_url( $diag_url ); ?>"><?php echo esc_html__( 'Diagnostics', 'universal-product-reviews' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'site-health.php' ) ); ?>"><?php echo esc_html__( 'Site Health', 'universal-product-reviews' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings' ) ); ?>"><?php echo esc_html__( 'WooCommerce settings', 'universal-product-reviews' ); ?></a>
			</p>

			<h3><?php echo esc_html__( 'Open workload', 'universal-product-reviews' ); ?></h3>
			<?php if ( empty( $open['ok'] ) ) : ?>
				<p><?php echo esc_html__( 'Open workload counts unavailable.', 'universal-product-reviews' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: open invitation row count */
							__( 'Total open (non-terminal) invitation rows: %d', 'universal-product-reviews' ),
							(int) $open['total_open']
						)
					);
					?>
				</p>
				<?php if ( ! empty( $open['by_state'] ) ) : ?>
					<ul>
						<?php foreach ( $open['by_state'] as $state => $count ) : ?>
							<li><?php echo esc_html( $state . ': ' . (int) $count ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>

			<h3><?php echo esc_html__( 'Recent lifecycle activity (30 days)', 'universal-product-reviews' ); ?></h3>
			<?php if ( empty( $lifecycle['ok'] ) ) : ?>
				<p><?php echo esc_html__( 'Lifecycle activity unavailable.', 'universal-product-reviews' ); ?></p>
			<?php elseif ( empty( $lifecycle['by_state'] ) ) : ?>
				<p><?php echo esc_html__( 'No recent lifecycle updates.', 'universal-product-reviews' ); ?></p>
			<?php else : ?>
				<ul>
					<?php foreach ( $lifecycle['by_state'] as $state => $count ) : ?>
						<li><?php echo esc_html( $state . ': ' . (int) $count ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h3><?php echo esc_html__( 'Last reconciliation', 'universal-product-reviews' ); ?></h3>
			<?php if ( empty( $last['found'] ) ) : ?>
				<p><?php echo esc_html__( 'No recorded run.', 'universal-product-reviews' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: UTC datetime */
							__( 'Occurred at (UTC): %s', 'universal-product-reviews' ),
							(string) $last['occurred_at']
						)
					);
					?>
				</p>
				<?php if ( ! empty( $last['counters'] ) && is_array( $last['counters'] ) ) : ?>
					<ul>
						<?php foreach ( $last['counters'] as $key => $value ) : ?>
							<li><?php echo esc_html( $key . ': ' . (int) $value ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endif; ?>

			<h3><?php echo esc_html__( 'Recent audit', 'universal-product-reviews' ); ?></h3>
			<?php if ( empty( $audit ) ) : ?>
				<p><?php echo esc_html__( 'No recent audit rows.', 'universal-product-reviews' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Occurred (UTC)', 'universal-product-reviews' ); ?></th>
							<th><?php echo esc_html__( 'Event', 'universal-product-reviews' ); ?></th>
							<th><?php echo esc_html__( 'Actor', 'universal-product-reviews' ); ?></th>
							<th><?php echo esc_html__( 'Order ID', 'universal-product-reviews' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $audit as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $row['occurred_at'] ); ?></td>
								<td><?php echo esc_html( (string) $row['event_type'] ); ?></td>
								<td><?php echo esc_html( (string) $row['actor_type'] ); ?></td>
								<td><?php echo null === $row['order_id'] ? '—' : esc_html( (string) (int) $row['order_id'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
