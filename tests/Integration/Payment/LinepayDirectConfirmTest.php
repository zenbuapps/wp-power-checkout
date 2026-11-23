<?php
/**
 * LINE Pay 直連 confirmUrl / cancelUrl 測試（P3）
 *
 * LINE Pay 沒有 webhook：顧客認證後瀏覽器被導回 confirmUrl（GET，帶 transactionId / orderId），
 * 由我方呼叫 confirm 才真正扣款。走統一外框 handle_webhook()，ack 覆寫為 302。
 *  - verify：order_key hash_equals + transactionId === meta + orderId === meta（偽造 / 換單一律不 confirm）
 *  - confirm 0000 → payment_complete（金額 / orderId 守衛）→ 302 order-received
 *  - 1172 / 1198 → 查明細 CAPTURE → 冪等完成；已付款訂單再導回 → 不打 API
 *  - 1180 → 保持 pending + note → 302 order-pay；網路逾時 → 不改狀態（留給 AS 補單）
 *  - cancelUrl → note + 302 order-pay
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter LinepayDirectConfirm --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers\LinepayMetaKeys;
use Tests\Integration\Payment\Doubles\LinepayDirectTestTrait;
use Tests\Integration\TestCase;

/**
 * LINE Pay confirm / cancel 回呼測試
 *
 * @group integration
 * @group payment
 * @group linepay
 */
final class LinepayDirectConfirmTest extends TestCase {
	use LinepayDirectTestTrait;

	/** 每個測試前：啟用 LINE Pay */
	protected function configure_dependencies(): void {
		$this->setup_linepay();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_linepay_http();
		parent::tear_down();
	}

	/**
	 * 已送出付款請求的 pending 訂單
	 *
	 * @return \WC_Order
	 */
	private function pending_order(): \WC_Order {
		return $this->make_linepay_order( 1000.0, 'pending', true );
	}

	/**
	 * 冒煙：confirm / cancel 路由已註冊
	 *
	 * @group smoke
	 */
	public function test_冒煙_confirm與cancel路由已註冊(): void {
		$routes = \rest_get_server()->get_routes();

		$this->assertArrayHasKey( '/power-checkout/linepay/confirm', $routes );
		$this->assertArrayHasKey( '/power-checkout/linepay/cancel', $routes );
	}

	/**
	 * Happy：confirm 0000 → processing、transaction id、302 order-received；confirm body 金額由後端訂單決定
	 *
	 * @group happy
	 */
	public function test_confirm成功轉processing並302導回order_received(): void {
		$order = $this->pending_order();
		$this->fake_linepay( 'confirm', $this->linepay_fixture( 'confirm_ok', $this->linepay_order_id_of( $order ) ) );

		$response = $this->linepay_return( 'confirm', $order );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( self::$linepay_tx, $fresh->get_transaction_id() );
		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( $fresh->get_checkout_order_received_url(), $this->linepay_location( $response ) );

		$requests = $this->linepay_requests( 'confirm' );
		$this->assertCount( 1, $requests );
		$this->assertStringEndsWith( '/v4/payments/' . self::$linepay_tx . '/confirm', $requests[0]['path'] );
		$this->assertSame(
			[
				'amount'   => 1000,
				'currency' => 'TWD',
			],
			$this->linepay_request_body( 'confirm' )
		);
		$this->assertNotEmpty( ( new LinepayMetaKeys( $fresh ) )->get_payment_detail() );
	}

	/**
	 * Edge：confirm 回 1172（前一次 confirm 回應遺失）→ 查明細 CAPTURE → 冪等完成；再導回一次不打 API、不重複 note
	 *
	 * @group edge
	 */
	public function test_重複confirm_1172查明細後冪等完成(): void {
		$order = $this->pending_order();
		$this->fake_linepay( 'confirm', $this->linepay_fixture( 'confirm_1172' ) );
		$this->fake_linepay( 'details', $this->linepay_fixture( 'details_capture', $this->linepay_order_id_of( $order ) ) );

		$this->linepay_return( 'confirm', $order );
		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 'transactionId=' . self::$linepay_tx, $this->linepay_requests( 'details' )[0]['query'] );

