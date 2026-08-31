<?php
/**
 * M10 O9′: stored credential option lifecycle and resolver precedence.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Ai\OpenAi\CredentialResolver;
use UniversalProductReviews\Ai\OpenAi\OpenAiCredentialAdmin;
use UniversalProductReviews\Ai\OpenAi\OpenAiCredentialCipher;
use UniversalProductReviews\Ai\OpenAi\OpenAiCredentialStore;
use WP_UnitTestCase;

final class M10O9CredentialStoreIntegrationTest extends WP_UnitTestCase {

	/** @var callable|null */
	private $redirect_filter = null;

	public function set_up(): void {
		parent::set_up();
		CredentialResolver::set_test_credential( null );
		OpenAiCredentialStore::clear();
		$this->redirect_filter = null;
	}

	public function tear_down(): void {
		if ( null !== $this->redirect_filter ) {
			remove_filter( 'wp_redirect', $this->redirect_filter );
			$this->redirect_filter = null;
		}
		CredentialResolver::set_test_credential( null );
		OpenAiCredentialStore::clear();
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	private function trap_redirect(): void {
		$this->redirect_filter = static function ( string $location ): string {
			throw new \RuntimeException( 'redirect:' . $location );
		};
		add_filter( 'wp_redirect', $this->redirect_filter );
	}

	private function grant_manage_woocommerce(): int {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$user = new \WP_User( $user_id );
		$user->add_cap( 'manage_woocommerce' );
		return $user_id;
	}

	private function assert_field_scrubbed(): void {
		$this->assertArrayNotHasKey( OpenAiCredentialStore::FIELD, $_POST );
		$this->assertArrayNotHasKey( OpenAiCredentialStore::FIELD, $_REQUEST );
	}

	public function test_first_save_autoload_false_and_absent_from_alloptions(): void {
		$cipher   = new OpenAiCredentialCipher();
		$envelope = $cipher->encrypt( 'sk-first-save-autoload' );
		$this->assertTrue( OpenAiCredentialStore::save_envelope( $envelope ) );

		$flag = OpenAiCredentialStore::autoload_flag();
		$this->assertTrue( in_array( $flag, array( 'no', 'off' ), true ), 'autoload must be no/off, got: ' . $flag );

		$all = wp_load_alloptions();
		$this->assertArrayNotHasKey( OpenAiCredentialStore::OPTION, $all );

		$status = CredentialResolver::status();
		$this->assertTrue( $status['present'] );
		$this->assertSame( CredentialResolver::SOURCE_STORED, $status['source'] );
		$this->assertStringNotContainsString( 'sk-first-save-autoload', wp_json_encode( $status ) ?: '' );
	}

	public function test_replace_preserves_autoload_and_overwrites_invalidated(): void {
		$bad = 'upr1:' . str_repeat( 'A', 40 );
		$this->assertTrue( OpenAiCredentialStore::save_envelope( $bad ) );
		$this->assertTrue( OpenAiCredentialStore::is_undecryptable() );

		$cipher = new OpenAiCredentialCipher();
		$fresh  = $cipher->encrypt( 'sk-replaced-over-invalid' );
		$this->assertTrue( OpenAiCredentialStore::save_envelope( $fresh ) );
		$flag = OpenAiCredentialStore::autoload_flag();
		$this->assertTrue( in_array( $flag, array( 'no', 'off' ), true ) );

		$result = OpenAiCredentialStore::decrypt();
		$this->assertSame( 'sk-replaced-over-invalid', $result->plaintext() );
	}

	public function test_precedence_constant_then_env_then_stored(): void {
		$cipher = new OpenAiCredentialCipher();
		OpenAiCredentialStore::save_envelope( $cipher->encrypt( 'sk-stored-only' ) );

		CredentialResolver::set_test_credential( 'sk-from-constant', CredentialResolver::SOURCE_CONSTANT );
		$this->assertSame( 'sk-from-constant', CredentialResolver::require_secret() );
		$this->assertSame( CredentialResolver::SOURCE_CONSTANT, CredentialResolver::status()['source'] );

		CredentialResolver::set_test_credential( 'sk-from-env', CredentialResolver::SOURCE_ENVIRONMENT );
		$this->assertSame( CredentialResolver::SOURCE_ENVIRONMENT, CredentialResolver::status()['source'] );

		CredentialResolver::set_test_credential( null );
		$this->assertSame( CredentialResolver::SOURCE_STORED, CredentialResolver::status()['source'] );
		$this->assertSame( 'sk-stored-only', CredentialResolver::require_secret() );
	}

	public function test_admin_save_scrubs_and_persists(): void {
		$this->grant_manage_woocommerce();
		$this->trap_redirect();

		$_POST    = array(
			'_wpnonce'                          => wp_create_nonce( OpenAiCredentialAdmin::ACTION ),
			OpenAiCredentialAdmin::CONFIRM_SAVE => '1',
			OpenAiCredentialStore::FIELD        => 'sk-admin-posted-key',
		);
		$_REQUEST = $_POST;

		try {
			OpenAiCredentialAdmin::handle();
			$this->fail( 'Expected redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'upr_cred=saved', $e->getMessage() );
		}

		$this->assert_field_scrubbed();
		$this->assertSame( 'sk-admin-posted-key', OpenAiCredentialStore::decrypt()->plaintext() );
		$this->assertTrue( in_array( OpenAiCredentialStore::autoload_flag(), array( 'no', 'off' ), true ) );
	}

	public function test_invalid_nonce_scrubs_and_no_write(): void {
		$this->grant_manage_woocommerce();

		$_POST    = array(
			'_wpnonce'                          => 'definitely-not-a-valid-nonce',
			OpenAiCredentialAdmin::CONFIRM_SAVE => '1',
			OpenAiCredentialStore::FIELD        => 'sk-invalid-nonce-secret',
		);
		$_REQUEST = $_POST;

		try {
			OpenAiCredentialAdmin::handle();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}

		$this->assertFalse( OpenAiCredentialStore::exists() );
		$this->assert_field_scrubbed();
	}

	public function test_capability_denial_scrubs_and_no_write(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$this->assertFalse( current_user_can( 'manage_woocommerce' ) );

		$_POST    = array(
			'_wpnonce'                          => wp_create_nonce( OpenAiCredentialAdmin::ACTION ),
			OpenAiCredentialAdmin::CONFIRM_SAVE => '1',
			OpenAiCredentialStore::FIELD        => 'sk-cap-denied-secret',
		);
		$_REQUEST = $_POST;

		try {
			OpenAiCredentialAdmin::handle();
			$this->fail( 'Expected wp_die' );
		} catch ( \WPDieException $e ) {
			$this->assertNotSame( '', $e->getMessage() );
		}

		$this->assertFalse( OpenAiCredentialStore::exists() );
		$this->assert_field_scrubbed();
	}

	public function test_non_string_posted_key_rejects_scrubs_no_write(): void {
		$this->grant_manage_woocommerce();
		$this->trap_redirect();

		$_POST    = array(
			'_wpnonce'                          => wp_create_nonce( OpenAiCredentialAdmin::ACTION ),
			OpenAiCredentialAdmin::CONFIRM_SAVE => '1',
			OpenAiCredentialStore::FIELD        => array( 'sk-array-not-allowed' ),
		);
		$_REQUEST = $_POST;

		$before = error_get_last();
		try {
			OpenAiCredentialAdmin::handle();
			$this->fail( 'Expected redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'upr_cred=rejected', $e->getMessage() );
		}
		$after = error_get_last();
		$this->assertSame( $before, $after, 'non-string key must not raise a PHP warning' );

		$this->assertFalse( OpenAiCredentialStore::exists() );
		$this->assert_field_scrubbed();
	}

	public function test_empty_confirmed_save_is_noop_and_scrubbed(): void {
		$this->grant_manage_woocommerce();
		$this->trap_redirect();

		$cipher = new OpenAiCredentialCipher();
		OpenAiCredentialStore::save_envelope( $cipher->encrypt( 'sk-keep-me' ) );

		$_POST    = array(
			'_wpnonce'                          => wp_create_nonce( OpenAiCredentialAdmin::ACTION ),
			OpenAiCredentialAdmin::CONFIRM_SAVE => '1',
			OpenAiCredentialStore::FIELD        => '',
		);
		$_REQUEST = $_POST;

		try {
			OpenAiCredentialAdmin::handle();
			$this->fail( 'Expected redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'upr_cred=saved', $e->getMessage() );
		}

		$this->assertSame( 'sk-keep-me', OpenAiCredentialStore::decrypt()->plaintext() );
		$this->assert_field_scrubbed();
	}

	public function test_confirmed_clear_deletes_and_scrubs(): void {
		$this->grant_manage_woocommerce();
		$this->trap_redirect();

		$cipher = new OpenAiCredentialCipher();
		OpenAiCredentialStore::save_envelope( $cipher->encrypt( 'sk-to-clear' ) );
		$this->assertTrue( OpenAiCredentialStore::exists() );

		$_POST    = array(
			'_wpnonce'                           => wp_create_nonce( OpenAiCredentialAdmin::ACTION ),
			OpenAiCredentialAdmin::INTENT_CLEAR  => '1',
			OpenAiCredentialAdmin::CONFIRM_CLEAR => '1',
			OpenAiCredentialStore::FIELD         => 'sk-should-be-ignored-on-clear',
		);
		// Clear + nonempty key is a conflict → rejected. Use empty/absent field for clear-only.
		unset( $_POST[ OpenAiCredentialStore::FIELD ] );
		$_REQUEST = $_POST;

		try {
			OpenAiCredentialAdmin::handle();
			$this->fail( 'Expected redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'upr_cred=cleared', $e->getMessage() );
		}

		$this->assertFalse( OpenAiCredentialStore::exists() );
		$this->assert_field_scrubbed();
	}

	public function test_confirmed_clear_with_field_present_scrubs(): void {
		// Clear intent alone (no key field) already covered; this proves scrub when
		// a key was posted on a pure-clear path after conflict is avoided by empty string.
		$this->grant_manage_woocommerce();
		$this->trap_redirect();

		$cipher = new OpenAiCredentialCipher();
		OpenAiCredentialStore::save_envelope( $cipher->encrypt( 'sk-to-clear-2' ) );

		$_POST    = array(
			'_wpnonce'                           => wp_create_nonce( OpenAiCredentialAdmin::ACTION ),
			OpenAiCredentialAdmin::INTENT_CLEAR  => '1',
			OpenAiCredentialAdmin::CONFIRM_CLEAR => '1',
			OpenAiCredentialStore::FIELD         => '',
		);
		$_REQUEST = $_POST;

		try {
			OpenAiCredentialAdmin::handle();
			$this->fail( 'Expected redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'upr_cred=cleared', $e->getMessage() );
		}

		$this->assertFalse( OpenAiCredentialStore::exists() );
		$this->assert_field_scrubbed();
	}

	public function test_save_clear_conflict_no_write_and_scrubbed(): void {
		$this->grant_manage_woocommerce();
		$this->trap_redirect();

		$cipher = new OpenAiCredentialCipher();
		OpenAiCredentialStore::save_envelope( $cipher->encrypt( 'sk-pre-conflict' ) );

		$_POST    = array(
			'_wpnonce'                           => wp_create_nonce( OpenAiCredentialAdmin::ACTION ),
			OpenAiCredentialAdmin::INTENT_CLEAR  => '1',
			OpenAiCredentialAdmin::CONFIRM_CLEAR => '1',
			OpenAiCredentialAdmin::CONFIRM_SAVE  => '1',
			OpenAiCredentialStore::FIELD         => 'sk-conflict-new',
		);
		$_REQUEST = $_POST;

		try {
			OpenAiCredentialAdmin::handle();
			$this->fail( 'Expected redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'upr_cred=rejected', $e->getMessage() );
		}

		$this->assertSame( 'sk-pre-conflict', OpenAiCredentialStore::decrypt()->plaintext() );
		$this->assert_field_scrubbed();
	}

	public function test_save_clear_conflict_with_array_key_no_cast_warning(): void {
		$this->grant_manage_woocommerce();
		$this->trap_redirect();

		$_POST    = array(
			'_wpnonce'                          => wp_create_nonce( OpenAiCredentialAdmin::ACTION ),
			OpenAiCredentialAdmin::INTENT_CLEAR => '1',
			OpenAiCredentialAdmin::CONFIRM_SAVE => '1',
			OpenAiCredentialStore::FIELD        => array( 'sk-array' ),
		);
		$_REQUEST = $_POST;

		$before = error_get_last();
		try {
			OpenAiCredentialAdmin::handle();
			$this->fail( 'Expected redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'upr_cred=rejected', $e->getMessage() );
		}
		$after = error_get_last();
		$this->assertSame( $before, $after );

		$this->assertFalse( OpenAiCredentialStore::exists() );
		$this->assert_field_scrubbed();
	}

	public function test_admin_rejects_nul_without_write(): void {
		$this->grant_manage_woocommerce();
		$this->trap_redirect();

		$_POST    = array(
			'_wpnonce'                          => wp_create_nonce( OpenAiCredentialAdmin::ACTION ),
			OpenAiCredentialAdmin::CONFIRM_SAVE => '1',
			OpenAiCredentialStore::FIELD        => "sk-\x00-bad",
		);
		$_REQUEST = $_POST;

		try {
			OpenAiCredentialAdmin::handle();
			$this->fail( 'Expected redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'upr_cred=rejected', $e->getMessage() );
		}

		$this->assertFalse( OpenAiCredentialStore::exists() );
		$this->assert_field_scrubbed();
	}

	public function test_admin_requires_confirm(): void {
		$this->grant_manage_woocommerce();
		$this->trap_redirect();

		$_POST    = array(
			'_wpnonce'                   => wp_create_nonce( OpenAiCredentialAdmin::ACTION ),
			OpenAiCredentialStore::FIELD => 'sk-no-confirm',
		);
		$_REQUEST = $_POST;

		try {
			OpenAiCredentialAdmin::handle();
			$this->fail( 'Expected redirect' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'upr_cred=rejected', $e->getMessage() );
		}
		$this->assertFalse( OpenAiCredentialStore::exists() );
		$this->assert_field_scrubbed();
	}
}
