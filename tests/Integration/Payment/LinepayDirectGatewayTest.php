<?php
/**
 * LINE Pay 直連 gateway 測試（P3）
 *
 * - process_payment → POST /v4/payments/request → redirect info.paymentUrl.web（api-redirect）
 * - transactionId 以字串保存（19 位，R4）；orderId = PCL{order_id}-{attempt}-{隨機}（每次付款唯一，1172）
 * - 請求成功即排 Action Scheduler 單次補單任務 pc_linepay_reconcile（約 +1500 秒，R3）
 * - returnCode ≠ 0000 → wc notice（通用訊息）+ order note 含 returnCode + 不 redirect
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter LinepayDirectGateway --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Linepay\Managers\LinepayReconcileJob;
use J7\PowerCheckout\Domains\Payment\Linepay\Services\LinepayGateway;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers\LinepayMetaKeys;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers\LinepaySignature;
use Tests\Integration\Payment\Doubles\LinepayDirectTestTrait;
use Tests\Integration\TestCase;

/**
 * LINE Pay gateway 測試
 *
 * @group integration
 * @group payment
 * @group linepay
 */
final class LinepayDirectGatewayTest extends TestCase {
	use LinepayDirectTestTrait;

	/** 每個測試前：啟用 LINE Pay */
	protected function configure_dependencies(): void {
		$this->setup_linepay();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_linepay_http();
		\wc_clear_notices();
		parent::tear_down();
	}

	/**
	 * request 成功的回應（orderId 依請求帶入）
	 *
	 * @return \Closure
	 */
	private function request_ok(): \Closure {
		return fn( array $req ): string => $this->linepay_fixture( 'request_ok' );
	}

	/**
	 * 冒煙：已註冊為 WC gateway，且補單 hook 已掛
	 *
	 * @group smoke
	 */
	public function test_冒煙_已註冊為WC付款方式且補單hook已掛(): void {
		\WC()->payment_gateways()->init();
		$gateways = \WC()->payment_gateways()->payment_gateways();

		$this->assertArrayHasKey( LinepayGateway::ID, $gateways );
		$this->assertInstanceOf( LinepayGateway::class, $gateways[ LinepayGateway::ID ] );
		$this->assertNotFalse( \has_action( LinepayReconcileJob::HOOK ) );
	}

	/**
	 * Happy：付款請求 → redirect paymentUrl.web、transactionId 字串、排 AS 補單任務
	 *
	 * @group happy
	 */
	public function test_付款請求成功導向paymentUrl並排補單任務(): void {
		$order = $this->make_linepay_order( 1000.0 );
		$this->fake_linepay( 'request', $this->request_ok() );

		$before = \time();
		$result = ( new LinepayGateway() )->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( 'https://sandbox-web-pay.line.me/web/payment/wait?transactionReserveId=TESTRESERVE001&locale=zh-TW_LP', $result['redirect'] );

		$fresh = \wc_get_order( $order->get_id() );
		$meta  = new LinepayMetaKeys( $fresh );
		$this->assertSame( self::$linepay_tx, $meta->get_transaction_id() );
		$this->assertSame( 1000, $meta->get_charge_amount() );
		$this->assertMatchesRegularExpression( '/^PCL' . $order->get_id() . '-1-[A-Z0-9]{6}$/', $meta->get_linepay_order_id() );
		$this->assertSame( 'pending', $fresh->get_status() );

		$next = \as_next_scheduled_action( LinepayReconcileJob::HOOK, [ 'order_id' => $order->get_id() ] );
		$this->assertIsInt( $next, '應排入 Action Scheduler 補單任務' );
		$this->assertEqualsWithDelta( $before + 1500, $next, 60 );
	}

