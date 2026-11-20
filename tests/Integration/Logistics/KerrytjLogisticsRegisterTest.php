<?php
/**
 * 嘉里大榮註冊 / meta / REST 測試（P7：kerrytj_logistics）
 *
 * - ProviderRegister：啟用後進容器、運送方式已註冊、/settings 列出、REST 解析清單含 kerrytj_logistics（不在豁免清單）
 * - meta：HOME only；create / query / print / return = true；has_cvs_map / can_cancel = false
 *   （can_cancel=false 依據：skill api-reference §5「沒有取消 / 刪除託運單的 API」，規格書 v1.1.0 與 Help Page 皆無）
 * - REST：print 回 PDF（application/pdf）；cancel / store-selection 回 501；建單號段用罄 409
 * - 嘉里專屬 admin REST：POST logistics/{id}/kerrytj/update（修改託運資料）、POST logistics/{id}/kerrytj/detail（託運明細 PDF）
 * - 設定儲存後同步常溫 / 低溫號段到配號器（key 分環境 × 溫層），get_settings 帶回剩餘量
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter KerrytjLogisticsRegister --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Http\KerrytjAdminApiService;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\KerrytjLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\WC_KerrytjLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsApiService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\KerrytjLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 嘉里大榮註冊
 *
 * @group integration
 * @group logistics
 * @group kerrytj_logistics
 */
final class KerrytjLogisticsRegisterTest extends TestCase {
	use KerrytjLogisticsTestTrait;

	/** 清理 */
	public function tear_down(): void {
		$this->reset_kerrytj_logistics();
		\wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;
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
		$request->set_param( 'provider', KerrytjLogisticsProvider::ID );
		return $request;
	}

	/** @return \WC_Order 已建單訂單 */
	private function make_shipped_order(): \WC_Order {
		$order = $this->make_kerrytj_order();
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_ok' );
		KerrytjLogisticsProvider::instance()->create_shipment( $order );
		return \wc_get_order( $order->get_id() );
	}

