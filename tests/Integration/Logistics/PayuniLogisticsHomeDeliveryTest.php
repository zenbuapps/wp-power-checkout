<?php
/**
 * PAYUNi 物流：黑貓宅配建單 / 託運單 / 退貨、統一貨態、錯誤訊息、門市回呼綁定
 *
 * 修正前：
 *  - 宅配（HOME）訂單 create_shipment 一律丟「尚未選店」，provider meta 卻宣稱支援 HOME。
 *  - 門市回呼只讀 POST body，拿不到 MapReturnURL query 的 pc_oid / pc_key → 選好的門市永遠寫不進訂單。
 *  - MapReturnURL 預設空字串（後台沒有輸入欄位）→ 送出相對路徑，選完門市回不來。
 *  - PAYUNi 業務錯誤（如門市代碼已異動 DEF05019）只顯示 "unknown"，真正原因在已簽章的內層 Message。
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\HomeTradeParams;
use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\PayuniLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Payuni\Http\PayuniLogisticsApiClient;
use J7\PowerCheckout\Domains\Logistics\Payuni\Http\PayuniLogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\PayuniLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums\PayuniShipStatus;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * @group integration
 * @group logistics
 * @group payuni
 */
final class PayuniLogisticsHomeDeliveryTest extends TestCase {

	private const MER_ID   = 'S0000000000';
	private const HASH_KEY = '12345678901234567890123456789012';
	private const HASH_IV  = '1234567890123456';

