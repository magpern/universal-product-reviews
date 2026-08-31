<?php
/**
 * Admin-post handler for OpenAI credential save/replace/clear (M10 O9′).
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Ai\OpenAi;

defined( 'ABSPATH' ) || exit;

final class OpenAiCredentialAdmin {

	public const ACTION          = 'upr_openai_credential';
	public const CONFIRM_SAVE    = 'upr_confirm_openai_credential_save';
	public const CONFIRM_CLEAR   = 'upr_confirm_openai_credential_clear';
	public const INTENT_CLEAR    = 'upr_openai_credential_clear';

	/**
	 * Handle admin-post. Never echoes secrets.
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			self::scrub_request();
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'universal-product-reviews' ), 403 );
		}

		check_admin_referer( self::ACTION );

		$clear_intent = isset( $_POST[ self::INTENT_CLEAR ] ) && '1' === (string) wp_unslash( (string) $_POST[ self::INTENT_CLEAR ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$confirm_save = isset( $_POST[ self::CONFIRM_SAVE ] ) && '1' === (string) wp_unslash( (string) $_POST[ self::CONFIRM_SAVE ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$confirm_clear = isset( $_POST[ self::CONFIRM_CLEAR ] ) && '1' === (string) wp_unslash( (string) $_POST[ self::CONFIRM_CLEAR ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing

		// Save and clear in one request → no write.
		if ( $clear_intent && ( $confirm_save || ( isset( $_POST[ OpenAiCredentialStore::FIELD ] ) && '' !== (string) wp_unslash( (string) $_POST[ OpenAiCredentialStore::FIELD ] ) ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
			self::scrub_request();
			self::redirect( 'rejected' );
		}

		if ( $clear_intent ) {
			if ( ! $confirm_clear ) {
				self::scrub_request();
				self::redirect( 'rejected' );
			}
			OpenAiCredentialStore::clear();
			self::scrub_request();
			self::redirect( 'cleared' );
		}

		if ( ! $confirm_save ) {
			self::scrub_request();
			self::redirect( 'rejected' );
		}

		if ( ! isset( $_POST[ OpenAiCredentialStore::FIELD ] ) || ! is_string( $_POST[ OpenAiCredentialStore::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::scrub_request();
			self::redirect( 'rejected' );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- O9′ narrow input contract: wp_unslash only.
		$raw = wp_unslash( $_POST[ OpenAiCredentialStore::FIELD ] );
		if ( ! is_string( $raw ) ) {
			self::scrub_request();
			self::redirect( 'rejected' );
		}

		$len = strlen( $raw );
		if ( 0 === $len ) {
			self::scrub_request();
			self::redirect( 'saved' ); // no-op keep stored; allowlisted code.
		}

		if ( $len > OpenAiCredentialCipher::PLAINTEXT_MAX || self::has_forbidden_octets( $raw ) ) {
			self::scrub_request();
			self::redirect( 'rejected' );
		}

		$existed = OpenAiCredentialStore::exists();
		try {
			$cipher   = new OpenAiCredentialCipher();
			$envelope = $cipher->encrypt( $raw );
		} catch ( \Throwable $e ) {
			self::scrub_request();
			self::redirect( 'rejected' );
		}

		if ( ! OpenAiCredentialStore::save_envelope( $envelope ) ) {
			self::scrub_request();
			self::redirect( 'rejected' );
		}

		self::scrub_request();
		self::redirect( $existed ? 'replaced' : 'saved' );
	}

	private static function has_forbidden_octets( string $value ): bool {
		$len = strlen( $value );
		for ( $i = 0; $i < $len; $i++ ) {
			$o = ord( $value[ $i ] );
			if ( 0 === $o || $o <= 0x1F || 0x7F === $o ) {
				return true;
			}
		}
		return false;
	}

	private static function scrub_request(): void {
		unset( $_POST[ OpenAiCredentialStore::FIELD ], $_REQUEST[ OpenAiCredentialStore::FIELD ] );
	}

	/**
	 * @param 'saved'|'replaced'|'cleared'|'rejected'|'forbidden' $code
	 */
	private static function redirect( string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => \UniversalProductReviews\Admin\SettingsPage::MENU_SLUG,
					'tab'       => 'controls',
					'upr_cred'  => sanitize_key( $code ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
