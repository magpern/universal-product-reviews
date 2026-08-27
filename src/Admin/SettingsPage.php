<?php
/**
 * Generic UPR admin settings (invitation email controls).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Admin;

use UniversalProductReviews\Config\Options;
use UniversalProductReviews\Invitations\EmergencyPause;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const MENU_SLUG = 'universal-product-reviews';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	public static function add_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Product Reviews', 'universal-product-reviews' ),
			__( 'Product Reviews', 'universal-product-reviews' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( self::class, 'render_page' )
		);
	}

	public static function register_settings(): void {
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
		$prev    = Options::invitation_emails_enabled();
		if ( $enabled !== $prev ) {
			Options::bump_controls_epoch();
		}
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

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$enabled = Options::invitation_emails_enabled();
		$paused  = Options::invitation_emergency_pause();
		$meta    = EmergencyPause::meta();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Universal Product Reviews', 'universal-product-reviews' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'upr_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Enable review invitation emails', 'universal-product-reviews' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Options::INVITATION_EMAILS_ENABLED ); ?>" value="no" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Options::INVITATION_EMAILS_ENABLED ); ?>" value="yes" <?php checked( $enabled ); ?> />
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
								<input type="checkbox" name="<?php echo esc_attr( Options::INVITATION_EMERGENCY_PAUSE ); ?>" value="yes" <?php checked( $paused ); ?> />
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
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
