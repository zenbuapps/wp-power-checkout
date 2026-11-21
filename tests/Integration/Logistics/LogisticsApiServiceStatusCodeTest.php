<?php
/**
 * 物流 REST 操作（建單 / 查詢 / 列印 / 退貨…）provider 端失敗不可回 502（P8 fix #4）
 *
 * 5fd963c 只把「測試連線」ping 的 provider 失敗改為 424：站點經 Cloudflare 等 CDN 時，
 * origin 的 502 本文會被改寫成 CDN 錯誤頁，後台只看到「發生未預期的錯誤」。
 * LogisticsApiService 其他操作以 ErrorCode::to_http_status() 映射，NETWORK / PROVIDER 仍回 502。
 *
 * 契約：原本回 502 的情境一律改 424 Failed Dependency；本文 code / error_code / raw_code / message 格式不變；
 * 其他狀態碼（409 / 422 / 501 …）不受影響。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter LogisticsApiServiceStatusCode --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\KerrytjLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsApiService;
use Tests\Integration\Logistics\Doubles\KerrytjLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 物流 REST 操作失敗狀態碼
 *
 * @group integration
 * @group logistics
 */
final class LogisticsApiServiceStatusCodeTest extends TestCase {
	use KerrytjLogisticsTestTrait;

	/** 每個測試前：啟用嘉里大榮 */
	protected function configure_dependencies(): void {
		$this->setup_kerrytj_logistics();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_kerrytj_logistics();
		parent::tear_down();
	}

	/**
	 * 建立 REST 請求
	 *
	 * @param string    $suffix 路徑尾端
	 * @param \WC_Order $order  訂單
	 * @param string    $method HTTP 方法
	 * @return \WP_REST_Request
	 */
	private function make_request( string $suffix, \WC_Order $order, string $method = 'POST' ): \WP_REST_Request {
		$request = new \WP_REST_Request( $method, "/power-checkout/v1/logistics/{$order->get_id()}{$suffix}" );
		$request->set_param( 'id', (string) $order->get_id() );
		$request->set_param( 'provider', KerrytjLogisticsProvider::ID );
		return $request;
	}

	/** @return \WC_Order 已建單訂單（之後的 API 一律被擋 → 連線失敗） */
	private function make_shipped_order(): \WC_Order {
		$order = $this->make_kerrytj_order();
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_ok' );
		KerrytjLogisticsProvider::instance()->create_shipment( $order );
		$this->kerrytj_queues = [];
		$fresh                = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		return $fresh;
	}

	/**
	 * 斷言 provider 失敗回 424 且本文格式不變
	 *
	 * @param \WP_REST_Response $response 回應
	 * @param string            $label    操作名稱
	 * @return void
	 */
	private function assert_provider_failure( \WP_REST_Response $response, string $label ): void {
		$this->assertSame( 424, $response->get_status(), "{$label}：provider 失敗應回 424（不可 502）" );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'error', $data['code'] );
		$this->assertContains( $data['error_code'], [ 'NETWORK', 'PROVIDER' ], $label );
		$this->assertArrayHasKey( 'raw_code', $data );
		$this->assertNotSame( '', (string) $data['message'] );
		$this->assertNull( $data['data'] );
	}

	/**
	 * Error：建單連線失敗（NETWORK）→ 424
	 *
	 * @group error
	 */
	public function test_建單連線失敗回424(): void {
		$order = $this->make_kerrytj_order();
		$this->block_kerrytj_http();

		$response = LogisticsApiService::instance()->post_logistics_with_id_create_shipment_callback( $this->make_request( '/create-shipment', $order ) );

		$this->assert_provider_failure( $response, '建單' );
	}

	/**
	 * Error：查詢 / 列印 / 退貨 provider 失敗 → 424
	 *
	 * @group error
	 */
	public function test_查詢列印退貨失敗回424(): void {
		$service = LogisticsApiService::instance();

		$order = $this->make_shipped_order();
		$this->assert_provider_failure( $service->get_logistics_with_id_callback( $this->make_request( '', $order, 'GET' ) ), '查詢' );

		$order = $this->make_shipped_order();
		$this->assert_provider_failure( $service->post_logistics_with_id_print_callback( $this->make_request( '/print', $order ) ), '列印' );

		$order = $this->make_shipped_order();
		$this->assert_provider_failure( $service->post_logistics_with_id_return_callback( $this->make_request( '/return', $order ) ), '退貨' );
	}

	/**
	 * Edge：provider 失敗以外的狀態碼不受影響（501 UNSUPPORTED 維持）
	 *
	 * @group edge
	 */
	public function test_不支援的操作仍回501(): void {
		$order = $this->make_kerrytj_order();
		$this->block_kerrytj_http();

		$response = LogisticsApiService::instance()->post_logistics_with_id_cancel_callback( $this->make_request( '/cancel', $order ) );

		$this->assertSame( 501, $response->get_status() );
	}
}
