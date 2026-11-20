<?php
/**
 * 新竹物流註冊 / meta / REST 測試（P5）
 *
 * - ProviderRegister：啟用後進容器、運送方式已註冊、/settings 列出 hct_logistics、REST 解析清單含 hct_logistics（不在豁免清單）
 * - meta：HOME only；create / query / print / return / cancel 全 true、has_cvs_map=false
 * - REST：print 回 HTML（不回 501）；store-selection 回 501；建單失敗回正規化錯誤碼
 * - 設定儲存後同步號段到配號器（get_settings 帶回號段剩餘量）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter HctLogisticsRegister --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Hct\Services\HctLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Hct\Services\WC_HctLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctLogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsApiService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\HctLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 新竹物流註冊
 *
 * @group integration
 * @group logistics
 * @group hct_logistics
 */
final class HctLogisticsRegisterTest extends TestCase {
	use HctLogisticsTestTrait;

	/** 清理 */
	public function tear_down(): void {
		$this->reset_hct_logistics();
		parent::tear_down();
	}

	/**
	 * 建立 REST 請求（指定 provider）
	 *
	 * @param string    $suffix 路徑尾端
	 * @param \WC_Order $order  訂單
	 * @return \WP_REST_Request
	 */
	private function make_request( string $suffix, \WC_Order $order ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}{$suffix}" );
		$request->set_param( 'id', (string) $order->get_id() );
		$request->set_param( 'provider', HctLogisticsProvider::ID );
		return $request;
	}

	/**
	 * Smoke：ID / option key
	 *
	 * @group smoke
	 */
	public function test_冒煙_ID與option_key(): void {
		$this->assertSame( 'hct_logistics', HctLogisticsProvider::ID );
		$this->assertSame( 'woocommerce_hct_logistics_settings', ProviderUtils::get_option_name( HctLogisticsProvider::ID ) );
	}

	/**
	 * Smoke：啟用後 register_hooks 放入容器；未啟用不放
	 *
	 * @group smoke
	 */
	public function test_冒煙_啟用後進容器(): void {
		ProviderUtils::$container = [];
		ProviderRegister::register_hooks();
		$this->assertArrayNotHasKey( HctLogisticsProvider::ID, ProviderUtils::$container );

		$this->setup_hct_logistics();
		ProviderUtils::$container = [];
		ProviderRegister::register_hooks();

		$this->assertInstanceOf( HctLogisticsProvider::class, ProviderUtils::$container[ HctLogisticsProvider::ID ] ?? null );
	}

	/**
	 * Smoke：WC 運送方式已註冊；/settings 列表含 hct_logistics；REST 解析清單含 hct_logistics 且不在豁免清單
	 *
	 * @group smoke
	 */
	public function test_冒煙_運送方式與設定列表(): void {
		$methods = ProviderRegister::add_shipping_method( [] );
		$this->assertSame( WC_HctLogisticsShipping::class, $methods[ WC_HctLogisticsShipping::METHOD_ID ] ?? null );

		$dtos = ProviderRegister::get_registered_provider_dtos();
		$this->assertArrayHasKey( HctLogisticsProvider::ID, $dtos );
		$this->assertSame( ProviderRegister::get_provider_class( HctLogisticsProvider::ID ), HctLogisticsProvider::class );

		$reflection = new \ReflectionClass( LogisticsApiService::class );
		$ids        = $reflection->getReflectionConstant( 'PROVIDER_IDS' );
		$exempt     = $reflection->getReflectionConstant( 'CAPABILITY_CHECK_EXEMPT_IDS' );
		$this->assertNotFalse( $ids );
		$this->assertNotFalse( $exempt );
		$this->assertContains( HctLogisticsProvider::ID, $ids->getValue() );
		$this->assertNotContains( HctLogisticsProvider::ID, $exempt->getValue() );
	}

	/**
	 * Smoke：meta 宣告
	 *
	 * @group smoke
	 */
	public function test_冒煙_meta宣告(): void {
		$meta = HctLogisticsProvider::instance()->get_provider_meta()->to_array();

		$this->assertSame( HctLogisticsProvider::ID, $meta['id'] );
		$this->assertSame( [ 'HOME' ], $meta['supported_delivery_methods'] );
		$this->assertSame(
			[
				'can_create_shipment' => true,
				'can_query_shipment'  => true,
				'has_cvs_map'         => false,
				'can_print'           => true,
				'can_return'          => true,
				'can_cancel'          => true,
			],
			$meta['capabilities']
		);
	}

	/**
	 * Smoke：REST print 回 HTML 標籤（不回 501）
	 *
	 * @group smoke
	 */
	public function test_冒煙_REST列印回HTML(): void {
		$this->setup_hct_logistics();
		$order = $this->make_hct_order();
		( new HctLogisticsMetaKeys( $order ) )->save_shipment( self::$hct_edelno, 'E1', '20261121', '4004', \base64_encode( (string) \hex2bin( $this->hct_label_hex() ) ) );

		$response = LogisticsApiService::instance()->post_logistics_with_id_print_callback( $this->make_request( '/print', \wc_get_order( $order->get_id() ) ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringContainsString( 'data:image/png;base64,', (string) $response->get_data() );
		$this->assertSame( 'text/html; charset=utf-8', $response->get_headers()['Content-Type'] ?? '' );
	}

	/**
	 * Error：REST 選店對 hct 回 501 UNSUPPORTED
	 *
	 * @group error
	 */
	public function test_REST選店回501(): void {
		$this->setup_hct_logistics();
		$order = $this->make_hct_order();
		$this->block_hct_http();

		$response = LogisticsApiService::instance()->post_logistics_with_id_store_selection_callback( $this->make_request( '/store-selection', $order ) );

		$this->assertSame( 501, $response->get_status() );
		$this->assertSame( 'UNSUPPORTED', $response->get_data()['error_code'] );
		$this->assertSame( [], $this->hct_requests );
	}

	/**
	 * Error：REST 建單號段用罄 → 409 NUMBER_EXHAUSTED
	 *
	 * @group error
	 */
	public function test_REST號段用罄回409(): void {
		$this->setup_hct_logistics(
			[
				'waybill_start' => '5',
				'waybill_end'   => '5',
			]
		);
		$this->fake_hct( 'TransData_Json', 'trans_data_ok' );
		$service = LogisticsApiService::instance();
		$service->post_logistics_with_id_create_shipment_callback( $this->make_request( '/create-shipment', $this->make_hct_order() ) );

		$response = $service->post_logistics_with_id_create_shipment_callback( $this->make_request( '/create-shipment', $this->make_hct_order() ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'NUMBER_EXHAUSTED', $response->get_data()['error_code'] );
	}

	/**
	 * Happy：REST 建單成功 → data.logistics_id
	 *
	 * @group happy
	 */
	public function test_REST建單成功(): void {
		$this->setup_hct_logistics();
		$this->fake_hct( 'TransData_Json', 'trans_data_ok' );

		$response = LogisticsApiService::instance()->post_logistics_with_id_create_shipment_callback( $this->make_request( '/create-shipment', $this->make_hct_order() ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::$hct_edelno, $response->get_data()['data']['logistics_id'] );
	}

	/**
	 * Happy：儲存設定即同步號段；get_settings 帶回號段狀態（剩餘量）
	 *
	 * @group happy
	 */
	public function test_儲存設定同步號段並回剩餘量(): void {
		$this->setup_hct_logistics(
			[
				'waybill_start' => '100173483',
				'waybill_end'   => '100173582',
			]
		);

		$status = HctLogisticsProvider::get_settings()['waybill_status'];

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 100, $status['remaining'] );
		$this->assertSame( '1001734834', $status['next'] );
	}

	/**
	 * Smoke：get_settings 帶回貨況查詢可用性（給設定頁警示）
	 *
	 * @group smoke
	 */
	public function test_冒煙_設定帶回查貨可用性(): void {
		$this->setup_hct_logistics();

		$settings = HctLogisticsProvider::get_settings();

		$this->assertIsBool( $settings['track_crypto_available'] );
		$this->assertArrayNotHasKey( 'waybill_status', HctLogisticsProvider::get_settings( false ) );
	}
}