	/** @return void 註冊嘉里 admin REST 並以 admin 身分 */
	private function boot_admin_rest(): void {
		// singleton 可能已由前一測試建立（其 rest_api_init hook 已被測試框架還原移除）→ 顯式掛上
		\add_action( 'rest_api_init', [ KerrytjAdminApiService::instance(), 'register_apis' ] );
		$GLOBALS['wp_rest_server'] = null;
		\rest_get_server();
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Smoke：ID / option key
	 *
	 * @group smoke
	 */
	public function test_冒煙_ID與option_key(): void {
		$this->assertSame( 'kerrytj_logistics', KerrytjLogisticsProvider::ID );
		$this->assertSame( 'woocommerce_kerrytj_logistics_settings', ProviderUtils::get_option_name( KerrytjLogisticsProvider::ID ) );
	}

	/**
	 * Smoke：啟用後 register_hooks 放入容器；未啟用不放
	 *
	 * @group smoke
	 */
	public function test_冒煙_啟用後進容器(): void {
		ProviderUtils::$container = [];
		ProviderRegister::register_hooks();
		$this->assertArrayNotHasKey( KerrytjLogisticsProvider::ID, ProviderUtils::$container );

		$this->setup_kerrytj_logistics();
		ProviderUtils::$container = [];
		ProviderRegister::register_hooks();

		$this->assertInstanceOf( KerrytjLogisticsProvider::class, ProviderUtils::$container[ KerrytjLogisticsProvider::ID ] ?? null );
	}

	/**
	 * Smoke：WC 運送方式已註冊；/settings 列表含；REST 解析清單含且不在豁免清單
	 *
	 * @group smoke
	 */
	public function test_冒煙_運送方式與設定列表(): void {
		$methods = ProviderRegister::add_shipping_method( [] );
		$this->assertSame( WC_KerrytjLogisticsShipping::class, $methods[ WC_KerrytjLogisticsShipping::METHOD_ID ] ?? null );

		$dtos = ProviderRegister::get_registered_provider_dtos();
		$this->assertArrayHasKey( KerrytjLogisticsProvider::ID, $dtos );
		$this->assertSame( KerrytjLogisticsProvider::class, ProviderRegister::get_provider_class( KerrytjLogisticsProvider::ID ) );

		$reflection = new \ReflectionClass( LogisticsApiService::class );
		$ids        = $reflection->getReflectionConstant( 'PROVIDER_IDS' );
		$exempt     = $reflection->getReflectionConstant( 'CAPABILITY_CHECK_EXEMPT_IDS' );
		$this->assertNotFalse( $ids );
		$this->assertNotFalse( $exempt );
		$this->assertContains( KerrytjLogisticsProvider::ID, $ids->getValue() );
		$this->assertNotContains( KerrytjLogisticsProvider::ID, $exempt->getValue() );
	}

	/**
	 * Smoke：meta 宣告
	 *
	 * @group smoke
	 */
	public function test_冒煙_meta宣告(): void {
		$meta = KerrytjLogisticsProvider::instance()->get_provider_meta()->to_array();

		$this->assertSame( KerrytjLogisticsProvider::ID, $meta['id'] );
		$this->assertSame( [ 'HOME' ], $meta['supported_delivery_methods'] );
		$this->assertSame(
			[
				'can_create_shipment' => true,
				'can_query_shipment'  => true,
				'has_cvs_map'         => false,
				'can_print'           => true,
				'can_return'          => true,
				'can_cancel'          => false,
			],
			$meta['capabilities']
		);
	}

	/**
	 * Smoke：REST print 回 PDF（不回 501）
	 *
	 * @group smoke
	 */
	public function test_冒煙_REST列印回PDF(): void {
		$this->setup_kerrytj_logistics();
		$order = $this->make_shipped_order();
		$this->fake_kerrytj( 'Report/GetLabelPdf', 'pdf' );

		$response = LogisticsApiService::instance()->post_logistics_with_id_print_callback( $this->make_request( '/print', $order ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', (string) $response->get_data() );
		$this->assertSame( 'application/pdf', $response->get_headers()['Content-Type'] ?? '' );
		$this->assertStringContainsString( self::$kerrytj_ambient_first, $response->get_headers()['Content-Disposition'] ?? '' );
	}

	/**
	 * Error：REST 取消 / 選店對 kerrytj 回 501 UNSUPPORTED，不打 API
	 *
	 * @group error
	 */
	public function test_REST取消與選店回501(): void {
		$this->setup_kerrytj_logistics();
		$order = $this->make_kerrytj_order();
		$this->block_kerrytj_http();
		$service = LogisticsApiService::instance();

		foreach ( [ $service->post_logistics_with_id_cancel_callback( $this->make_request( '/cancel', $order ) ), $service->post_logistics_with_id_store_selection_callback( $this->make_request( '/store-selection', $order ) ) ] as $response ) {
			$this->assertSame( 501, $response->get_status() );
			$this->assertSame( 'UNSUPPORTED', $response->get_data()['error_code'] );
		}
		$this->assertSame( [], $this->kerrytj_requests );
	}

	/**
	 * Error：REST 建單號段用罄 → 409 NUMBER_EXHAUSTED
	 *
	 * @group error
	 */
	public function test_REST號段用罄回409(): void {
		$this->setup_kerrytj_logistics(
			[
				'ambient_waybill_start' => '40350718600',
				'ambient_waybill_end'   => '40350718600',
			]
		);
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_ok' );
		$service = LogisticsApiService::instance();
		$service->post_logistics_with_id_create_shipment_callback( $this->make_request( '/create-shipment', $this->make_kerrytj_order() ) );

		$response = $service->post_logistics_with_id_create_shipment_callback( $this->make_request( '/create-shipment', $this->make_kerrytj_order() ) );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'NUMBER_EXHAUSTED', $response->get_data()['error_code'] );
	}

	/**
	 * Happy：嘉里 admin REST 修改託運資料
	 *
	 * @group happy
	 */
	public function test_admin_REST修改託運資料(): void {
		$this->setup_kerrytj_logistics();
		$order = $this->make_shipped_order();
		$this->fake_kerrytj( 'UpdateShipmentDelivery', 'update_ok' );
		$this->boot_admin_rest();

		$response = \rest_do_request( new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/kerrytj/update" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['data']['updated'] );
	}

	/**
	 * Happy：嘉里 admin REST 託運明細 PDF
	 *
	 * @group happy
	 */
	public function test_admin_REST託運明細(): void {
		$this->setup_kerrytj_logistics();
		$order = $this->make_shipped_order();
		$this->fake_kerrytj( 'Report/GetDetailPdf', 'pdf' );
		$this->boot_admin_rest();

		$response = \rest_do_request( new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/kerrytj/detail" ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF-', (string) $response->get_data() );
		$this->assertSame( 'application/pdf', $response->get_headers()['Content-Type'] ?? '' );
	}

	/**
	 * Error：admin REST 尚未建單 → 409；訂單不存在 → 404
	 *
	 * @group error
	 */
	public function test_admin_REST錯誤映射(): void {
		$this->setup_kerrytj_logistics();
		$order = $this->make_kerrytj_order();
		$this->block_kerrytj_http();
		$this->boot_admin_rest();

		$conflict = \rest_do_request( new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/kerrytj/update" ) );
		$missing  = \rest_do_request( new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/999999/kerrytj/detail' ) );

		$this->assertSame( 409, $conflict->get_status() );
		$this->assertSame( 'CONFLICT', $conflict->get_data()['error_code'] );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( [], $this->kerrytj_requests );
	}

	/**
	 * Security：一般會員不可呼叫嘉里 admin REST
	 *
	 * @group security
	 */
	public function test_admin_REST需管理權限(): void {
		$this->setup_kerrytj_logistics();
		$order = $this->make_kerrytj_order();
		$this->block_kerrytj_http();
		// singleton 可能已由前一測試建立（其 rest_api_init hook 已被測試框架還原移除）→ 顯式掛上
		\add_action( 'rest_api_init', [ KerrytjAdminApiService::instance(), 'register_apis' ] );
		$GLOBALS['wp_rest_server'] = null;
		\rest_get_server();
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );

		$response = \rest_do_request( new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$order->get_id()}/kerrytj/update" ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->kerrytj_requests );
	}

	/**
	 * Happy：儲存設定即同步號段（key 分環境 × 溫層）；get_settings 帶回剩餘量
	 *
	 * @group happy
	 */
	public function test_儲存設定同步號段並回剩餘量(): void {
		$this->setup_kerrytj_logistics();

		$settings = KerrytjLogisticsProvider::get_settings();

		$this->assertTrue( $settings['ambient_waybill_status']['configured'] );
		$this->assertSame( 100, $settings['ambient_waybill_status']['remaining'] );
		$this->assertSame( self::$kerrytj_ambient_first, $settings['ambient_waybill_status']['next'] );
		$this->assertSame( self::$kerrytj_cold_first, $settings['cold_waybill_status']['next'] );
		$this->assertArrayNotHasKey( 'ambient_waybill_status', KerrytjLogisticsProvider::get_settings( false ) );

		ProviderUtils::update_option( KerrytjLogisticsProvider::ID, [ 'ambient_waybill_end' => '40350718609' ] );
		$this->assertSame( 10, KerrytjLogisticsProvider::get_settings()['ambient_waybill_status']['remaining'] );
	}
}
