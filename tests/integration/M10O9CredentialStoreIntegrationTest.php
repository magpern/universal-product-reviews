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

	public function set_up(): void {
		parent::set_up();
		CredentialResolver::set_test_credential( null );
		OpenAiCredentialStore::clear();
	}

	public function tear_down(): void {
		CredentialResolver::set_test_credential( null );
		OpenAiCredentialStore::clear();
		parent::tear_down();
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
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$user = new \WP_User( $user_id );
		$user->add_cap( 'manage_woocommerce' );

		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new \RuntimeException( 'redirect:' . $location );
			}
		);

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

		$this->assertArrayNotHasKey( OpenAiCredentialStore::FIELD, $_POST );
		$this->assertArrayNotHasKey( OpenAiCredentialStore::FIELD, $_REQUEST );
		$this->assertSame( 'sk-admin-posted-key', OpenAiCredentialStore::decrypt()->plaintext() );
		$this->assertTrue( in_array( OpenAiCredentialStore::autoload_flag(), array( 'no', 'off' ), true ) );
	}

	public function test_admin_rejects_nul_without_write(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$user = new \WP_User( $user_id );
		$user->add_cap( 'manage_woocommerce' );

		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new \RuntimeException( 'redirect:' . $location );
			}
		);

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
		$this->assertArrayNotHasKey( OpenAiCredentialStore::FIELD, $_POST );
	}

	public function test_admin_requires_confirm(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );
		$user = new \WP_User( $user_id );
		$user->add_cap( 'manage_woocommerce' );

		add_filter(
			'wp_redirect',
			static function ( string $location ): string {
				throw new \RuntimeException( 'redirect:' . $location );
			}
		);

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
	}
}
