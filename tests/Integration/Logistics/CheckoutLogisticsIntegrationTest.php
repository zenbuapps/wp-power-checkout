<?php
/**
 * 物流共用結帳整合（傳統 + Blocks）：子類型 rate、選店（session 權杖）、未選門市擋下單、下單寫 meta
 *
 * 以 PAYUNi 物流為實作者（ISupportsCheckoutStoreSelection）；綠界有自己的整合，不應被這層接手。
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\PayuniLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Payuni\Http\PayuniLogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\PayuniLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\WC_PayuniLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\CartLogisticsSession;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\CartLogisticsApiService;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\CheckoutLogisticsIntegration;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * @group integration
 * @group logistics
 * @group payuni
 */
final class CheckoutLogisticsIntegrationTest extends TestCase {

	private const HASH_KEY = '12345678901234567890123456789012';
	private const HASH_IV  = '1234567890123456';

	/** 啟用 PAYUNi 物流 + 開 WC session */
	public function set_up(): void {
		parent::set_up();
		\putenv( 'API_MODE=mock' );
		$wc = \WC();
		if ( ! isset( $wc->session ) || ! $wc->session instanceof \WC_Session ) {
			$wc->initialize_session();
		}
		$wc->session->set_customer_session_cookie( true );

		$this->enable_provider(
			PayuniLogisticsProvider::ID,
			[
				'mode'            => 'test',
				'mer_id'          => 'S0000000000',
				'hash_key'        => self::HASH_KEY,
				'hash_iv'         => self::HASH_IV,
				'cvs_lgs_type'    => 'B2C',
				'enabled_methods' => [ 'SEVEN', 'HOME' ],
			]
		);
		PayuniLogisticsSettingsDTO::reset();
	}

	/** 清理 */
	public function tear_down(): void {
		CartLogisticsSession::clear();
		\WC()->session->set( 'chosen_shipping_methods', null );
		PayuniLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( PayuniLogisticsProvider::ID ) );
		\delete_option( ProviderUtils::get_option_name( EcpayLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * @param string $sub_type 子類型
	 * @return \WC_Order 已選 PAYUNi 運送方式的訂單
	 */
	private function create_order_with_rate( string $sub_type, string $payment_method = 'bacs' ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => $payment_method,
			]
		);
		$item  = new \WC_Order_Item_Shipping();
		$item->set_method_id( WC_PayuniLogisticsShipping::METHOD_ID );
		$item->set_method_title( 'PAYUNi' );
		$item->add_meta_data( 'sub_type', $sub_type );
		$order->add_item( $item );
		$order->save();
		return $order;
	}

	/** @param string $sub_type 選定的子類型 */
	private function choose_rate( string $sub_type ): void {
		\WC()->session->set( 'chosen_shipping_methods', [ WC_PayuniLogisticsShipping::METHOD_ID . ':3:' . $sub_type ] );
	}

	/** 把門市寫進目前 session（模擬選店回呼） */
	private function select_store( string $sub_type = 'SEVEN' ): void {
		$token = CartLogisticsSession::issue_token();
		CartLogisticsSession::store_by_token(
			$token,
			[
				'store_id'   => '273927',
				'store_name' => '敦安門市',
				'store_addr' => '台北市大安區安和路一段86號',
				'sub_type'   => $sub_type,
			]
		);
	}

	// ========== providers / rate ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_啟用的PAYUNi物流會被共用整合接手_綠界不會(): void {
		$this->enable_provider( EcpayLogisticsProvider::ID, [ 'enabled_methods' => [ 'FAMI' ] ] );

		$providers = CheckoutLogisticsIntegration::providers();