	/**
	 * Happy：請求 body 與 header（金額後端算、confirm/cancel URL 自帶 order_key、簽章 = 實際送出的 body）
	 *
	 * @group happy
	 */
	public function test_付款請求內容與簽章(): void {
		$order = $this->make_linepay_order( 1000.0 );
		$this->fake_linepay( 'request', $this->request_ok() );

		( new LinepayGateway() )->process_payment( $order->get_id() );

		$requests = $this->linepay_requests( 'request' );
		$this->assertCount( 1, $requests );
		$req = $requests[0];
		$this->assertSame( 'https://sandbox-api-pay.line.me/v4/payments/request', $req['url'] );

		$body = $this->linepay_request_body( 'request' );
		$this->assertSame( 1000, $body['amount'] );
		$this->assertSame( 'TWD', $body['currency'] );
		$confirm = (string) $body['redirectUrls']['confirmUrl'];
		$this->assertStringContainsString( 'power-checkout/linepay/confirm', $confirm );
		$this->assertStringContainsString( 'key=' . $order->get_order_key(), $confirm );
		$this->assertStringContainsString( 'order_id=' . $order->get_id(), $confirm );
		$this->assertStringNotContainsString( 'transactionId', $confirm, '不可自行預放 transactionId' );
		$this->assertStringContainsString( 'power-checkout/linepay/cancel', (string) $body['redirectUrls']['cancelUrl'] );
		$this->assertLessThanOrEqual( 500, \strlen( $confirm ) );

		$headers = $req['headers'];
		$this->assertSame( 'application/json', $headers['Content-Type'] );
		$this->assertSame( self::$linepay_channel_id, $headers['X-LINE-ChannelId'] );
		$nonce = (string) $headers['X-LINE-Authorization-Nonce'];
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $nonce );
		$this->assertSame(
			LinepaySignature::sign( self::$linepay_channel_secret, '/v4/payments/request', $req['body'], $nonce ),
			$headers['X-LINE-Authorization']
		);
		$this->assertArrayNotHasKey( 'X-LINE-ChannelSecret', $headers, 'secret 只用於簽章，不可放進 header' );
	}

	/**
	 * Error：returnCode 1104 → 通用 notice + order note 含 1104 + 不 redirect、不排補單
	 *
	 * @group error
	 */
	public function test_付款請求失敗1104(): void {
		$order = $this->make_linepay_order( 1000.0 );
		$this->fake_linepay( 'request', $this->linepay_fixture( 'request_1104' ) );

		$result = ( new LinepayGateway() )->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertArrayNotHasKey( 'redirect', $result );
		$this->assertNotEmpty( \wc_get_notices( 'error' ) );
		$this->assertStringContainsString( '1104', \implode( "\n", $this->linepay_order_notes( $order ) ) );
		$this->assertFalse( \as_next_scheduled_action( LinepayReconcileJob::HOOK, [ 'order_id' => $order->get_id() ] ) );
		$this->assertSame( '', ( new LinepayMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_transaction_id() );
		$this->assertStringNotContainsString( self::$linepay_channel_secret, \implode( "\n", $this->linepay_order_notes( $order ) ) );
	}

	/**
	 * Error：連線失敗 → failure，不 redirect
	 *
	 * @group error
	 */
	public function test_付款請求連線失敗(): void {
		$order = $this->make_linepay_order( 1000.0 );
		$this->fake_linepay( 'request', new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$result = ( new LinepayGateway() )->process_payment( $order->get_id() );

		$this->assertSame( 'failure', $result['result'] );
		$this->assertFalse( \as_next_scheduled_action( LinepayReconcileJob::HOOK, [ 'order_id' => $order->get_id() ] ) );
	}

	/**
	 * Edge：19 位 transactionId（2026111900000000001）存取不失真，後續 confirm path 逐字相同
	 *
	 * @group edge
	 */
	public function test_19位transactionId存取不失真(): void {
		$order = $this->make_linepay_order( 1000.0 );
		$this->fake_linepay( 'request', $this->request_ok() );

		( new LinepayGateway() )->process_payment( $order->get_id() );

		$raw = \wc_get_order( $order->get_id() )->get_meta( LinepayMetaKeys::TRANSACTION_ID_KEY );
		$this->assertIsString( $raw );
		$this->assertSame( '2026111900000000001', $raw );
		$this->assertNotSame( (string) (float) '2026111900000000001', $raw, '不可經 float' );
	}

	/**
	 * Edge：同一訂單重試付款（order-pay）→ attempt 遞增產生新 orderId，補單任務只保留一筆
	 *
	 * @group edge
	 */
	public function test_重試付款產生新orderId且補單任務不重複(): void {
		$order = $this->make_linepay_order( 1000.0 );
		$this->fake_linepay( 'request', $this->request_ok() );
		$gateway = new LinepayGateway();

		$gateway->process_payment( $order->get_id() );
		$first = ( new LinepayMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_linepay_order_id();
		$gateway->process_payment( $order->get_id() );
		$second = ( new LinepayMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_linepay_order_id();

		$this->assertMatchesRegularExpression( '/^PCL' . $order->get_id() . '-2-[A-Z0-9]{6}$/', $second );
		$this->assertNotSame( $first, $second );
		$bodies = [ $this->linepay_request_body( 'request', 0 ), $this->linepay_request_body( 'request', 1 ) ];
		$this->assertNotSame( $bodies[0]['orderId'], $bodies[1]['orderId'] );

		$pending = \as_get_scheduled_actions(
			[
				'hook'   => LinepayReconcileJob::HOOK,
				'args'   => [ 'order_id' => $order->get_id() ],
				'status' => \ActionScheduler_Store::STATUS_PENDING,
			],
			'ids'
		);
		$this->assertCount( 1, $pending );
	}

	/**
	 * Edge：非 TWD 或缺憑證 → 前台不可用
	 *
	 * @group edge
	 */
	public function test_非TWD或缺憑證不可用(): void {
		$gateway = new LinepayGateway();
		$this->assertTrue( $gateway->is_available_for_currency( 'TWD' ) );

		\update_option( 'woocommerce_currency', 'USD' );
		$this->assertFalse( ( new LinepayGateway() )->is_available_for_currency( 'USD' ) );

		$this->setup_linepay( [ 'channel_secret' => '' ] );
		$this->assertFalse( ( new LinepayGateway() )->is_available_for_currency( 'TWD' ) );
	}
}