		$api_calls = \count( $this->linepay_requests() );
		$response  = $this->linepay_return( 'confirm', $order );

		$this->assertSame( $api_calls, \count( $this->linepay_requests() ), '已付款訂單再導回不應再打 API' );
		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( \wc_get_order( $order->get_id() )->get_checkout_order_received_url(), $this->linepay_location( $response ) );
		$paid_notes = \array_filter(
			$this->linepay_order_notes( $order ),
			static fn( string $note ): bool => \str_contains( $note, 'LINE Pay 付款成功' )
		);
		$this->assertCount( 1, $paid_notes );
	}

	/**
	 * Security：transactionId 與 meta 不符（偽造 / 換單）→ 不 confirm、不改狀態
	 *
	 * @group security
	 */
	public function test_transactionId不符不confirm(): void {
		$order = $this->pending_order();
		$this->block_linepay_http();

		$response = $this->linepay_return( 'confirm', $order, [ 'transactionId' => '2026111900000000002' ] );

		$this->assertSame( [], $this->linepay_requests() );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 302, $response->get_status() );
	}

	/**
	 * Security：order_key 錯 → 不 confirm、不改狀態、不導向該訂單頁
	 *
	 * @group security
	 */
	public function test_order_key錯不confirm(): void {
		$order = $this->pending_order();
		$this->block_linepay_http();

		$response = $this->linepay_return( 'confirm', $order, [ 'key' => 'wc_order_forged' ] );

		$this->assertSame( [], $this->linepay_requests() );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringNotContainsString( $order->get_order_key(), $this->linepay_location( $response ) );
	}

	/**
	 * Security：orderId 與 meta 不符 → 不 confirm
	 *
	 * @group security
	 */
	public function test_orderId不符不confirm(): void {
		$order = $this->pending_order();
		$this->block_linepay_http();

		$this->linepay_return( 'confirm', $order, [ 'orderId' => 'PCL999-1-OTHER1' ] );

		$this->assertSame( [], $this->linepay_requests() );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Security：非 LINE Pay 訂單 → 不 confirm
	 *
	 * @group security
	 */
	public function test_非LINE_Pay訂單不confirm(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true, 'bacs' );
		$this->block_linepay_http();

		$this->linepay_return( 'confirm', $order );

		$this->assertSame( [], $this->linepay_requests() );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Error：1180 逾時 → 保持 pending + note 含 1180、302 order-pay
	 *
	 * @group error
	 */
	public function test_confirm逾時1180保持pending並導回order_pay(): void {
		$order = $this->pending_order();
		$this->fake_linepay( 'confirm', $this->linepay_fixture( 'confirm_1180' ) );

		$response = $this->linepay_return( 'confirm', $order );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $fresh->get_status() );
		$this->assertStringContainsString( '1180', \implode( "\n", $this->linepay_order_notes( $order ) ) );
		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( $fresh->get_checkout_payment_url(), $this->linepay_location( $response ) );
	}

	/**
	 * Error：confirm 網路逾時 → 不改狀態（留給 AS 補單），仍 302 order-received
	 *
	 * @group error
	 */
	public function test_confirm網路逾時不改狀態(): void {
		$order = $this->pending_order();
		$this->fake_linepay( 'confirm', new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$response = $this->linepay_return( 'confirm', $order );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $fresh->get_status() );
		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( $fresh->get_checkout_order_received_url(), $this->linepay_location( $response ) );
	}

	/**
	 * Edge：confirm 0000 但 payInfo 金額與送出金額不符 → 不完成、note 警示
	 *
	 * @group edge
	 */
	public function test_confirm金額不符不完成訂單(): void {
		$order = $this->pending_order();
		$this->fake_linepay( 'confirm', $this->linepay_fixture( 'confirm_ok', $this->linepay_order_id_of( $order ), 999 ) );

		$this->linepay_return( 'confirm', $order );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( '金額不符', \implode( "\n", $this->linepay_order_notes( $order ) ) );
	}

	/**
	 * confirm 0000 回應（自訂 info 內容，用於 payInfo / packages 缺漏情境）
	 *
	 * @param \WC_Order $order 訂單
	 * @param string    $extra info 內 orderId / transactionId 之後的 JSON 片段（含前導逗號，可空）
	 * @return string
	 */
	private function confirm_ok_without_pay_info( \WC_Order $order, string $extra ): string {
		return '{"returnCode":"0000","returnMessage":"OK","info":{"orderId":"' . $this->linepay_order_id_of( $order )
			. '","transactionId":' . self::$linepay_tx . $extra . '}}';
	}

	/**
	 * Edge：confirm 0000 無 payInfo → 以 packages 金額加總守衛，相符即完成
	 *
	 * @group edge
	 */
	public function test_confirm無payInfo時以packages金額守衛_相符完成(): void {
		$order = $this->pending_order();
		$this->fake_linepay( 'confirm', $this->confirm_ok_without_pay_info( $order, ',"packages":[{"id":"1","amount":600},{"id":"2","amount":400}]' ) );

		$this->linepay_return( 'confirm', $order );

		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Security：confirm 0000 無 payInfo 且 packages 金額不符 → 不完成、note 警示
	 *
	 * @group security
	 */
	public function test_confirm無payInfo且packages金額不符不完成(): void {
		$order = $this->pending_order();
		$this->fake_linepay( 'confirm', $this->confirm_ok_without_pay_info( $order, ',"packages":[{"id":"1","amount":1}]' ) );

		$this->linepay_return( 'confirm', $order );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( '金額不符', \implode( "\n", $this->linepay_order_notes( $order ) ) );
	}

	/**
	 * Security：confirm 0000 但 payInfo / packages 都無法取得金額 → fail closed：不完成、note 警示
	 *
	 * @group security
	 */
	public function test_confirm無任何金額資訊時不完成(): void {
		$order = $this->pending_order();
		$this->fake_linepay( 'confirm', $this->confirm_ok_without_pay_info( $order, ',"payInfo":[],"packages":[{"id":"1"}]' ) );

		$this->linepay_return( 'confirm', $order );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( '無法確認金額', \implode( "\n", $this->linepay_order_notes( $order ) ) );
	}

	/**
	 * Security：明細補單（1172 → details）無 payInfo 也無 packages → 不完成
	 *
	 * @group security
	 */
	public function test_明細無金額資訊時不完成(): void {
		$order = $this->pending_order();
		$this->fake_linepay( 'confirm', $this->linepay_fixture( 'confirm_1172' ) );
		$this->fake_linepay(
			'details',
			'{"returnCode":"0000","returnMessage":"success","info":[{"transactionId":' . self::$linepay_tx
				. ',"transactionType":"PAYMENT","orderId":"' . $this->linepay_order_id_of( $order ) . '","payStatus":"CAPTURE"}]}'
		);

		$this->linepay_return( 'confirm', $order );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( '無法確認金額', \implode( "\n", $this->linepay_order_notes( $order ) ) );
	}

	/**
	 * Happy：cancelUrl → note「取消」+ 302 order-pay，不打 API
	 *
	 * @group happy
	 */
	public function test_cancelUrl留note並導回order_pay(): void {
		$order = $this->pending_order();
		$this->block_linepay_http();

		$response = $this->linepay_return( 'cancel', $order );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( [], $this->linepay_requests() );
		$this->assertSame( 'pending', $fresh->get_status() );
		$this->assertStringContainsString( '取消', \implode( "\n", $this->linepay_order_notes( $order ) ) );
		$this->assertSame( 302, $response->get_status() );
		$this->assertSame( $fresh->get_checkout_payment_url(), $this->linepay_location( $response ) );
	}
}
