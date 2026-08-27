<?php
/**
 * Controls tab: invitation email settings + safe operator actions.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Database\Schema;
use UniversalProductReviews\Invitations\EmergencyPause;
use UniversalProductReviews\Invitations\InvitationEmailControls;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const MENU_SLUG = 'universal-product-reviews';

	/**
	 * Register Settings API options only (menu owned by AdminController).
	 */
	public static function register_settings(): void {
		add_action( 'admin_init', array( self::class, 'register_setting_fields' ) );
	}

	public static function register_setting_fields(): void {
		register_setting(
			'upr_settings',
			Options::INVITATION_EMAILS_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_enabled' ),
				'default'           => 'no',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::INVITATION_EMERGENCY_PAUSE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_pause' ),
				'default'           => 'no',
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_enabled( $value ): string {
		$enabled = self::is_checked( $value );
		InvitationEmailControls::set_emails_enabled( $enabled );
		return $enabled ? 'yes' : 'no';
	}

	/**
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_pause( $value ): string {
		$paused = self::is_checked( $value );
		$reason = '';
		if ( isset( $_POST['upr_invitation_emergency_pause_reason'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$reason = sanitize_text_field( wp_unslash( (string) $_POST['upr_invitation_emergency_pause_reason'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		EmergencyPause::set_paused( $paused, $reason );
		return $paused ? 'yes' : 'no';
	}

	/**
	 * @param mixed $value Checkbox-ish value.
	 */
	private static function is_checked( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_int( $value ) ) {
			return 1 === $value;
		}
		$v = strtolower( trim( (string) $value ) );
		return in_array( $v, array( '1', 'yes', 'true', 'on' ), true );
	}

	public static function render_controls(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$enabled      = Options::invitation_emails_enabled();
		$paused       = Options::invitation_emergency_pause();
		$meta         = EmergencyPause::meta();
		$schema_ok    = (string) get_option( Migrator::OPTION_VERSION, '' ) === Schema::DB_VERSION;
		$admin_post   = admin_url( 'admin-post.php' );
		?>
		<div class="upr-controls">
			<h2><?php echo esc_html__( 'Controls', 'universal-product-reviews' ); ?></h2>

			<h3><?php echo esc_html__( 'Status', 'universal-product-reviews' ); ?></h3>
			<ul>
				<li>
					<strong><?php echo esc_html__( 'Invitation emails', 'universal-product-reviews' ); ?>:</strong>
					<?php
					echo $enabled
						? esc_html__( 'enabled', 'universal-product-reviews' )
						: esc_html__( 'disabled', 'universal-product-reviews' );
					?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'Emergency pause', 'universal-product-reviews' ); ?>:</strong>
					<?php
					echo $paused
						? esc_html__( 'active', 'universal-product-reviews' )
						: esc_html__( 'off', 'universal-product-reviews' );
					?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'Sending not authorised', 'universal-product-reviews' ); ?>:</strong>
					<?php echo esc_html__( 'Host filter / authorisation may still deny sends. That policy cannot be toggled here.', 'universal-product-reviews' ); ?>
				</li>
			</ul>

			<form method="post" action="options.php" id="upr-controls-form">
				<?php settings_fields( 'upr_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Enable review invitation emails', 'universal-product-reviews' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Options::INVITATION_EMAILS_ENABLED ); ?>" value="no" />
							<label>
								<input type="checkbox" id="upr_invitation_emails_enabled" name="<?php echo esc_attr( Options::INVITATION_EMAILS_ENABLED ); ?>" value="yes" <?php checked( $enabled ); ?> data-upr-was="<?php echo $enabled ? '1' : '0'; ?>" />
								<?php echo esc_html__( 'Allow new review-invitation emails to be scheduled and sent.', 'universal-product-reviews' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Default is disabled. When disabled, outstanding invitation links remain valid, but no new invitation email is scheduled or sent.', 'universal-product-reviews' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Emergency pause invitations', 'universal-product-reviews' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Options::INVITATION_EMERGENCY_PAUSE ); ?>" value="no" />
							<label>
								<input type="checkbox" id="upr_invitation_emergency_pause" name="<?php echo esc_attr( Options::INVITATION_EMERGENCY_PAUSE ); ?>" value="yes" <?php checked( $paused ); ?> data-upr-was="<?php echo $paused ? '1' : '0'; ?>" />
								<?php echo esc_html__( 'Emergency stop: block all invitation scheduling and sending.', 'universal-product-reviews' ); ?>
							</label>
							<p class="description" style="color:#b32d2e;">
								<strong><?php echo esc_html__( 'Warning:', 'universal-product-reviews' ); ?></strong>
								<?php echo esc_html__( 'This is an emergency stop. While paused, invitation emails will not send, and outstanding invitation tokens and form sessions are revoked.', 'universal-product-reviews' ); ?>
							</p>
							<p>
								<label for="upr_invitation_emergency_pause_reason">
									<?php echo esc_html__( 'Pause / unpause reason (audit)', 'universal-product-reviews' ); ?>
								</label><br />
								<input type="text" class="large-text" id="upr_invitation_emergency_pause_reason" name="upr_invitation_emergency_pause_reason" value="" maxlength="191" />
							</p>
							<?php if ( $meta['changed_at'] > 0 ) : ?>
								<p class="description">
									<?php
									echo esc_html(
										sprintf(
											/* translators: 1: actor user ID, 2: unix timestamp, 3: reason */
											__( 'Last change: actor %1$d at %2$d — %3$s', 'universal-product-reviews' ),
											$meta['actor_id'],
											$meta['changed_at'],
											$meta['reason'] !== '' ? $meta['reason'] : '—'
										)
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save controls', 'universal-product-reviews' ) ); ?>
			</form>
			<script>
			(function () {
				var form = document.getElementById('upr-controls-form');
				if (!form) { return; }
				form.addEventListener('submit', function (e) {
					var emails = document.getElementById('upr_invitation_emails_enabled');
					var pause = document.getElementById('upr_invitation_emergency_pause');
					if (emails && emails.checked && emails.getAttribute('data-upr-was') === '0') {
						if (!window.confirm(<?php echo wp_json_encode( __( 'Enable invitation emails? This refreshes the no-retro-send scheduling boundary.', 'universal-product-reviews' ) ); ?>)) {
							e.preventDefault();
							return;
						}
					}
					if (pause && pause.checked && pause.getAttribute('data-upr-was') === '0') {
						if (!window.confirm(<?php echo wp_json_encode( __( 'Enable emergency pause? This revokes outstanding tokens and cancels pending invitation sends.', 'universal-product-reviews' ) ); ?>)) {
							e.preventDefault();
						}
					}
				});
			})();
			</script>

			<hr />
			<h3><?php echo esc_html__( 'Reconciliation', 'universal-product-reviews' ); ?></h3>
			<p class="description">
				<?php echo esc_html__( 'Dry-run performs zero writes. Apply requires confirmation and writes reconcile.completed on success.', 'universal-product-reviews' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( $admin_post ); ?>" style="margin-bottom:1em;">
				<input type="hidden" name="action" value="upr_reconcile_dry_run" />
				<?php wp_nonce_field( 'upr_reconcile_dry_run' ); ?>
				<label>
					<?php echo esc_html__( 'Lookback days', 'universal-product-reviews' ); ?>
					<input type="number" name="upr_lookback_days" value="90" min="1" max="365" />
				</label>
				<?php submit_button( __( 'Reconcile dry-run', 'universal-product-reviews' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
				<input type="hidden" name="action" value="upr_reconcile_apply" />
				<?php wp_nonce_field( 'upr_reconcile_apply' ); ?>
				<label>
					<?php echo esc_html__( 'Lookback days', 'universal-product-reviews' ); ?>
					<input type="number" name="upr_lookback_days" value="90" min="1" max="365" />
				</label>
				<label>
					<input type="checkbox" name="upr_confirm" value="1" required />
					<?php echo esc_html__( 'I confirm applying reconciliation (writes).', 'universal-product-reviews' ); ?>
				</label>
				<?php submit_button( __( 'Reconcile apply', 'universal-product-reviews' ), 'primary', 'submit', false ); ?>
			</form>

			<hr />
			<h3><?php echo esc_html__( 'Database upgrade', 'universal-product-reviews' ); ?></h3>
			<p>
				<?php
				echo $schema_ok
					? esc_html__( 'Schema is current.', 'universal-product-reviews' )
					: esc_html__( 'Schema is behind target. Run a controlled upgrade.', 'universal-product-reviews' );
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
				<input type="hidden" name="action" value="upr_db_upgrade" />
				<?php wp_nonce_field( 'upr_db_upgrade' ); ?>
				<label>
					<input type="checkbox" name="upr_confirm" value="1" required />
					<?php echo esc_html__( 'I confirm running the controlled database upgrade.', 'universal-product-reviews' ); ?>
				</label>
				<?php submit_button( __( 'Upgrade database', 'universal-product-reviews' ), 'secondary', 'submit', false ); ?>
			</form>

			<hr />
			<h3><?php echo esc_html__( 'Support export', 'universal-product-reviews' ); ?></h3>
			<p class="description">
				<?php echo esc_html__( 'Local JSON download of allowlisted aggregates only (no order IDs, emails, tokens, or free text).', 'universal-product-reviews' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
				<input type="hidden" name="action" value="upr_support_export" />
				<?php wp_nonce_field( 'upr_support_export' ); ?>
				<?php submit_button( __( 'Download support export', 'universal-product-reviews' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}
}
