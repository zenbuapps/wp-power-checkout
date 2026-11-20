<?php
/**
 * LogisticsApiService capability 檢查與 providers/meta 端點測試（P0 統一介面）
 *
 *  - provider meta 宣告不支援的操作 → HTTP 501 + error_code UNSUPPORTED，且不呼叫 provider 方法
 *  - 既有 3 家（ecpay / payuni / paynow）不受 capability 檢查影響，既有回應碼不變（回歸）
 *  - GET power-checkout/v1/logistics/providers/meta：admin 可讀已啟用 provider 的 meta，未登入 401/403
 *
 * 測試替身 CapabilityTestLogisticsProvider 經 filter `power_checkout_logistics_provider_ids` 掛入解析清單。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter LogisticsApiServiceCapability --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\LogisticsProviderMetaDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\ShipmentCapabilitiesDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\DeliveryMethod;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsApiService;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * LogisticsApiService capability 測試
 *
 * @group integration
 * @group logistics
 */
final class LogisticsApiServiceCapabilityTest extends TestCase {

	/** 每個測試前掛入測試替身 */
	public function set_up(): void {
		parent::set_up();
		CapabilityTestLogisticsProvider::$calls = [];
		\add_filter( 'power_checkout_logistics_provider_ids', [ self::class, 'add_double_id' ] );
		$this->enable_provider( CapabilityTestLogisticsProvider::ID );
		ProviderUtils::$container[ CapabilityTestLogisticsProvider::ID ] = new CapabilityTestLogisticsProvider();
	}

	/** 每個測試後清理 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( CapabilityTestLogisticsProvider::ID ) );
		\delete_option( ProviderUtils::get_option_name( PaynowLogisticsProvider::ID ) );
		\wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Filter callback：把替身 id 加入解析清單
	 *
	 * @param array<int, string> $ids provider ids
	 * @return array<int, string>
	 */
	public static function add_double_id( array $ids ): array {
		$ids[] = CapabilityTestLogisticsProvider::ID;
		return $ids;
	}

	/**
	 * 建立訂單與指定 provider 的請求
	 *
	 * @param string $method   HTTP method
	 * @param string $suffix   路徑尾端（如 '/print'）
	 * @param string $provider provider id
	 * @return \WP_REST_Request
	 */
	private function make_request( string $method, string $suffix, string $provider = CapabilityTestLogisticsProvider::ID ): \WP_REST_Request {
		$order   = $this->create_wc_order(
			[
				'status' => 'processing',
				'total'  => 500,
			]
		);
		$request = new \WP_REST_Request( $method, "/power-checkout/v1/logistics/{$order->get_id()}{$suffix}" );
		$request->set_param( 'id', (string) $order->get_id() );
		$request->set_param( 'provider', $provider );
		return $request;
	}

	/**
	 * Error：宣告 can_print=false → print 回 501 + UNSUPPORTED，不呼叫 print_document
	 *
	 * @group error
	 */
	public function test_宣告不支援列印回501_UNSUPPORTED(): void {
		$response = LogisticsApiService::instance()->post_logistics_with_id_print_callback( $this->make_request( 'POST', '/print' ) );

		$this->assertSame( 501, $response->get_status() );
		$this->assertSame( 'UNSUPPORTED', $response->get_data()['error_code'] );
		$this->assertSame( [], CapabilityTestLogisticsProvider::$calls );
	}

	/**
	 * Error：宣告 can_cancel=false → cancel 回 501
	 *
	 * @group error
	 */
	public function test_宣告不支援取消回501(): void {
		$response = LogisticsApiService::instance()->post_logistics_with_id_cancel_callback( $this->make_request( 'POST', '/cancel' ) );

		$this->assertSame( 501, $response->get_status() );
		$this->assertSame( 'UNSUPPORTED', $response->get_data()['error_code'] );
		$this->assertSame( [], CapabilityTestLogisticsProvider::$calls );
	}

	/**
	 * Error：宣告 can_return=false / has_cvs_map=false → 501
	 *
	 * @group error
	 */
	public function test_宣告不支援退貨與選店回501(): void {
		$service = LogisticsApiService::instance();

		$this->assertSame( 501, $service->post_logistics_with_id_return_callback( $this->make_request( 'POST', '/return' ) )->get_status() );
		$this->assertSame( 501, $service->post_logistics_with_id_store_selection_callback( $this->make_request( 'POST', '/store-selection' ) )->get_status() );
		$this->assertSame( [], CapabilityTestLogisticsProvider::$calls );
	}

	/**
	 * Happy：宣告支援的操作照常委派 provider（建單 / 查詢）
	 *
	 * @group happy
	 */
	public function test_宣告支援的操作照常委派(): void {
		$service = LogisticsApiService::instance();

		$create = $service->post_logistics_with_id_create_shipment_callback( $this->make_request( 'POST', '/create-shipment' ) );
		$query  = $service->get_logistics_with_id_callback( $this->make_request( 'GET', '' ) );

		$this->assertSame( 200, $create->get_status() );
		$this->assertSame( [ 'logistics_id' => 'DOUBLE-1' ], $create->get_data()['data'] );
		$this->assertSame( 200, $query->get_status() );
		$this->assertSame( [ 'create_shipment', 'query_shipment' ], CapabilityTestLogisticsProvider::$calls );
	}

