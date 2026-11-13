<?php
/**
 * 結帳測試 harness 自我驗證（基礎設施測試）+ 試驗 S1
 *
 * 執行：bash "<scratchpad>/gate.sh" ut tests/Integration/Checkout/CheckoutHarnessTest.php
 *
 * @group checkout
 */

declare( strict_types=1 );

namespace Tests\Integration\Checkout;

/**
 * Class CheckoutHarnessTest
 * 驗證 CheckoutTestCase 的 helper 在 WP_UnitTestCase 下可正常運作。
 */
final class CheckoutHarnessTest extends CheckoutTestCase {

	/**
	 * @group smoke
	 */
	public function test_能建立購物車並加入商品(): void {
		// needs_shipping() 要求全站至少有一個啟用的運送方式
		$this->create_free_shipping_zone(
			[
				[
					'id'       => 'flat_rate',
					'settings' => [ 'cost' => '100' ],
				],
			]
			);
		$this->init_cart_session();
		$product = $this->create_product( '實體商品', 500.0, false );
		$this->add_to_cart( [ [ $product, 2 ] ] );

		$this->assertInstanceOf( \WC_Cart::class, \WC()->cart );
		$this->assertSame( 2, \WC()->cart->get_cart_contents_count() );
		$this->assertTrue( \WC()->cart->needs_shipping() );
	}

	/**
	 * @group smoke
	 */
	public function test_虛擬商品購物車不需運送(): void {
		$this->init_cart_session();
		$product = $this->create_product( '虛擬商品', 100.0, true );
		$this->add_to_cart(
			[
				[
					'product' => $product,
					'qty'     => 1,
				],
			]
			);

		$this->assertFalse( \WC()->cart->needs_shipping() );
		$this->assertTrue( $product->is_virtual() );
	}

	/**
	 * @group smoke
	 */
	public function test_classic_validate_回傳WP_Error(): void {
		$this->init_cart_session();
		$this->add_to_cart( [ [ $this->create_product( '實體商品', 500.0, false ), 1 ] ] );

		$errors = $this->classic_validate( [ 'billing_phone' => '0912345678' ] );

		$this->assertInstanceOf( \WP_Error::class, $errors );
		// 缺必填欄位，傳統結帳驗證必有錯誤
		$this->assertTrue( $errors->has_errors(), '空白必填欄位應該產生驗證錯誤' );
	}

	/**
	 * @group smoke
	 */
	public function test_set_checkout_settings_寫入設定並重建WC_Countries(): void {
		$before = \WC()->countries;
		$this->set_checkout_settings( [ 'last_name_first' => 'yes' ] );

		$stored = \get_option( 'woocommerce_checkout_enhancement_settings' );
		$this->assertIsArray( $stored );
		$this->assertSame( 'yes', $stored['last_name_first'] );
		$this->assertSame( 'no', $stored['tw_address'], '未指定的鍵應使用預設值' );
		$this->assertNotSame( $before, \WC()->countries, 'R6：必須重建 WC()->countries 避免 locale 快取殘留' );
	}

	/**
	 * @group smoke
	 */
	public function test_create_free_shipping_zone_建立運送區域與方式(): void {
		$zone = $this->create_free_shipping_zone(
			[
				[
					'id'       => 'flat_rate',
					'settings' => [ 'cost' => '100' ],
				],
				[
					'id'       => 'free_shipping',
					'settings' => [
						'requires'   => 'min_amount',
						'min_amount' => '1000',
					],
				],
			]
		);

		$ids = array_map( static fn( $m ) => $m->id, $zone->get_shipping_methods( true ) );
		$this->assertContains( 'flat_rate', $ids );
		$this->assertContains( 'free_shipping', $ids );
	}

	/**
	 * @group smoke
	 */
	public function test_capture_logs_攔截Plugin_logger訊息(): void {
		$this->capture_logs();

		\J7\PowerCheckout\Plugin::logger( 'harness-log-測試#1007', 'error' );

		$this->assert_log_contains( '1007', 'error' );
	}

	/**
	 * S1 補充：同一條 Store API 路徑，地址完整時能真的建單（證明 harness 可走到下單成功）
	 *
	 * @group smoke
	 */
	public function test_S1_完整TW地址_Store_API送單拿得到回應(): void {
		$this->enable_bacs_gateway();
		$this->init_cart_session();
		$this->add_to_cart( [ [ $this->create_product( '虛擬商品', 100.0, true ), 1 ] ] );

		$address = [
			'first_name' => '小明',
			'last_name'  => '王',
			'company'    => '',
			'address_1'  => '中正路1號',
			'address_2'  => '',
			'city'       => '臺北市',
			'state'      => '臺北市',
			'postcode'   => '100',
			'country'    => 'TW',
			'phone'      => '0912345678',
		];

		$response = $this->store_api_checkout(
			[
				'billing_address'  => $address + [ 'email' => 'buyer@example.com' ],
				'shipping_address' => $address,
				'payment_method'   => 'bacs',
			]
		);

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 200, $response->get_status(), '完整地址的虛擬商品 Store API 送單應成功：' . \wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
		$this->assertArrayHasKey( 'order_id', $data );
		$this->assertInstanceOf( \WC_Order::class, \wc_get_order( (int) $data['order_id'] ) );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
	}

	/**
	 * 試驗 S1：全虛擬購物車 + 空地址，走真實 Store API（rest_do_request）送單
	 *
	 * 斷言僅限基礎設施層級：拿得到 WP_REST_Response 即可（是否 200 / 400 屬於結論，記錄在 CheckoutTestCase 檔頭）。
	 *
	 * @group smoke
	 */
	public function test_S1_全虛擬購物車空地址_Store_API送單拿得到回應(): void {
		$this->enable_bacs_gateway();
		$this->init_cart_session();
		$product = $this->create_product( '虛擬商品', 100.0, true );
		$this->add_to_cart( [ [ $product, 1 ] ] );

		$empty_address = [
			'first_name' => '',
			'last_name'  => '',
			'company'    => '',
			'address_1'  => '',
			'address_2'  => '',
			'city'       => '',
			'state'      => '',
			'postcode'   => '',
			'country'    => '',
			'phone'      => '',
		];

		$response = $this->store_api_checkout(
			[
				'billing_address'  => $empty_address + [ 'email' => 'buyer@example.com' ],
				'shipping_address' => $empty_address,
				'payment_method'   => 'bacs',
			]
		);

		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $response->get_status(), "預期原生行為 400，實際：{$response->get_status()}" );
		// 購物車有成功載入（否則會是 woocommerce_rest_cart_empty），並走到 WC 原生地址驗證；F4 開關未開時空 country 照樣被擋
		$this->assertSame( 'woocommerce_rest_invalid_address_country', $data['code'] ?? null );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
	}
}
