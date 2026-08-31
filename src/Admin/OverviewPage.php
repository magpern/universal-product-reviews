<?php
/**
 * Overview tab renderer.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

use UniversalProductReviews\Ai\ActionLedgerRepository;
use UniversalProductReviews\Ai\ActionPolicy;
use UniversalProductReviews\Ai\AssessmentRepository;
use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Invitations\EmergencyPause;
use UniversalProductReviews\Moderation\CommentListEnhancements;

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

			<?php self::render_ai_moderation_posture(); ?>

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

	/**
	 * Privacy-safe AI moderation posture (counts/booleans only — no PII).
	 */
	private static function render_ai_moderation_posture(): void {
		$master    = Options::ai_auto_spam_enabled();
		$policy    = Options::ai_auto_spam_policy_enabled();
		$sim       = Options::ai_auto_spam_simulation_guard_enabled();
		$kill      = Options::ai_auto_spam_kill_switch();
		$dry       = Options::ai_auto_spam_dry_run();
		$boundary  = Options::ai_auto_action_boundary_unix() > 0;
		$ledger    = array();
		$unknown   = 0;
		$assess_24 = array();

		try {
			$ledger  = ActionLedgerRepository::counts_by_state();
			$unknown = (int) ( $ledger['unknown_after_crash'] ?? 0 );
		} catch ( \Throwable $e ) {
			$ledger = array();
		}

		try {
			$assess_24 = AssessmentRepository::count_states_24h();
		} catch ( \Throwable $e ) {
			$assess_24 = array();
		}

		$would_act_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=upr_would_act_report' ),
			'upr_would_act_report'
		);
		?>
		<h3><?php echo esc_html__( 'AI moderation posture', 'universal-product-reviews' ); ?></h3>
		<p class="description">
			<?php echo esc_html__( 'Operator surface only. Masters remain default-off. Does not enable auto-spam. Production automatic moderation remains prohibited pending Calibration GO.', 'universal-product-reviews' ); ?>
		</p>
		<ul>
			<li><?php echo esc_html( sprintf( 'Auto-spam master: %s', $master ? 'on' : 'off' ) ); ?></li>
			<li><?php echo esc_html( sprintf( 'Auto-spam policy: %s', $policy ? 'on' : 'off' ) ); ?></li>
			<li><?php echo esc_html( sprintf( 'Simulation guard: %s', $sim ? 'on' : 'off' ) ); ?></li>
			<li><?php echo esc_html( sprintf( 'Kill switch: %s', $kill ? 'on' : 'off' ) ); ?></li>
			<li><?php echo esc_html( sprintf( 'Dry-run: %s', $dry ? 'on' : 'off' ) ); ?></li>
			<li>
				<?php
				echo $boundary
					? esc_html__( 'Auto-action enablement boundary: set', 'universal-product-reviews' )
					: esc_html__( 'Auto-action enablement boundary: unset', 'universal-product-reviews' );
				?>
			</li>
			<li><?php echo esc_html( sprintf( 'Tuple fingerprint: %s', substr( ActionPolicy::active_tuple_fingerprint(), 0, 12 ) ) ); ?></li>
		</ul>

		<h4><?php echo esc_html__( 'Action ledger (counts)', 'universal-product-reviews' ); ?></h4>
		<?php if ( array() === $ledger ) : ?>
			<p><?php echo esc_html__( 'Ledger counts unavailable.', 'universal-product-reviews' ); ?></p>
		<?php else : ?>
			<ul>
				<?php foreach ( array( 'processing', 'cas_succeeded', 'acted', 'abstained', 'observed', 'unknown_after_crash' ) as $state ) : ?>
					<li><?php echo esc_html( $state . ': ' . (int) ( $ledger[ $state ] ?? 0 ) ); ?></li>
				<?php endforeach; ?>
			</ul>
			<?php if ( $unknown > 0 ) : ?>
				<p><strong><?php echo esc_html__( 'unknown_after_crash requires manual reconciliation — never replay WP transition hooks.', 'universal-product-reviews' ); ?></strong></p>
			<?php endif; ?>
		<?php endif; ?>

		<h4><?php echo esc_html__( 'Assessments (24h aggregates)', 'universal-product-reviews' ); ?></h4>
		<?php if ( array() === $assess_24 ) : ?>
			<p><?php echo esc_html__( 'No assessment aggregates in the last 24 hours (or unavailable).', 'universal-product-reviews' ); ?></p>
		<?php else : ?>
			<ul>
				<?php foreach ( $assess_24 as $state => $count ) : ?>
					<li><?php echo esc_html( (string) $state . ': ' . (int) $count ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p>
			<a class="button" href="<?php echo esc_url( $would_act_url ); ?>">
				<?php echo esc_html__( 'Run would-act report (read-only)', 'universal-product-reviews' ); ?>
			</a>
		</p>
		<p class="description">
			<?php echo esc_html__( 'Would-act is zero-write: it does not change status, write audit rows, or enable auto-spam. Policy match (pre-boundary) is non-actionable and is never labelled would-act.', 'universal-product-reviews' ); ?>
		</p>

		<?php self::render_held_review_count(); ?>
		<?php
	}

	/**
	 * Held product-review count: visible with manage_woocommerce; link only if also moderate_comments.
	 */
	private static function render_held_review_count(): void {
		$held = array( 'ok' => false, 'count' => 0 );
		try {
			$held = OverviewRepository::held_product_review_count();
		} catch ( \Throwable $e ) {
			$held = array( 'ok' => false, 'count' => 0 );
		}

		$queue_url = add_query_arg(
			array(
				'upr_view' => CommentListEnhancements::VIEW_PENDING,
			),
			admin_url( 'edit-comments.php' )
		);
		$can_link = current_user_can( 'moderate_comments' );
		?>
		<h4><?php echo esc_html__( 'Held product reviews', 'universal-product-reviews' ); ?></h4>
		<?php if ( empty( $held['ok'] ) ) : ?>
			<p><?php echo esc_html__( 'Held review count unavailable', 'universal-product-reviews' ); ?></p>
		<?php else : ?>
			<p>
				<?php
				$label = sprintf(
					/* translators: %d: held product review count */
					__( 'Held reviews awaiting moderation: %d', 'universal-product-reviews' ),
					(int) $held['count']
				);
				if ( $can_link ) {
					printf(
						'<a href="%s">%s</a>',
						esc_url( $queue_url ),
						esc_html( $label )
					);
				} else {
					echo esc_html( $label );
				}
				?>
			</p>
		<?php endif; ?>
		<?php
	}
}