	/** 啟用 payuni_logistics（mock） */
	public function set_up(): void {
		parent::set_up();
		\putenv( 'API_MODE=mock' );
		$this->enable_provider(
			PayuniLogisticsProvider::ID,
			[
				'mode'            => 'test',
				'mer_id'          => self::MER_ID,
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
		PayuniLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( PayuniLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * @param int  $total        訂單金額
	 * @param bool $with_address 是否填地址
	 * @return \WC_Order 黑貓宅配訂單
	 */
	private function create_home_order( int $total = 1000, bool $with_address = true ): \WC_Order {
		$order = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => $total,
			]
		);
		$order->set_billing_last_name( '王' );
		$order->set_billing_first_name( '小明' );
		$order->set_billing_phone( '0912345678' );
		if ( $with_address ) {
			$order->set_shipping_country( 'TW' );
			$order->set_shipping_city( '大安區' );
			$order->set_shipping_address_1( '敦化南路二段 100 號' );
		}
		$order->save();

		$meta = new LogisticsMetaKeys( $order );
		$meta->update_sub_type( 'HOME' );
		$meta->update_payment_scenario( 'online' );
		return $order;
	}

	// ========== 建單 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_宅配訂單不需選店即可建單並寫入ShipTradeNo(): void {
		$order  = $this->create_home_order();
		$result = PayuniLogisticsProvider::instance()->create_shipment( $order );

		$this->assertNotEmpty( $result['logistics_id'] );
		$this->assertSame( $result['logistics_id'], ( new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_ref() );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_已建單再呼叫不重複建單(): void {
		$order = $this->create_home_order();
		( new LogisticsMetaKeys( $order ) )->update_ref( 'EXISTING123' );

		$result = PayuniLogisticsProvider::instance()->create_shipment( \wc_get_order( $order->get_id() ) );
		$this->assertSame( 'EXISTING123', $result['logistics_id'] );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_宅配訂單沒有地址時給出明確錯誤(): void {
		$order = $this->create_home_order( 1000, false );
		$this->expectExceptionMessage( '沒有收件地址' );
		PayuniLogisticsProvider::instance()->create_shipment( $order );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_宅配金額超出30到20000元時拒絕(): void {
		$order = $this->create_home_order( 25000 );
		$this->expectExceptionMessage( '30 ~ 20000' );
		PayuniLogisticsProvider::instance()->create_shipment( $order );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_HomeTradeParams驗證配達時段與寬度截斷(): void {
		$this->assertSame( '測試測試測', HomeTradeParams::truncate_width( '測試測試測試', 10 ) );
		$this->assertSame( 'abc測', HomeTradeParams::truncate_width( 'abc測試', 5 ) );

		$this->expectExceptionMessage( 'DeliveryTimeTag' );
		HomeTradeParams::parse(
			[
				'MerTradeNo'       => 'PC1_1',
				'TradeAmt'         => 100,
				'DeliveryTimeTag'  => '03',
				'Consignee'        => '王小明',
				'ConsigneeMobile'  => '0912345678',
				'ConsigneeAddress' => '台北市大安區敦化南路二段100號',
				'ProdDesc'         => '商品',
			]
		);
	}

	// ========== 列印 / 退貨 / 查詢 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_宅配列印回傳託運單HTML(): void {
		$order = $this->create_home_order();
		( new LogisticsMetaKeys( $order ) )->update_ref( 'SHIP001' );

		$html = PayuniLogisticsProvider::instance()->print_document( \wc_get_order( $order->get_id() ) );
		$this->assertStringContainsString( 'SHIP001', $html );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_宅配退貨走黑貓退貨單並寫入退貨編號(): void {
		$order = $this->create_home_order();
		( new LogisticsMetaKeys( $order ) )->update_ref( 'SHIP002' );

		$result = PayuniLogisticsProvider::instance()->create_return( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '900000000001', $result['return_logistics_id'] );
		$this->assertSame( '900000000001', ( new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_return_ref() );
	}

	/**
	 * 沙盒實測 HOME01141：16:25 後申請退貨，取件日最少 D+2；且不可為週日
	 *
	 * @test
	 * @group edge
	 */
	public function test_黑貓退貨取件日遵守1625截止與避開週日(): void {
		// 2026-11-25（三）10:00 → 11-26（四）
		$this->assertSame( '20261126', \gmdate( 'Ymd', PayuniLogisticsProvider::home_return_pickup_day( \gmmktime( 10, 0, 0, 11, 25, 2026 ) ) ) );
		// 2026-11-25（三）16:25 → 11-27（五）
		$this->assertSame( '20261127', \gmdate( 'Ymd', PayuniLogisticsProvider::home_return_pickup_day( \gmmktime( 16, 25, 0, 11, 25, 2026 ) ) ) );
		// 2026-11-28（六）10:00 → 隔天週日跳過 → 11-30（一）
		$this->assertSame( '20261130', \gmdate( 'Ymd', PayuniLogisticsProvider::home_return_pickup_day( \gmmktime( 10, 0, 0, 11, 28, 2026 ) ) ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_查詢回傳統一貨態(): void {
		$order = $this->create_home_order();
		( new LogisticsMetaKeys( $order ) )->update_ref( 'SHIP003' );

		$result = PayuniLogisticsProvider::instance()->query_shipment( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '31', $result['status'] );
		$this->assertSame( ShippingState::IN_TRANSIT->value, $result['unified_state'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_貨態碼對應統一貨態(): void {
		$this->assertSame( ShippingState::PENDING, PayuniShipStatus::to_unified_state( '' ) );
		$this->assertSame( ShippingState::PENDING, PayuniShipStatus::to_unified_state( '91' ) );
		$this->assertSame( ShippingState::READY_FOR_PICKUP, PayuniShipStatus::to_unified_state( '32', true ) );
		$this->assertSame( ShippingState::ARRIVED, PayuniShipStatus::to_unified_state( '32', false ) );
		$this->assertSame( ShippingState::PICKED_UP, PayuniShipStatus::to_unified_state( '11', true ) );
		$this->assertSame( ShippingState::DELIVERED, PayuniShipStatus::to_unified_state( '11', false ) );
		$this->assertSame( ShippingState::EXPIRED_UNCLAIMED, PayuniShipStatus::to_unified_state( '52' ) );
		$this->assertSame( ShippingState::RETURNED, PayuniShipStatus::to_unified_state( '51' ) );
		$this->assertSame( ShippingState::CANCELLED, PayuniShipStatus::to_unified_state( '41' ) );
		$this->assertSame( ShippingState::FAILED, PayuniShipStatus::to_unified_state( '33' ) );
		$this->assertSame( ShippingState::PENDING, PayuniShipStatus::to_unified_state( '999' ), '未知碼不誤判終態' );
	}

	// ========== 錯誤訊息 ==========

	/**
	 * sandbox 實測：門市代碼異動時外層 Status=DEF05019、原因只在已簽章的內層 Message
	 *
	 * @test
	 * @group error
	 */
	public function test_業務錯誤顯示內層原因而非unknown(): void {
		$order  = $this->create_home_order();
		$crypto = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$inner  = $crypto->encrypt(
			[
				'Status'  => 'DEF05019',
				'Message' => '該門市代碼已異動，請重新更新選擇門市',
			]
		);

		try {
			( new PayuniLogisticsApiClient( $order ) )->parse_response(
				[
					'Status'      => 'DEF05019',
					'EncryptInfo' => $inner,
					'HashInfo'    => $crypto->hash_info( $inner ),
				]
			);
			$this->fail( '應拋出例外' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'DEF05019', $e->getMessage() );
			$this->assertStringContainsString( '門市代碼已異動', $e->getMessage() );
		}
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_外層報錯且無EncryptInfo時顯示外層訊息(): void {
		$order = $this->create_home_order();
		$this->expectExceptionMessage( 'API00001' );
		( new PayuniLogisticsApiClient( $order ) )->parse_response(
			[
				'Status'  => 'API00001',
				'Message' => '商店代號錯誤',
			]
		);
	}

	// ========== 設定預設值 / 門市回呼 ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_未設定時通知網址與門市回傳網址指向本站REST端點(): void {
		$settings = PayuniLogisticsSettingsDTO::instance();
		$this->assertSame( PayuniLogisticsCallback::get_status_notify_url(), $settings->notify_url );
		$this->assertSame( PayuniLogisticsCallback::get_map_return_url(), $settings->map_return_url );
		$this->assertStringStartsWith( 'http', $settings->map_return_url );
	}

	/**
	 * pc_oid / pc_key 在 query string、加密門市資料在 body：兩者都要讀到才寫得進訂單
	 *
	 * @test
	 * @group security
	 */
	public function test_門市回呼從query取得訂單綁定並寫入門市(): void {
		$order  = $this->create_home_order();
		$crypto = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$enc    = $crypto->encrypt(
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
		$request->set_query_params(
			[
				'pc_oid' => (string) $order->get_id(),
				'pc_key' => $order->get_order_key(),
			]
		);
		$request->set_body_params(
			[
				'MerID'       => self::MER_ID,
				'EncryptInfo' => $enc,
				'HashInfo'    => $crypto->hash_info( $enc ),
			]
		);

		PayuniLogisticsCallback::instance()->post_logistics_map_callback_callback( $request );

		$meta = new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '273927', $meta->get_store_id() );
		$this->assertSame( '敦安門市', $meta->get_store_name() );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_門市回呼order_key錯誤時不寫入(): void {
		$order  = $this->create_home_order();
		$crypto = new PayuniCrypto( self::HASH_KEY, self::HASH_IV );
		$enc    = $crypto->encrypt( [ 'MapJson' => (string) \wp_json_encode( [ 'StoreID' => '111111' ] ) ] );

		$request = new \WP_REST_Request( 'POST', '/power-checkout/payuni/logistics/map-callback' );
		$request->set_query_params(
			[
				'pc_oid' => (string) $order->get_id(),
				'pc_key' => 'wc_order_wrong',
			]
		);
		$request->set_body_params(
			[
				'EncryptInfo' => $enc,
				'HashInfo'    => $crypto->hash_info( $enc ),
			]
		);

		PayuniLogisticsCallback::instance()->post_logistics_map_callback_callback( $request );

		$this->assertSame( '', ( new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_store_id() );
	}
}
