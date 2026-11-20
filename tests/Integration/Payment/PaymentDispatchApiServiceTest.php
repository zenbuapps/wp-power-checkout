<?php
/**
 * PaymentDispatchApiService 嵌入式金流統一 dispatch REST 測試（P0 統一介面）
 *
 * 端點：POST power-checkout/v1/pay/{order_id}/dispatch（permission __return_true，body order_key 以 hash_equals 驗）
 *   - 訂單不存在 → 404
 *   - order_key 錯 / 缺 → 403
 *   - 訂單 gateway 非 IEmbeddedPaymentProvider → 400 + UNSUPPORTED
 *   - !needs_payment() → 409
 *   - 成功 → gateway dispatch() 回傳值原樣包進 data
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter PaymentDispatchApiService --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;
use Tests\Integration\Payment\Doubles\DispatchTestEmbeddedGateway;
use Tests\Integration\Payment\Doubles\DispatchTestPlainGateway;

/**
 * PaymentDispatchApiService 測試
 *
 * @group integration
 * @group payment
 */
final class PaymentDispatchApiServiceTest extends TestCase {

	/** @var string 路由樣式 */
	private const ROUTE_PATTERN = '/power-checkout/v1/pay/(?P<order_id>\d+)/dispatch';

	/** 每個測試前放入測試替身 */
	public function set_up(): void {
		parent::set_up();
		\update_option( 'woocommerce_currency', 'TWD' );
		DispatchTestEmbeddedGateway::$calls        = [];
		DispatchTestEmbeddedGateway::$throw        = null;
		$embedded                                  = new DispatchTestEmbeddedGateway();
		$plain                                     = new DispatchTestPlainGateway();
		ProviderUtils::$container[ $embedded->id ] = $embedded;
		ProviderUtils::$container[ $plain->id ]    = $plain;
	}

	/**
	 * 建立訂單
	 *
	 * @param string $payment_method gateway id
	 * @param string $status         訂單狀態
	 * @return \WC_Order
	 */
	private function make_order( string $payment_method = DispatchTestEmbeddedGateway::ID, string $status = 'pending' ): \WC_Order {
		return $this->create_wc_order(
			[
				'status'         => $status,
				'payment_method' => $payment_method,
				'total'          => 1000,
			]
		);
	}

	/**
	 * 送出 dispatch 請求
	 *
	 * @param int                  $order_id 訂單 ID
	 * @param array<string, mixed> $body     body 參數
	 * @return \WP_REST_Response
	 */
	private function dispatch( int $order_id, array $body ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/pay/{$order_id}/dispatch" );
		$request->set_body_params( $body );
		$response = \rest_do_request( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * 冒煙：路由已註冊
	 *
	 * @group smoke
	 */
	public function test_冒煙_dispatch路由已註冊(): void {
		$routes = \rest_get_server()->get_routes();
		$this->assertArrayHasKey( self::ROUTE_PATTERN, $routes );
	}

	/**
	 * Security：order_key 錯 → 403
	 *
	 * @group security
	 */
	public function test_order_key錯誤回403(): void {
		$order    = $this->make_order();
		$response = $this->dispatch( $order->get_id(), [ 'order_key' => 'wc_order_wrong' ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( 'AUTH', $response->get_data()['error_code'] );
		$this->assertSame( [], DispatchTestEmbeddedGateway::$calls );
	}

	/**
	 * Security：缺 order_key → 403
	 *
	 * @group security
	 */
	public function test_缺order_key回403(): void {
		$order    = $this->make_order();
		$response = $this->dispatch( $order->get_id(), [] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], DispatchTestEmbeddedGateway::$calls );
	}

	/**
	 * Security：訂單不存在 → 404
	 *
	 * @group security
	 */
	public function test_訂單不存在回404(): void {
		$response = $this->dispatch( 999999999, [ 'order_key' => 'wc_order_x' ] );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'NOT_FOUND', $response->get_data()['error_code'] );
	}

	/**
	 * Error：訂單 gateway 不是 IEmbeddedPaymentProvider → 400 UNSUPPORTED
	 *
	 * @group error
	 */
	public function test_非嵌入式gateway回400_UNSUPPORTED(): void {
		$order    = $this->make_order( DispatchTestPlainGateway::ID );
		$response = $this->dispatch( $order->get_id(), [ 'order_key' => $order->get_order_key() ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'UNSUPPORTED', $response->get_data()['error_code'] );
	}

	/**
	 * Error：訂單 gateway 不存在（未註冊）→ 400 UNSUPPORTED
	 *
	 * @group error
	 */
	public function test_訂單gateway不存在回400_UNSUPPORTED(): void {
		$order    = $this->make_order( 'pc_test_not_registered_gateway' );
		$response = $this->dispatch( $order->get_id(), [ 'order_key' => $order->get_order_key() ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'UNSUPPORTED', $response->get_data()['error_code'] );
	}

	/**
	 * Edge：訂單已付款（!needs_payment）→ 409
	 *
	 * @group edge
	 */
	public function test_訂單已付款回409(): void {
		$order    = $this->make_order( DispatchTestEmbeddedGateway::ID, 'processing' );
		$response = $this->dispatch( $order->get_id(), [ 'order_key' => $order->get_order_key() ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'CONFLICT', $response->get_data()['error_code'] );
		$this->assertSame( [], DispatchTestEmbeddedGateway::$calls );
	}

	/**
	 * Happy：gateway dispatch 回傳值原樣包進 data；payload 不含 order_key
	 *
	 * @group happy
	 */
	public function test_dispatch回傳值原樣包進data(): void {
		$order    = $this->make_order();
		$response = $this->dispatch(
			$order->get_id(),
			[
				'order_key'   => $order->get_order_key(),
				'pay_session' => 'ps_123',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$body = $response->get_data();
		$this->assertSame( 'success', $body['code'] );
		$this->assertSame(
			[
				'mode'     => 'client-sdk',
				'provider' => DispatchTestEmbeddedGateway::ID,
				'phase'    => 'confirm',
			],
			$body['data']
		);
		$this->assertCount( 1, DispatchTestEmbeddedGateway::$calls );
		$this->assertSame( $order->get_id(), DispatchTestEmbeddedGateway::$calls[0]['order_id'] );
		$this->assertSame( 'ps_123', DispatchTestEmbeddedGateway::$calls[0]['payload']['pay_session'] );
		$this->assertArrayNotHasKey( 'order_key', DispatchTestEmbeddedGateway::$calls[0]['payload'] );
	}

	/**
	 * Error：gateway dispatch 拋例外 → 500 通用訊息，不外洩內部訊息
	 *
	 * @group error
	 */
	public function test_dispatch拋例外回500通用訊息(): void {
		$order                              = $this->make_order();
		DispatchTestEmbeddedGateway::$throw = new \RuntimeException( 'internal secret detail' );

		$response = $this->dispatch( $order->get_id(), [ 'order_key' => $order->get_order_key() ] );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'UNKNOWN', $response->get_data()['error_code'] );
		$this->assertStringNotContainsString( 'internal secret detail', (string) \wp_json_encode( $response->get_data() ) );
	}
}
