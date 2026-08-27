<?php
/**
 * Shared helpers for M2/M3/B1 integration tests.
 *
 * @package UniversalProductReviews
 */

declare( strict_types=1 );

namespace UniversalProductReviews\Tests\Integration;

use UniversalProductReviews\Database\Migrator;
use UniversalProductReviews\Invitations\InviteRepository;
use UniversalProductReviews\Invitations\ScheduleStates;
use UniversalProductReviews\Tokens\FormSessionAuthenticator;
use UniversalProductReviews\Tokens\TokenService;

trait M2TestHelpers {

	protected function upr_create_product(): int {
		$product = new \WC_Product_Simple();
		$product->set_name( 'UPR Test Product' );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_regular_price( '10' );
		$product->set_price( '10' );
		$product->set_virtual( true );
		$product->save();
		$id = (int) $product->get_id();
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	/**
	 * @return array{order:\WC_Order,order_item_id:int,product_id:int}
	 */
	protected function upr_create_order_with_item( int $product_id ): array {
		$product = wc_get_product( $product_id );
		$this->assertNotFalse( $product );

		$order = wc_create_order();
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$item_id = $order->add_product( $product, 1 );
		$this->assertNotFalse( $item_id );
		$item = $order->get_item( (int) $item_id );
		$this->assertNotFalse( $item );
		if ( (float) $item->get_total() <= 0 ) {
			$item->set_subtotal( 10 );
			$item->set_total( 10 );
			$item->save();
		}
		$order->set_status( 'completed' );
		$order->set_date_completed( gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) ) );
		$order->calculate_totals( false );
		$order->save();

		$order = wc_get_order( $order->get_id() );
		$this->assertNotFalse( $order );
		$item = $order->get_item( (int) $item_id );
		$this->assertNotFalse( $item );
		$this->assertGreaterThan( 0.0, (float) $item->get_total() );

		return array(
			'order'         => $order,
			'order_item_id' => (int) $item_id,
			'product_id'    => $product_id,
		);
	}

	protected function upr_prepare_session_invite( int $order_item_id, int $product_id, int $order_id ): array {
		InviteRepository::upsert(
			$order_item_id,
			array(
				'order_id'        => $order_id,
				'product_id'      => $product_id,
				'schedule_state'  => ScheduleStates::INITIAL_SENT,
				'initial_sent_at' => current_time( 'mysql', true ),
			)
		);
		$issued = TokenService::issue_invite( $order_item_id, $product_id );
		$this->assertNotNull( $issued );
		$exchanged = TokenService::exchange_invite( $issued['raw'] );
		$this->assertNotNull( $exchanged );
		$session = FormSessionAuthenticator::current_session();
		$this->assertNotNull( $session );
		return array(
			'invite_token_id' => (int) $issued['id'],
			'session'         => $session,
		);
	}

	/**
	 * Ensure schema tables exist. Clears any orphaned migrate lock left by PHPUnit
	 * rolling back the options-table DELETE from a prior Migrator::release_lock().
	 */
	protected function upr_ensure_schema(): void {
		delete_option( Migrator::LOCK_KEY );
		$ref  = new \ReflectionClass( Migrator::class );
		$prop = $ref->getProperty( 'owner_token' );
		$prop->setAccessible( true );
		$prop->setValue( null );
		$this->assertTrue( Migrator::upgrade_now(), 'schema upgrade must succeed' );
		$this->assertTrue( Migrator::tables_exist(), 'upr schema tables must exist' );
	}

	/**
	 * Opt into invitation email scheduling/sending (fail-closed default is off since v0.3.0).
	 */
	protected function upr_enable_invitation_emails(): void {
		update_option( \UniversalProductReviews\Config\Options::INVITATION_EMAILS_ENABLED, 'yes', false );
	}

	protected function upr_use_logging_mail_transport(): void {
		\UniversalProductReviews\Email\LoggingMailTransport::reset();
		add_filter(
			'upr_mail_transport',
			static function () {
				return new \UniversalProductReviews\Email\LoggingMailTransport();
			}
		);
	}
}
