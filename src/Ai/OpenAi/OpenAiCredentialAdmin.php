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

	public const ACTION        = 'upr_openai_credential';
	public const CONFIRM_SAVE  = 'upr_confirm_openai_credential_save';
	public const CONFIRM_CLEAR = 'upr_confirm_openai_credential_clear';
	public const INTENT_CLEAR  = 'upr_openai_credential_clear';

	/**
	 * Handle admin-post. Never echoes secrets.
	 * Scrubs the submitted key from $_POST/$_REQUEST before every exit path,
	 * including invalid-nonce (does not use check_admin_referer, which can die first).
	 */
	public static function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			self::scrub_request();
			wp_die( esc_html__( 'Sorry, you are not allowed to do this.', 'universal-product-reviews' ), 403 );
		}

		if ( ! self::nonce_ok() ) {
			self::scrub_request();
			wp_die( esc_html__( 'The link you followed has expired.', 'universal-product-reviews' ), 403 );
		}

		$clear_intent  = self::posted_flag( self::INTENT_CLEAR );
		$confirm_save  = self::posted_flag( self::CONFIRM_SAVE );
		$confirm_clear = self::posted_flag( self::CONFIRM_CLEAR );

		// Save and clear in one request → no write. Never cast a non-string key.
		if ( $clear_intent && ( $confirm_save || self::posted_nonempty_key() ) ) {
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

	/**
	 * WordPress-native nonce check without terminating before scrub is possible.
	 */
	private static function nonce_ok(): bool {
		if ( ! isset( $_REQUEST['_wpnonce'] ) || ! is_string( $_REQUEST['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended
		$nonce = wp_unslash( $_REQUEST['_wpnonce'] );
		if ( ! is_string( $nonce ) ) {
			return false;
		}
		return false !== wp_verify_nonce( $nonce, self::ACTION );
	}

	/**
	 * Checkbox / intent flag: only the string "1" (after unslash) counts.
	 */
	private static function posted_flag( string $key ): bool {
		if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		return '1' === wp_unslash( $_POST[ $key ] );
	}

	/**
	 * True when a PHP-string key field is present and non-empty after unslash.
	 * Arrays / non-strings never count as a posted key (and are not cast).
	 */
	private static function posted_nonempty_key(): bool {
		if ( ! isset( $_POST[ OpenAiCredentialStore::FIELD ] ) || ! is_string( $_POST[ OpenAiCredentialStore::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return false;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
		$raw = wp_unslash( $_POST[ OpenAiCredentialStore::FIELD ] );
		return is_string( $raw ) && '' !== $raw;
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
					'page'     => \UniversalProductReviews\Admin\SettingsPage::MENU_SLUG,
					'tab'      => 'controls',
					'upr_cred' => sanitize_key( $code ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
