<?php
/**
 * 黑貓宅急便註冊 / meta / REST 測試（P4）
 *
 * - ProviderRegister：啟用後進容器、運送方式已註冊、/settings 列出 tcat_logistics
 * - meta：HOME only；create/query/print/return=true、has_cvs_map/cancel=false
 * - REST：print 對 tcat 不回 501，回 application/pdf；cancel / store-selection 回 501（新 provider 受 capability 檢查）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter TcatLogisticsRegister --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsApiService;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\TcatLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\WC_TcatLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatLogisticsMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\TcatLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 黑貓宅急便註冊
 *
 * @group integration
 * @group logistics
 * @group tcat_logistics
 */
final class TcatLogisticsRegisterTest extends TestCase {
	use TcatLogisticsTestTrait;

	/** 清理 */
	public function tear_down(): void {
		$this->reset_tcat_logistics();
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
		$request->set_param( 'provider', TcatLogisticsProvider::ID );
		return $request;
	}

	/**
	 * Smoke：ID / option key
	 *
	 * @group smoke
	 */
	public function test_冒煙_ID與option_key(): void {
		$this->assertSame( 'tcat_logistics', TcatLogisticsProvider::ID );
		$this->assertSame( 'woocommerce_tcat_logistics_settings', ProviderUtils::get_option_name( TcatLogisticsProvider::ID ) );
	}

	/**
	 * Smoke：啟用後 register_hooks 放入容器；未啟用不放
	 *
	 * @group smoke
	 */
	public function test_冒煙_啟用後進容器(): void {
		ProviderUtils::$container = [];
		ProviderRegister::register_hooks();
		$this->assertArrayNotHasKey( TcatLogisticsProvider::ID, ProviderUtils::$container );

		$this->setup_tcat_logistics();
		ProviderUtils::$container = [];
		ProviderRegister::register_hooks();

		$this->assertArrayHasKey( TcatLogisticsProvider::ID, ProviderUtils::$container );
		$this->assertInstanceOf( TcatLogisticsProvider::class, ProviderUtils::$container[ TcatLogisticsProvider::ID ] );
	}

	/**
	 * Smoke：WC 運送方式已註冊；/settings 列表含 tcat_logistics；REST 解析清單含 tcat_logistics
	 *
	 * @group smoke
	 */
	public function test_冒煙_運送方式與設定列表(): void {
		$methods = ProviderRegister::add_shipping_method( [] );
		$this->assertSame( WC_TcatLogisticsShipping::class, $methods[ WC_TcatLogisticsShipping::METHOD_ID ] ?? null );

		$dtos = ProviderRegister::get_registered_provider_dtos();
		$this->assertArrayHasKey( TcatLogisticsProvider::ID, $dtos );
		$this->assertSame( TcatLogisticsProvider::ID, $dtos[ TcatLogisticsProvider::ID ]->id );

		$constant = ( new \ReflectionClass( LogisticsApiService::class ) )->getReflectionConstant( 'PROVIDER_IDS' );
		$this->assertNotFalse( $constant );
		$this->assertContains( TcatLogisticsProvider::ID, $constant->getValue() );
	}

	/**
	 * Smoke：meta 宣告
	 *
	 * @group smoke
	 */
	public function test_冒煙_meta宣告(): void {
		$meta = TcatLogisticsProvider::instance()->get_provider_meta()->to_array();

		$this->assertSame( TcatLogisticsProvider::ID, $meta['id'] );
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
	 * Smoke：REST print 對 tcat 不回 501，回 PDF（application/pdf）
	 *
	 * @group smoke
	 */
	public function test_冒煙_REST列印回PDF不回501(): void {
		$this->setup_tcat_logistics();
		$order = $this->make_tcat_order();
		( new TcatLogisticsMetaKeys( $order ) )->save_shipment( self::$tcat_obt, self::$tcat_file_no, \time() );
		$this->fake_tcat(
			'DownloadOBT',
			[
				'body'         => $this->tcat_pdf(),
				'content_type' => 'application/pdf',
			]
		);

		$response = LogisticsApiService::instance()->post_logistics_with_id_print_callback( $this->make_request( '/print', $order ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertStringStartsWith( '%PDF', (string) $response->get_data() );
		$headers = $response->get_headers();
		$this->assertSame( 'application/pdf', $headers['Content-Type'] ?? '' );
		$this->assertStringContainsString( self::$tcat_obt, $headers['Content-Disposition'] ?? '' );
	}

	/**
	 * Error：REST 取消 / 選店對 tcat 回 501 UNSUPPORTED（新 provider 受 capability 檢查）
	 *
	 * @group error
	 */
	public function test_REST取消與選店回501(): void {
		$this->setup_tcat_logistics();
		$order = $this->make_tcat_order();
		$this->block_tcat_http();
		$service = LogisticsApiService::instance();

		$cancel = $service->post_logistics_with_id_cancel_callback( $this->make_request( '/cancel', $order ) );
		$select = $service->post_logistics_with_id_store_selection_callback( $this->make_request( '/store-selection', $order ) );

		$this->assertSame( 501, $cancel->get_status() );
		$this->assertSame( 'UNSUPPORTED', $cancel->get_data()['error_code'] );
		$this->assertSame( 501, $select->get_status() );
		$this->assertSame( [], $this->tcat_requests );
	}

	/**
	 * Error：REST 建單失敗（provider 帶正規化錯誤碼）→ HTTP 依 ErrorCode 映射並回 error_code
	 *
	 * @group error
	 */
	public function test_REST建單失敗回正規化錯誤碼(): void {
		$this->setup_tcat_logistics();
		$order = $this->make_tcat_order( [ 'phone' => '' ] );
		$this->block_tcat_http();
		$this->fake_tcat( 'ParsingAddress', 'parsing_address_ok' );

		$response = LogisticsApiService::instance()->post_logistics_with_id_create_shipment_callback( $this->make_request( '/create-shipment', $order ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'VALIDATION', $response->get_data()['error_code'] );
	}

	/**
	 * Happy：REST 建單成功 → data.logistics_id
	 *
	 * @group happy
	 */
	public function test_REST建單成功(): void {
		$this->setup_tcat_logistics();
		$order = $this->make_tcat_order();
		$this->fake_tcat( 'ParsingAddress', 'parsing_address_ok' );
		$this->fake_tcat( 'PrintOBT', 'print_obt_ok' );

		$response = LogisticsApiService::instance()->post_logistics_with_id_create_shipment_callback( $this->make_request( '/create-shipment', $order ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::$tcat_obt, $response->get_data()['data']['logistics_id'] );
	}
}
