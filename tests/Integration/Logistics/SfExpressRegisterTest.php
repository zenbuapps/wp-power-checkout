<?php
/**
 * 順豐註冊 / meta / REST 測試（P6：sf_express）
 *
 * - ProviderRegister：啟用後進容器、運送方式已註冊、/settings 列出 sf_express、REST 解析清單含 sf_express（不在豁免清單）
 * - meta：HOME only；create / query / print / cancel = true、return / has_cvs_map = false
 * - REST：print 回 PDF（不回 501）；return / store-selection 回 501 UNSUPPORTED；建單失敗回正規化錯誤碼
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter SfExpressRegister --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsApiService;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\SfExpressProvider;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\WC_SfExpressShipping;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfExpressMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\SfExpressTestTrait;
use Tests\Integration\TestCase;

/**
 * 順豐註冊
 *
 * @group integration
 * @group logistics
 * @group sf_express
 */
final class SfExpressRegisterTest extends TestCase {
	use SfExpressTestTrait;

	/** 清理 */
	public function tear_down(): void {
		$this->reset_sf_express();
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
		$request->set_param( 'provider', SfExpressProvider::ID );
		return $request;
	}

	/**
	 * Smoke：ID / option key
	 *
	 * @group smoke
	 */
	public function test_冒煙_ID與option_key(): void {
		$this->assertSame( 'sf_express', SfExpressProvider::ID );
		$this->assertSame( 'woocommerce_sf_express_settings', ProviderUtils::get_option_name( SfExpressProvider::ID ) );
	}

	/**
	 * Smoke：啟用後 register_hooks 放入容器；未啟用不放
	 *
	 * @group smoke
	 */
	public function test_冒煙_啟用後進容器(): void {
		ProviderUtils::$container = [];
		ProviderRegister::register_hooks();
		$this->assertArrayNotHasKey( SfExpressProvider::ID, ProviderUtils::$container );

		$this->setup_sf_express();
		ProviderUtils::$container = [];
		ProviderRegister::register_hooks();

		$this->assertInstanceOf( SfExpressProvider::class, ProviderUtils::$container[ SfExpressProvider::ID ] ?? null );
	}

	/**
	 * Smoke：WC 運送方式已註冊；/settings 列表含 sf_express；REST 解析清單含 sf_express 且不在豁免清單
	 *
	 * @group smoke
	 */
	public function test_冒煙_運送方式與設定列表(): void {
		$methods = ProviderRegister::add_shipping_method( [] );
		$this->assertSame( WC_SfExpressShipping::class, $methods[ WC_SfExpressShipping::METHOD_ID ] ?? null );

		$dtos = ProviderRegister::get_registered_provider_dtos();
		$this->assertArrayHasKey( SfExpressProvider::ID, $dtos );
		$this->assertSame( SfExpressProvider::class, ProviderRegister::get_provider_class( SfExpressProvider::ID ) );

		$reflection = new \ReflectionClass( LogisticsApiService::class );
		$ids        = $reflection->getReflectionConstant( 'PROVIDER_IDS' );
		$exempt     = $reflection->getReflectionConstant( 'CAPABILITY_CHECK_EXEMPT_IDS' );
		$this->assertNotFalse( $ids );
		$this->assertNotFalse( $exempt );
		$this->assertContains( SfExpressProvider::ID, $ids->getValue() );
		$this->assertNotContains( SfExpressProvider::ID, $exempt->getValue() );
	}

	/**
	 * Smoke：meta 宣告
	 *
	 * @group smoke
	 */
	public function test_冒煙_meta宣告(): void {
		$meta = SfExpressProvider::instance()->get_provider_meta()->to_array();

		$this->assertSame( SfExpressProvider::ID, $meta['id'] );
		$this->assertSame( [ 'HOME' ], $meta['supported_delivery_methods'] );
		$this->assertSame(
			[
				'can_create_shipment' => true,
				'can_query_shipment'  => true,
				'has_cvs_map'         => false,
				'can_print'           => true,
				'can_return'          => false,
				'can_cancel'          => true,
			],
			$meta['capabilities']
		);
		$this->assertSame( [ 'HOME' ], SfExpressProvider::instance()->get_supported_methods() );
	}

	/**
	 * Smoke：get_settings 帶 id / 標題 / mode=test，且不回傳 token 等執行期資料
	 *
	 * @group smoke
	 */
	public function test_冒煙_get_settings(): void {
		$settings = SfExpressProvider::get_settings();

		$this->assertSame( SfExpressProvider::ID, $settings['id'] );
		$this->assertSame( 'test', $settings['mode'] );
		$this->assertArrayHasKey( 'method_title', $settings );
		$this->assertArrayHasKey( 'track_push_url', $settings );
		$this->assertStringContainsString( 'power-checkout/sf-express/track-push', (string) $settings['track_push_url'] );
	}

	/**
	 * Happy：REST print 對 sf_express 回 PDF（capability=true，不回 501）
	 *
	 * @group happy
	 */
	public function test_REST列印回PDF(): void {
		$this->setup_sf_express();
		LogisticsApiService::instance();
		$order = $this->make_sf_order();
		$order->update_meta_data( SfExpressMetaKeys::REF_KEY, self::$sf_waybill );
		$order->save();
		$this->fake_sf( 'IUOP_PRINT_ORDER', 'print_ok' );
		$this->fake_sf(
			'download',
			[
				'status' => 200,
				'body'   => "%PDF-1.4\nfake",
			]
		);

		$response = LogisticsApiService::instance()->post_logistics_with_id_print_callback( $this->make_request( '/print', $order ) );

		$this->assertNotSame( 501, $response->get_status() );
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Error：REST 退貨 / 選店對 sf_express 回 501 UNSUPPORTED（capability=false，不呼叫 provider）
	 *
	 * @group error
	 */
	public function test_REST不支援操作回501(): void {
		$this->setup_sf_express();
		$order = $this->make_sf_order();
		$this->block_sf_http();

		$return    = LogisticsApiService::instance()->post_logistics_with_id_return_callback( $this->make_request( '/return', $order ) );
		$selection = LogisticsApiService::instance()->post_logistics_with_id_store_selection_callback( $this->make_request( '/store-selection', $order ) );

		$this->assertSame( 501, $return->get_status() );
		$this->assertSame( 'UNSUPPORTED', $return->get_data()['error_code'] ?? null );
		$this->assertSame( 501, $selection->get_status() );
		$this->assertSame( [], $this->sf_requests );
	}

	/**
	 * Error：REST 建單本地驗證失敗 → 422 VALIDATION（正規化錯誤碼）
	 *
	 * @group error
	 */
	public function test_REST建單驗證失敗回422(): void {
		$this->setup_sf_express();
		$order = $this->make_sf_order( [ 'phone' => '' ] );
		$this->block_sf_http();

		$response = LogisticsApiService::instance()->post_logistics_with_id_create_shipment_callback( $this->make_request( '/create-shipment', $order ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'VALIDATION', $response->get_data()['error_code'] ?? null );
	}
}