	/**
	 * Happy（回歸）：既有 PayNow 宣告 can_return=false，但既有回應碼不變（仍 400，不改 501）
	 *
	 * @group happy
	 */
	public function test_既有provider不受capability檢查影響回應碼不變(): void {
		$this->enable_provider( PaynowLogisticsProvider::ID );
		ProviderUtils::$container[ PaynowLogisticsProvider::ID ] = PaynowLogisticsProvider::instance();
		$this->assertFalse( PaynowLogisticsProvider::instance()->get_provider_meta()->capabilities->can_return );

		$response = LogisticsApiService::instance()->post_logistics_with_id_return_callback(
			$this->make_request( 'POST', '/return', PaynowLogisticsProvider::ID )
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'error', $response->get_data()['code'] );
	}

	/**
	 * 冒煙：providers/meta 路由已註冊，且不與 logistics/{id} 衝突
	 *
	 * @group smoke
	 */
	public function test_冒煙_providers_meta路由已註冊(): void {
		$routes = \rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/power-checkout/v1/logistics/providers/meta', $routes );
		$this->assertArrayHasKey( '/power-checkout/v1/logistics/(?P<id>\d+)', $routes );
	}

	/**
	 * Happy：admin 可讀已啟用 provider 的 meta
	 *
	 * @group happy
	 */
	public function test_admin可讀providers_meta(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$response = \rest_do_request( new \WP_REST_Request( 'GET', '/power-checkout/v1/logistics/providers/meta' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['code'] );
		$this->assertArrayHasKey( CapabilityTestLogisticsProvider::ID, $data['data'] );
		$this->assertFalse( $data['data'][ CapabilityTestLogisticsProvider::ID ]['capabilities']['can_print'] );
		$this->assertSame( [ 'HOME' ], $data['data'][ CapabilityTestLogisticsProvider::ID ]['supported_delivery_methods'] );
	}

	/**
	 * Security：未登入不可讀 providers/meta
	 *
	 * @group security
	 */
	public function test_未登入讀providers_meta被拒(): void {
		\wp_set_current_user( 0 );

		$response = \rest_do_request( new \WP_REST_Request( 'GET', '/power-checkout/v1/logistics/providers/meta' ) );

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
	}

	/**
	 * Security：一般會員（customer）不可讀 providers/meta
	 *
	 * @group security
	 */
	public function test_一般會員讀providers_meta被拒(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );

		$response = \rest_do_request( new \WP_REST_Request( 'GET', '/power-checkout/v1/logistics/providers/meta' ) );

		$this->assertSame( 403, $response->get_status() );
	}
}

/**
 * 測試替身：只支援建單 / 查詢的物流 provider
 */
final class CapabilityTestLogisticsProvider extends BaseService implements ILogisticsProvider {

	/** @var string provider id */
	public const ID = 'pc_test_capability_logistics';

	/** @var array<int, string> 被呼叫的方法紀錄 */
	public static array $calls = [];

	/** @var string $id Id */
	public string $id = self::ID;

	/**
	 * 設定
	 *
	 * @param bool $with_default 是否帶預設值
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		return [ 'enabled' => 'yes' ];
	}

	/**
	 * Meta：只支援建單 / 查詢
	 *
	 * @return LogisticsProviderMetaDTO
	 */
	public function get_provider_meta(): LogisticsProviderMetaDTO {
		return new LogisticsProviderMetaDTO(
			id: self::ID,
			supported_delivery_methods: [ DeliveryMethod::HOME ],
			capabilities: new ShipmentCapabilitiesDTO(
				can_create_shipment: true,
				can_query_shipment: true,
				has_cvs_map: false,
				can_print: false,
				can_return: false,
				can_cancel: false,
			),
		);
	}

	/**
	 * 選店
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 * @return array<string, mixed>
	 */
	public function get_store_selection( \WC_Order $order, array $ctx = [] ): array {
		self::$calls[] = 'get_store_selection';
		return [];
	}

	/**
	 * 解析選店
	 *
	 * @param array<string, mixed> $raw 原始資料
	 * @return array<string, mixed>
	 */
	public function parse_store_selection( array $raw ): array {
		self::$calls[] = 'parse_store_selection';
		return [];
	}

	/**
	 * 建單
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 */
	public function create_shipment( \WC_Order $order ): array {
		self::$calls[] = 'create_shipment';
		return [ 'logistics_id' => 'DOUBLE-1' ];
	}

	/**
	 * 查詢
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 */
	public function query_shipment( \WC_Order $order ): array {
		self::$calls[] = 'query_shipment';
		return [ 'logistics_id' => 'DOUBLE-1' ];
	}

	/**
	 * 列印
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	public function print_document( \WC_Order $order ): string {
		self::$calls[] = 'print_document';
		return '<html></html>';
	}

	/**
	 * 取消
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 */
	public function cancel_shipment( \WC_Order $order ): array {
		self::$calls[] = 'cancel_shipment';
		return [];
	}

	/**
	 * 退貨
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 * @return array<string, mixed>
	 */
	public function create_return( \WC_Order $order, array $ctx = [] ): array {
		self::$calls[] = 'create_return';
		return [];
	}

	/**
	 * 貨態 callback
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( 'OK', 200 );
	}

	/**
	 * 支援的子類型
	 *
	 * @return array<int, string>
	 */
	public function get_supported_methods(): array {
		return [ 'HOME' ];
	}
}
