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
use UniversalProductReviews\Invitations\EmergencyPause;
use UniversalProductReviews\Invitations\InvitationEmailControls;
use UniversalProductReviews\Ai\ActionControls;
use UniversalProductReviews\Ai\ActionPolicy;
use UniversalProductReviews\Ai\ExternalQuotaRepository;
use UniversalProductReviews\Ai\OpenAi\CredentialResolver;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	public const MENU_SLUG = 'universal-product-reviews';

	/** Posted confirmation required to enable invitation emails. */
	public const CONFIRM_ENABLE_EMAILS = 'upr_confirm_enable_emails';

	/** Posted confirmation required to activate emergency pause. */
	public const CONFIRM_EMERGENCY_PAUSE = 'upr_confirm_emergency_pause';

	/** Posted confirmation required to enable local AI shadow mode. */
	public const CONFIRM_ENABLE_LOCAL_AI_SHADOW = 'upr_confirm_enable_local_ai_shadow';

	/** Posted confirmation required to enable external AI. */
	public const CONFIRM_ENABLE_AI_EXTERNAL = 'upr_confirm_enable_ai_external';

	/** Posted confirmation required to enable M12 auto-spam master. */
	public const CONFIRM_ENABLE_AUTO_SPAM = 'upr_confirm_enable_auto_spam';

	/** Posted confirmation required to enable M12 auto-spam policy master. */
	public const CONFIRM_ENABLE_AUTO_SPAM_POLICY = 'upr_confirm_enable_auto_spam_policy';

	/** Posted confirmation required to enable M12 simulation-only guard. */
	public const CONFIRM_ENABLE_AUTO_SPAM_SIM = 'upr_confirm_enable_auto_spam_sim';

	/** Governance acknowledgements required with external enable. */
	public const ACK_OPENAI_PRIVACY   = 'upr_ack_openai_privacy';
	public const ACK_OPENAI_RETENTION = 'upr_ack_openai_retention';
	public const ACK_REVIEW_MAY_PII   = 'upr_ack_review_may_contain_pii';

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

		register_setting(
			'upr_settings',
			Options::LOCAL_AI_SHADOW_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_local_ai_shadow' ),
				'default'           => 'no',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::AI_RECOMMENDATIONS_DISPLAY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_ai_recommendations_display' ),
				'default'           => 'yes',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::AI_AUTO_SPAM_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_auto_spam_master' ),
				'default'           => 'no',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'upr_settings',
			Options::AI_AUTO_SPAM_POLICY_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_auto_spam_policy' ),
				'default'           => 'no',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'upr_settings',
			Options::AI_AUTO_SPAM_SIMULATION_GUARD,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_auto_spam_sim_guard' ),
				'default'           => 'no',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'upr_settings',
			Options::AI_AUTO_SPAM_DRY_RUN,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_auto_spam_dry_run' ),
				'default'           => 'no',
				'show_in_rest'      => false,
			)
		);
		register_setting(
			'upr_settings',
			Options::AI_AUTO_SPAM_KILL_SWITCH,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_auto_spam_kill' ),
				'default'           => 'no',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::AI_EXTERNAL_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_ai_external' ),
				'default'           => 'no',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::AI_PROVIDER,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Options::class, 'sanitize_provider' ),
				'default'           => 'local',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::OPENAI_MODEL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_openai_model' ),
				'default'           => Options::OPENAI_MODEL_DEFAULT,
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::OPENAI_MODEL_MANUAL,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( Options::class, 'sanitize_model_manual' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::OPENAI_MAX_OUTPUT_TOKENS,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_max_output_tokens' ),
				'default'           => Options::OPENAI_MAX_OUTPUT_TOKENS_DEFAULT,
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::AI_OPERATOR_GUIDANCE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( self::class, 'sanitize_guidance' ),
				'default'           => '',
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::AI_ALLOWED_PHRASES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize_phrase_list' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::AI_DISALLOWED_PHRASES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize_phrase_list' ),
				'default'           => array(),
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::OPENAI_DAILY_REQUEST_CAP,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_daily_cap' ),
				'default'           => Options::OPENAI_DAILY_CAP_DEFAULT,
				'show_in_rest'      => false,
			)
		);

		register_setting(
			'upr_settings',
			Options::OPENAI_MONTHLY_REQUEST_CAP,
			array(
				'type'              => 'integer',
				'sanitize_callback' => array( self::class, 'sanitize_monthly_cap' ),
				'default'           => Options::OPENAI_MONTHLY_CAP_DEFAULT,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_enabled( $value ): string {
		$enabled = self::is_checked( $value );
		$was     = Options::invitation_emails_enabled();

		// Enabling requires a verified posted confirmation field (JS confirm alone is not enough).
		if ( $enabled && ! $was && ! self::posted_confirm( self::CONFIRM_ENABLE_EMAILS ) ) {
			return $was ? 'yes' : 'no';
		}

		InvitationEmailControls::set_emails_enabled( $enabled );
		return $enabled ? 'yes' : 'no';
	}

	/**
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_pause( $value ): string {
		$paused = self::is_checked( $value );
		$was    = Options::invitation_emergency_pause();

		// Activating pause requires a verified posted confirmation field.
		if ( $paused && ! $was && ! self::posted_confirm( self::CONFIRM_EMERGENCY_PAUSE ) ) {
			return $was ? 'yes' : 'no';
		}

		$reason = '';
		if ( isset( $_POST['upr_invitation_emergency_pause_reason'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$reason = sanitize_text_field( wp_unslash( (string) $_POST['upr_invitation_emergency_pause_reason'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		EmergencyPause::set_paused( $paused, $reason );
		return $paused ? 'yes' : 'no';
	}

	/**
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_local_ai_shadow( $value ): string {
		$enabled = self::is_checked( $value );
		$was     = Options::local_ai_shadow_enabled();

		if ( $enabled && ! $was && ! self::posted_confirm( self::CONFIRM_ENABLE_LOCAL_AI_SHADOW ) ) {
			return $was ? 'yes' : 'no';
		}

		return $enabled ? 'yes' : 'no';
	}

	/**
	 * Recommendation display — absent defaults to enabled; no confirmation required.
	 *
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_ai_recommendations_display( $value ): string {
		return self::is_checked( $value ) ? 'yes' : 'no';
	}

	/**
	 * M12 auto-spam master — confirm on enable; refreshes scheduling boundary via ActionControls.
	 *
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_auto_spam_master( $value ): string {
		$enabled = self::is_checked( $value );
		$was     = Options::ai_auto_spam_enabled();
		if ( $enabled && ! $was && ! self::posted_confirm( self::CONFIRM_ENABLE_AUTO_SPAM ) ) {
			return 'no';
		}
		ActionControls::set_master_enabled( $enabled );
		return $enabled ? 'yes' : 'no';
	}

	/**
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_auto_spam_policy( $value ): string {
		$enabled = self::is_checked( $value );
		$was     = Options::ai_auto_spam_policy_enabled();
		if ( $enabled && ! $was && ! self::posted_confirm( self::CONFIRM_ENABLE_AUTO_SPAM_POLICY ) ) {
			return 'no';
		}
		ActionControls::set_policy_enabled( $enabled );
		return $enabled ? 'yes' : 'no';
	}

	/**
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_auto_spam_sim_guard( $value ): string {
		$enabled = self::is_checked( $value );
		$was     = Options::ai_auto_spam_simulation_guard_enabled();
		if ( $enabled && ! $was && ! self::posted_confirm( self::CONFIRM_ENABLE_AUTO_SPAM_SIM ) ) {
			return 'no';
		}
		ActionControls::set_simulation_guard( $enabled );
		return $enabled ? 'yes' : 'no';
	}

	/**
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_auto_spam_dry_run( $value ): string {
		$enabled = self::is_checked( $value );
		ActionControls::set_dry_run( $enabled );
		return $enabled ? 'yes' : 'no';
	}

	/**
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_auto_spam_kill( $value ): string {
		$enabled = self::is_checked( $value );
		ActionControls::set_kill_switch( $enabled );
		return $enabled ? 'yes' : 'no';
	}

	/**
	 * External AI enablement — requires confirm + privacy/governance acks server-side.
	 *
	 * @param mixed $value Raw POST.
	 */
	public static function sanitize_ai_external( $value ): string {
		$enabled = self::is_checked( $value );
		$was     = Options::ai_external_enabled();

		if ( $enabled && ! $was ) {
			$ok = self::posted_confirm( self::CONFIRM_ENABLE_AI_EXTERNAL )
				&& self::posted_confirm( self::ACK_OPENAI_PRIVACY )
				&& self::posted_confirm( self::ACK_OPENAI_RETENTION )
				&& self::posted_confirm( self::ACK_REVIEW_MAY_PII );
			if ( ! $ok ) {
				return $was ? 'yes' : 'no';
			}
		}

		$result = $enabled ? 'yes' : 'no';

		// External disable: silently revoke in-flight OpenAI claims only (by claim_provider_kind).
		// Local claims survive even if the selected provider option later changed to openai.
		if ( $was && ! $enabled ) {
			\UniversalProductReviews\Ai\AssessmentClaimsRepository::clear_all_active_for_provider(
				\UniversalProductReviews\Ai\PolicyAllowlist::POLICY_VERSION,
				'openai'
			);
		}

		return $result;
	}

	/**
	 * @param mixed $value Raw model dropdown.
	 */
	public static function sanitize_openai_model( $value ): string {
		$v = trim( (string) $value );
		if ( in_array( $v, Options::OPENAI_SUGGESTED_MODELS, true ) ) {
			return $v;
		}
		return Options::OPENAI_MODEL_DEFAULT;
	}

	/**
	 * @param mixed $value Raw int.
	 */
	public static function sanitize_max_output_tokens( $value ): int {
		$n = (int) $value;
		return max( Options::OPENAI_MAX_OUTPUT_TOKENS_MIN, min( Options::OPENAI_MAX_OUTPUT_TOKENS_MAX, $n ) );
	}

	/**
	 * @param mixed $value Raw guidance.
	 */
	public static function sanitize_guidance( $value ): string {
		$raw = wp_strip_all_tags( (string) $value );
		if ( strlen( $raw ) > Options::GUIDANCE_MAX_CHARS ) {
			$raw = substr( $raw, 0, Options::GUIDANCE_MAX_CHARS );
		}
		return $raw;
	}

	/**
	 * @param mixed $value Raw int.
	 */
	public static function sanitize_daily_cap( $value ): int {
		return max( 1, min( 10000, (int) $value ) );
	}

	/**
	 * @param mixed $value Raw int.
	 */
	public static function sanitize_monthly_cap( $value ): int {
		return max( 1, min( 100000, (int) $value ) );
	}

	/**
	 * True when the named confirmation checkbox was posted as "1".
	 */
	public static function posted_confirm( string $field ): bool {
		if ( empty( $_POST[ $field ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}
		return '1' === (string) wp_unslash( $_POST[ $field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
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
		$shadow       = Options::local_ai_shadow_enabled();
		$rec_display  = Options::ai_recommendations_display_enabled();
		$external     = Options::ai_external_enabled();
		$provider     = Options::ai_provider();
		$cred         = CredentialResolver::status();
		$quota        = ExternalQuotaRepository::summarize();
		$meta         = EmergencyPause::meta();
		$schema_ok    = ! Migrator::needs_upgrade();
		$admin_post   = admin_url( 'admin-post.php' );
		$model        = (string) get_option( Options::OPENAI_MODEL, Options::OPENAI_MODEL_DEFAULT );
		$model_manual = (string) get_option( Options::OPENAI_MODEL_MANUAL, '' );
		$max_tokens   = Options::openai_max_output_tokens();
		$guidance     = Options::ai_operator_guidance();
		$allowed      = Options::ai_allowed_phrases();
		$disallowed   = Options::ai_disallowed_phrases();
		$daily_cap    = Options::openai_daily_request_cap();
		$monthly_cap  = Options::openai_monthly_request_cap();
		?>
		<div class="upr-controls">
			<h2><?php echo esc_html__( 'Controls', 'universal-product-reviews' ); ?></h2>

			<?php self::render_controls_notices(); ?>

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
				<li>
					<strong><?php echo esc_html__( 'Local AI shadow mode', 'universal-product-reviews' ); ?>:</strong>
					<?php
					echo $shadow
						? esc_html__( 'enabled (advisory only)', 'universal-product-reviews' )
						: esc_html__( 'disabled', 'universal-product-reviews' );
					?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'External AI', 'universal-product-reviews' ); ?>:</strong>
					<?php
					echo $external
						? esc_html__( 'enabled (advisory only)', 'universal-product-reviews' )
						: esc_html__( 'disabled', 'universal-product-reviews' );
					?>
					—
					<?php echo esc_html( sprintf( /* translators: %s: local|openai */ __( 'provider %s', 'universal-product-reviews' ), $provider ) ); ?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'OpenAI credential', 'universal-product-reviews' ); ?>:</strong>
					<?php
					echo $cred['present']
						? esc_html( sprintf( /* translators: %s: constant|environment */ __( 'present (%s)', 'universal-product-reviews' ), $cred['source'] ) )
						: esc_html__( 'missing', 'universal-product-reviews' );
					?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'External quota (today / month)', 'universal-product-reviews' ); ?>:</strong>
					<?php
					echo esc_html(
						sprintf(
							'%d / %d',
							(int) ( $quota['day_count'] ?? 0 ),
							(int) ( $quota['month_count'] ?? 0 )
						)
					);
					?>
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
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::CONFIRM_ENABLE_EMAILS ); ?>" value="1" id="upr_confirm_enable_emails" />
									<?php echo esc_html__( 'I confirm enabling invitation emails (required when turning this on).', 'universal-product-reviews' ); ?>
								</label>
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
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::CONFIRM_EMERGENCY_PAUSE ); ?>" value="1" id="upr_confirm_emergency_pause" />
									<?php echo esc_html__( 'I confirm activating emergency pause (required when turning this on).', 'universal-product-reviews' ); ?>
								</label>
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
					<tr>
						<th scope="row"><?php echo esc_html__( 'Local AI shadow mode (advisory only)', 'universal-product-reviews' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Options::LOCAL_AI_SHADOW_ENABLED ); ?>" value="no" />
							<label>
								<input type="checkbox" id="upr_local_ai_shadow_enabled" name="<?php echo esc_attr( Options::LOCAL_AI_SHADOW_ENABLED ); ?>" value="yes" <?php checked( $shadow ); ?> data-upr-was="<?php echo $shadow ? '1' : '0'; ?>" />
								<?php echo esc_html__( 'Run local-only advisory publication-safety assessments on held product reviews. Does not change comment status or content.', 'universal-product-reviews' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Default is disabled. When disabled, no new assessments or AI audit events are recorded.', 'universal-product-reviews' ); ?>
							</p>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::CONFIRM_ENABLE_LOCAL_AI_SHADOW ); ?>" value="1" id="upr_confirm_enable_local_ai_shadow" />
									<?php echo esc_html__( 'I confirm enabling local AI shadow mode (required when turning this on).', 'universal-product-reviews' ); ?>
								</label>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'AI recommendations (M11)', 'universal-product-reviews' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Options::AI_RECOMMENDATIONS_DISPLAY ); ?>" value="no" />
							<label>
								<input type="checkbox" id="upr_ai_recommendations_display" name="<?php echo esc_attr( Options::AI_RECOMMENDATIONS_DISPLAY ); ?>" value="yes" <?php checked( $rec_display ); ?> />
								<?php echo esc_html__( 'Show recommendation labels in Comments for held reviews that have assessments.', 'universal-product-reviews' ); ?>
							</label>
							<p class="description">
								<?php echo esc_html__( 'Absent option defaults to on. Independent of local/external shadow masters. Risk score: higher means greater publication risk. Actionable labels only while Pending (hold). Does not auto-moderate by itself. M12 auto-spam masters default off (Simulation GO — production needs Calibration GO).', 'universal-product-reviews' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'M12 auto-spam (Simulation GO)', 'universal-product-reviews' ); ?></th>
						<td>
							<?php
							$auto_master = Options::ai_auto_spam_enabled();
							$auto_policy = Options::ai_auto_spam_policy_enabled();
							$auto_sim    = Options::ai_auto_spam_simulation_guard_enabled();
							$auto_dry    = Options::ai_auto_spam_dry_run();
							$auto_kill   = Options::ai_auto_spam_kill_switch();
							$boundary    = Options::ai_auto_action_boundary_unix();
							$tuple_fp    = ActionPolicy::active_tuple_fingerprint();
							?>
							<p class="description" style="color:#b32d2e;">
								<strong><?php echo esc_html__( 'Production automatic moderation remains prohibited', 'universal-product-reviews' ); ?></strong>
								<?php echo esc_html__( ' until Calibration GO and a separate production-enable approval. Simulation GO authorises implementation and non-production synthetic testing only.', 'universal-product-reviews' ); ?>
							</p>
							<input type="hidden" name="<?php echo esc_attr( Options::AI_AUTO_SPAM_ENABLED ); ?>" value="no" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Options::AI_AUTO_SPAM_ENABLED ); ?>" value="yes" <?php checked( $auto_master ); ?> data-upr-was="<?php echo $auto_master ? '1' : '0'; ?>" />
								<?php echo esc_html__( 'Master: enable auto_spam_held_technical (default off). Off→on refreshes the strict scheduling boundary.', 'universal-product-reviews' ); ?>
							</label>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::CONFIRM_ENABLE_AUTO_SPAM ); ?>" value="1" />
									<?php echo esc_html__( 'I confirm enabling the auto-spam master (required when turning this on).', 'universal-product-reviews' ); ?>
								</label>
							</p>
							<input type="hidden" name="<?php echo esc_attr( Options::AI_AUTO_SPAM_POLICY_ENABLED ); ?>" value="no" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Options::AI_AUTO_SPAM_POLICY_ENABLED ); ?>" value="yes" <?php checked( $auto_policy ); ?> />
								<?php echo esc_html__( 'Policy master: allow auto_spam_held_technical conjunction (default off).', 'universal-product-reviews' ); ?>
							</label>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::CONFIRM_ENABLE_AUTO_SPAM_POLICY ); ?>" value="1" />
									<?php echo esc_html__( 'I confirm enabling the policy master.', 'universal-product-reviews' ); ?>
								</label>
							</p>
							<input type="hidden" name="<?php echo esc_attr( Options::AI_AUTO_SPAM_SIMULATION_GUARD ); ?>" value="no" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Options::AI_AUTO_SPAM_SIMULATION_GUARD ); ?>" value="yes" <?php checked( $auto_sim ); ?> />
								<?php echo esc_html__( 'Simulation-only non-production environment guard (default off). Required for any action under Simulation GO.', 'universal-product-reviews' ); ?>
							</label>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::CONFIRM_ENABLE_AUTO_SPAM_SIM ); ?>" value="1" />
									<?php echo esc_html__( 'I confirm this is a non-production Simulation environment.', 'universal-product-reviews' ); ?>
								</label>
							</p>
							<input type="hidden" name="<?php echo esc_attr( Options::AI_AUTO_SPAM_DRY_RUN ); ?>" value="no" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Options::AI_AUTO_SPAM_DRY_RUN ); ?>" value="yes" <?php checked( $auto_dry ); ?> />
								<?php echo esc_html__( 'Dry-run: ledger observed only — never CAS / status mutation.', 'universal-product-reviews' ); ?>
							</label>
							<br />
							<input type="hidden" name="<?php echo esc_attr( Options::AI_AUTO_SPAM_KILL_SWITCH ); ?>" value="no" />
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Options::AI_AUTO_SPAM_KILL_SWITCH ); ?>" value="yes" <?php checked( $auto_kill ); ?> />
								<?php echo esc_html__( 'Kill switch: force abstain / clear active claims.', 'universal-product-reviews' ); ?>
							</label>
							<p class="description">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: unix boundary, 2: tuple fingerprint prefix */
										__( 'Boundary (unix): %1$s. Active Simulation tuple fingerprint: %2$s… Equality with boundary abstains; missing boundary fails closed.', 'universal-product-reviews' ),
										$boundary > 0 ? (string) $boundary : 'unset',
										substr( $tuple_fp, 0, 12 )
									)
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Assessment provider', 'universal-product-reviews' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( Options::AI_PROVIDER ); ?>" id="upr_ai_provider">
								<option value="local" <?php selected( $provider, 'local' ); ?>><?php echo esc_html__( 'Local (built-in)', 'universal-product-reviews' ); ?></option>
								<option value="openai" <?php selected( $provider, 'openai' ); ?>><?php echo esc_html__( 'OpenAI (external)', 'universal-product-reviews' ); ?></option>
							</select>
							<p class="description">
								<?php echo esc_html__( 'Exactly local or openai. OpenAI never silently falls back to local.', 'universal-product-reviews' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Enable external AI (OpenAI)', 'universal-product-reviews' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Options::AI_EXTERNAL_ENABLED ); ?>" value="no" />
							<label>
								<input type="checkbox" id="upr_ai_external_enabled" name="<?php echo esc_attr( Options::AI_EXTERNAL_ENABLED ); ?>" value="yes" <?php checked( $external ); ?> data-upr-was="<?php echo $external ? '1' : '0'; ?>" />
								<?php echo esc_html__( 'Allow OpenAI advisory assessments when provider is openai. Requires host credential UPR_OPENAI_API_KEY.', 'universal-product-reviews' ); ?>
							</label>
							<p class="description" style="color:#b32d2e;">
								<strong><?php echo esc_html__( 'Warning:', 'universal-product-reviews' ); ?></strong>
								<?php echo esc_html__( 'Review text may contain personal data and will be sent to OpenAI when external AI runs. Plugin limits are not a defence against a compromised administrator or leaked secret — configure provider-side spend/rate limits first.', 'universal-product-reviews' ); ?>
							</p>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::CONFIRM_ENABLE_AI_EXTERNAL ); ?>" value="1" id="upr_confirm_enable_ai_external" />
									<?php echo esc_html__( 'I confirm enabling external AI (required when turning this on).', 'universal-product-reviews' ); ?>
								</label>
							</p>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::ACK_OPENAI_PRIVACY ); ?>" value="1" id="upr_ack_openai_privacy" />
									<?php echo esc_html__( 'I acknowledge processor/privacy terms for OpenAI apply to this site.', 'universal-product-reviews' ); ?>
								</label>
							</p>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::ACK_OPENAI_RETENTION ); ?>" value="1" id="upr_ack_openai_retention" />
									<?php echo esc_html__( 'I acknowledge OpenAI project retention/privacy posture is configured appropriately.', 'universal-product-reviews' ); ?>
								</label>
							</p>
							<p>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::ACK_REVIEW_MAY_PII ); ?>" value="1" id="upr_ack_review_may_contain_pii" />
									<?php echo esc_html__( 'I acknowledge review text may contain personal data.', 'universal-product-reviews' ); ?>
								</label>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'OpenAI model', 'universal-product-reviews' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( Options::OPENAI_MODEL ); ?>" id="upr_openai_model">
								<?php foreach ( Options::OPENAI_SUGGESTED_MODELS as $suggested ) : ?>
									<option value="<?php echo esc_attr( $suggested ); ?>" <?php selected( $model, $suggested ); ?>><?php echo esc_html( $suggested ); ?></option>
								<?php endforeach; ?>
							</select>
							<p>
								<label for="upr_openai_model_manual"><?php echo esc_html__( 'Manual model ID (advanced)', 'universal-product-reviews' ); ?></label><br />
								<input type="text" class="regular-text" id="upr_openai_model_manual" name="<?php echo esc_attr( Options::OPENAI_MODEL_MANUAL ); ?>" value="<?php echo esc_attr( $model_manual ); ?>" maxlength="64" autocomplete="off" />
							</p>
							<p>
								<label for="upr_openai_max_output_tokens"><?php echo esc_html__( 'Max output tokens', 'universal-product-reviews' ); ?></label><br />
								<input type="number" id="upr_openai_max_output_tokens" name="<?php echo esc_attr( Options::OPENAI_MAX_OUTPUT_TOKENS ); ?>" value="<?php echo esc_attr( (string) $max_tokens ); ?>" min="<?php echo esc_attr( (string) Options::OPENAI_MAX_OUTPUT_TOKENS_MIN ); ?>" max="<?php echo esc_attr( (string) Options::OPENAI_MAX_OUTPUT_TOKENS_MAX ); ?>" />
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Operator guidance & phrases', 'universal-product-reviews' ); ?></th>
						<td>
							<label for="upr_ai_operator_guidance"><?php echo esc_html__( 'Operator guidance (evidence cue only)', 'universal-product-reviews' ); ?></label><br />
							<textarea class="large-text" rows="3" id="upr_ai_operator_guidance" name="<?php echo esc_attr( Options::AI_OPERATOR_GUIDANCE ); ?>" maxlength="<?php echo esc_attr( (string) Options::GUIDANCE_MAX_CHARS ); ?>"><?php echo esc_textarea( $guidance ); ?></textarea>
							<p>
								<label for="upr_ai_allowed_phrases"><?php echo esc_html__( 'Allowed phrases (one per line)', 'universal-product-reviews' ); ?></label><br />
								<textarea class="large-text" rows="3" id="upr_ai_allowed_phrases" name="<?php echo esc_attr( Options::AI_ALLOWED_PHRASES ); ?>"><?php echo esc_textarea( implode( "\n", $allowed ) ); ?></textarea>
							</p>
							<p>
								<label for="upr_ai_disallowed_phrases"><?php echo esc_html__( 'Disallowed phrases (one per line)', 'universal-product-reviews' ); ?></label><br />
								<textarea class="large-text" rows="3" id="upr_ai_disallowed_phrases" name="<?php echo esc_attr( Options::AI_DISALLOWED_PHRASES ); ?>"><?php echo esc_textarea( implode( "\n", $disallowed ) ); ?></textarea>
							</p>
							<p class="description">
								<?php echo esc_html__( 'Bounded evidence cues only. They cannot change schema, provider, tools, quotas, or moderation status.', 'universal-product-reviews' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'External request caps', 'universal-product-reviews' ); ?></th>
						<td>
							<label for="upr_openai_daily_request_cap"><?php echo esc_html__( 'Daily request cap', 'universal-product-reviews' ); ?></label>
							<input type="number" id="upr_openai_daily_request_cap" name="<?php echo esc_attr( Options::OPENAI_DAILY_REQUEST_CAP ); ?>" value="<?php echo esc_attr( (string) $daily_cap ); ?>" min="1" max="10000" />
							<label for="upr_openai_monthly_request_cap" style="margin-left:1em;"><?php echo esc_html__( 'Monthly request cap', 'universal-product-reviews' ); ?></label>
							<input type="number" id="upr_openai_monthly_request_cap" name="<?php echo esc_attr( Options::OPENAI_MONTHLY_REQUEST_CAP ); ?>" value="<?php echo esc_attr( (string) $monthly_cap ); ?>" min="1" max="100000" />
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
					var confirmEmails = document.getElementById('upr_confirm_enable_emails');
					var confirmPause = document.getElementById('upr_confirm_emergency_pause');
					var shadow = document.getElementById('upr_local_ai_shadow_enabled');
					var confirmShadow = document.getElementById('upr_confirm_enable_local_ai_shadow');
					var external = document.getElementById('upr_ai_external_enabled');
					var confirmExternal = document.getElementById('upr_confirm_enable_ai_external');
					var ackPrivacy = document.getElementById('upr_ack_openai_privacy');
					var ackRetention = document.getElementById('upr_ack_openai_retention');
					var ackPii = document.getElementById('upr_ack_review_may_contain_pii');
					if (emails && emails.checked && emails.getAttribute('data-upr-was') === '0') {
						if (!confirmEmails || !confirmEmails.checked) {
							window.alert(<?php echo wp_json_encode( __( 'Check the confirmation box to enable invitation emails.', 'universal-product-reviews' ) ); ?>);
							e.preventDefault();
							return;
						}
						if (!window.confirm(<?php echo wp_json_encode( __( 'Enable invitation emails? This refreshes the no-retro-send scheduling boundary.', 'universal-product-reviews' ) ); ?>)) {
							e.preventDefault();
							return;
						}
					}
					if (pause && pause.checked && pause.getAttribute('data-upr-was') === '0') {
						if (!confirmPause || !confirmPause.checked) {
							window.alert(<?php echo wp_json_encode( __( 'Check the confirmation box to activate emergency pause.', 'universal-product-reviews' ) ); ?>);
							e.preventDefault();
							return;
						}
						if (!window.confirm(<?php echo wp_json_encode( __( 'Enable emergency pause? This revokes outstanding tokens and cancels pending invitation sends.', 'universal-product-reviews' ) ); ?>)) {
							e.preventDefault();
						}
					}
					if (shadow && shadow.checked && shadow.getAttribute('data-upr-was') === '0') {
						if (!confirmShadow || !confirmShadow.checked) {
							window.alert(<?php echo wp_json_encode( __( 'Check the confirmation box to enable local AI shadow mode.', 'universal-product-reviews' ) ); ?>);
							e.preventDefault();
							return;
						}
						if (!window.confirm(<?php echo wp_json_encode( __( 'Enable local AI shadow mode? Assessments are advisory only and never change review status.', 'universal-product-reviews' ) ); ?>)) {
						 e.preventDefault();
						 return;
						}
					}
					if (external && external.checked && external.getAttribute('data-upr-was') === '0') {
						if (!confirmExternal || !confirmExternal.checked || !ackPrivacy || !ackPrivacy.checked || !ackRetention || !ackRetention.checked || !ackPii || !ackPii.checked) {
							window.alert(<?php echo wp_json_encode( __( 'Confirm enabling external AI and all governance acknowledgements.', 'universal-product-reviews' ) ); ?>);
							e.preventDefault();
							return;
						}
						if (!window.confirm(<?php echo wp_json_encode( __( 'Enable external AI? Review text may be sent to OpenAI. Advisory only — never auto-moderates.', 'universal-product-reviews' ) ); ?>)) {
							e.preventDefault();
						}
					}
				});
			})();
			</script>

			<hr />
			<h3><?php echo esc_html__( 'OpenAI test connection', 'universal-product-reviews' ); ?></h3>
			<p class="description">
				<?php echo esc_html__( 'Sends a fixed synthetic payload only (no customer reviews). Consumes external quota. Does not touch local AI rate or circuit state.', 'universal-product-reviews' ); ?>
			</p>
			<form method="post" action="<?php echo esc_url( $admin_post ); ?>">
				<input type="hidden" name="action" value="upr_ai_test_connection" />
				<?php wp_nonce_field( 'upr_ai_test_connection' ); ?>
				<label>
					<input type="checkbox" name="upr_confirm_test_connection" value="1" required />
					<?php echo esc_html__( 'I confirm running a paid synthetic OpenAI test connection.', 'universal-product-reviews' ); ?>
				</label>
				<?php submit_button( __( 'Test OpenAI connection', 'universal-product-reviews' ), 'secondary', 'submit', false ); ?>
			</form>

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

	private static function render_controls_notices(): void {
		if ( empty( $_GET['upr_ai_conn'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$code = sanitize_key( wp_unslash( (string) $_GET['upr_ai_conn'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$map  = array(
			'connection_ok'         => array( 'success', __( 'OpenAI test connection succeeded (synthetic payload).', 'universal-product-reviews' ) ),
			'budget_exceeded'       => array( 'warning', __( 'External quota exhausted; test connection skipped.', 'universal-product-reviews' ) ),
			'credential_missing'    => array( 'error', __( 'OpenAI credential missing.', 'universal-product-reviews' ) ),
			'external_disabled'     => array( 'warning', __( 'External AI is disabled.', 'universal-product-reviews' ) ),
			'provider_unavailable'  => array( 'error', __( 'OpenAI provider unavailable.', 'universal-product-reviews' ) ),
			'provider_incomplete'   => array( 'error', __( 'OpenAI response incomplete.', 'universal-product-reviews' ) ),
			'validation_rejected'   => array( 'error', __( 'OpenAI response failed validation.', 'universal-product-reviews' ) ),
			'malformed'             => array( 'error', __( 'OpenAI response malformed.', 'universal-product-reviews' ) ),
			'connection_refused'    => array( 'error', __( 'Test connection refused (capability, nonce, or confirmation).', 'universal-product-reviews' ) ),
		);
		if ( ! isset( $map[ $code ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( $map[ $code ][0] ),
			esc_html( $map[ $code ][1] )
		);
	}
}