		$this->assertArrayHasKey( WC_PayuniLogisticsShipping::METHOD_ID, $providers );
		$this->assertArrayNotHasKey( 'ecpay_logistics', $providers, '綠界有自己的整合' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_運送方式每個子類型一個rate並帶sub_type(): void {
		$method = new WC_PayuniLogisticsShipping();
		$method->calculate_shipping(
			[
				'contents'      => [],
				'contents_cost' => 100,
			]
		);

		$this->assertCount( 2, $method->rates );
		$subs = [];
		foreach ( $method->rates as $rate_id => $rate ) {
			$meta   = $rate->get_meta_data();
			$subs[] = $meta['sub_type'];
			$this->assertStringEndsWith( ':' . $meta['sub_type'], (string) $rate_id );
		}
		$this->assertSame( [ 'SEVEN', 'HOME' ], $subs );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_rate_id解析(): void {
		$this->assertSame( [ 'payuni_logistics', 'SEVEN' ], CheckoutLogisticsIntegration::parse_rate_id( 'payuni_logistics:SEVEN' ) );
		$this->assertSame( [ 'payuni_logistics', 'HOME' ], CheckoutLogisticsIntegration::parse_rate_id( 'payuni_logistics:5:HOME' ) );
		$this->assertNull( CheckoutLogisticsIntegration::parse_rate_id( 'flat_rate:1' ) );
		$this->assertNull( CheckoutLogisticsIntegration::parse_rate_id( 'payuni_logistics' ) );
	}

	// ========== 未選門市擋下單 ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_選超商但沒選門市_傳統結帳擋下(): void {
		$this->choose_rate( 'SEVEN' );
		$errors = new \WP_Error();

		CheckoutLogisticsIntegration::validate_classic( [], $errors );

		$this->assertContains( 'pc_logistics_store_required', $errors->get_error_codes() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_選超商但沒選門市_Blocks結帳回400(): void {
		$this->choose_rate( 'SEVEN' );
		$this->expectException( \Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class );
		CheckoutLogisticsIntegration::validate_blocks( $this->create_wc_order() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_已選門市或選宅配不擋(): void {
		$this->choose_rate( 'HOME' );
		$this->assertNull( CheckoutLogisticsIntegration::missing_store_message() );

		$this->choose_rate( 'SEVEN' );
		$this->select_store( 'SEVEN' );
		$this->assertNull( CheckoutLogisticsIntegration::missing_store_message() );
	}

	/**
	 * 別家的超商門市（子類型不同）不能拿來充數
	 *
	 * @test
	 * @group security
	 */
	public function test_其他子類型的門市不算已選(): void {
		$this->choose_rate( 'SEVEN' );
		$this->select_store( 'FAMI' );
		$this->assertNotNull( CheckoutLogisticsIntegration::missing_store_message() );
	}

	// ========== 下單寫 meta ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_下單把門市子類型付款情境寫進訂單並清掉session(): void {
		$this->select_store( 'SEVEN' );
		$order = $this->create_order_with_rate( 'SEVEN', 'cod' );

		CheckoutLogisticsIntegration::save_order_meta( $order );

		$meta = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( 'SEVEN', $meta->get_sub_type() );
		$this->assertSame( '273927', $meta->get_store_id() );
		$this->assertSame( '敦安門市', $meta->get_store_name() );
		$this->assertSame( 'cod', $meta->get_payment_scenario() );
		$this->assertSame( PayuniLogisticsProvider::ID, $meta->get_provider_id() );
		$this->assertNull( CartLogisticsSession::get_selected_store(), '搬完應清掉 session' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_宅配下單只寫子類型不寫門市(): void {
		$this->select_store( 'SEVEN' );
		$order = $this->create_order_with_rate( 'HOME' );

		CheckoutLogisticsIntegration::save_order_meta( $order );

		$meta = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( 'HOME', $meta->get_sub_type() );
		$this->assertSame( '', $meta->get_store_id() );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_未啟用的子類型不寫入(): void {
		$order = $this->create_order_with_rate( 'C2B_FAKE' );
		CheckoutLogisticsIntegration::save_order_meta( $order );
		$this->assertSame( '', ( new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_sub_type() );
	}

	// ========== 購物車選店 + 回呼 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_購物車選店端點回傳門市地圖並帶session權杖(): void {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/store-selection' );
		$request->set_param( 'provider', PayuniLogisticsProvider::ID );
		$request->set_param( 'sub_type', 'SEVEN' );

		$response = CartLogisticsApiService::instance()->post_logistics_store_selection_callback( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertNotEmpty( $data['data']['redirect_target'] ?? '' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_購物車選店_宅配不需選店(): void {
		$this->expectExceptionMessage( '7-ELEVEN' );
		PayuniLogisticsProvider::instance()->get_cart_store_selection( [ 'sub_type' => 'HOME' ] );
	}

	/**
	 * 門市地圖回呼（pc_st 權杖）→ 門市寫進 session → 302 回結帳頁
	 *
	 * @test
	 * @group happy
	 */
	public function test_門市回呼以權杖寫回session並導回結帳頁(): void {
		$token   = CartLogisticsSession::issue_token();
		$crypto  = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$enc     = $crypto->encrypt(
			[
				'Status'  => 'SUCCESS',
				'MapJson' => (string) \wp_json_encode(
					[
						'StoreID'   => '273927',
						'StoreName' => '敦安門市',
						'Address'   => '台北市大安區安和路一段86號',
					]
				),
			]
		);
		$request = new \WP_REST_Request( 'POST', '/power-checkout/payuni/logistics/map-callback' );
		$request->set_query_params( [ 'pc_st' => $token ] );
		$request->set_body_params(
			[
				'EncryptInfo' => $enc,
				'HashInfo'    => $crypto->hash_info( $enc ),
			]
		);

		$response = PayuniLogisticsCallback::instance()->post_logistics_map_callback_callback( $request );

		$this->assertSame( 302, $response->get_status() );
		$this->assertArrayHasKey( 'Location', $response->get_headers() );
		$store = CartLogisticsSession::get_selected_store();
		$this->assertSame( '273927', $store['store_id'] ?? '' );
		$this->assertSame( 'SEVEN', $store['sub_type'] ?? '' );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_門市回呼偽造權杖不寫入(): void {
		CartLogisticsSession::issue_token();
		$crypto = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$enc    = $crypto->encrypt( [ 'MapJson' => (string) \wp_json_encode( [ 'StoreID' => '999999' ] ) ] );

		$request = new \WP_REST_Request( 'POST', '/power-checkout/payuni/logistics/map-callback' );
		$request->set_query_params( [ 'pc_st' => \str_repeat( 'x', 64 ) ] );
		$request->set_body_params(
			[
				'EncryptInfo' => $enc,
				'HashInfo'    => $crypto->hash_info( $enc ),
			]
		);
		PayuniLogisticsCallback::instance()->post_logistics_map_callback_callback( $request );

		$this->assertNull( CartLogisticsSession::get_selected_store() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_cart_extension與前端設定(): void {
		$this->select_store( 'SEVEN' );

		$ext = CheckoutLogisticsIntegration::cart_extension_data();
		$this->assertSame( '273927', $ext['store_id'] );

		$data = CheckoutLogisticsIntegration::get_frontend_data();
		$this->assertSame( PayuniLogisticsProvider::ID, $data['providers'][0]['provider'] );
		$this->assertSame( [ 'SEVEN' ], $data['providers'][0]['cvs_sub_types'] );
		$this->assertStringContainsString( 'logistics/store-selection', $data['store_selection_url'] );
	}
}
