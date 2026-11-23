<?php
/**
 * LINE Pay 直連退款 / 查詢測試（P3）
 *
 * - 自動請款 → 只能 refund（不能 void）：POST /v4/payments/{transactionId}/refund，一律帶明確 refundAmount
 *   （省略 = 全額；明確金額避免「我方以為部分退、LINE Pay 退了全額」）
 * - 1165 已全退 / 1164 超額 → VALIDATION；其他 → PROVIDER；API 失敗走共用 fail_gateway_refund()
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter LinepayDirectRefund --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Linepay\Services\LinepayGateway;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use Tests\Integration\Payment\Doubles\LinepayDirectTestTrait;
use Tests\Integration\TestCase;

/**
 * LINE Pay 退款測試
 *
 * @group integration
 * @group payment
 * @group linepay
 */
final class LinepayDirectRefundTest extends TestCase {
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
	 * 已付款訂單
	 *
	 * @param string $payment_method gateway id
	 * @return \WC_Order
	 */
	private function paid_order( string $payment_method = LinepayGateway::ID ): \WC_Order {
		$order = $this->make_linepay_order( 1000.0, 'processing', true, $payment_method );
		$order->set_transaction_id( self::$linepay_tx );
		$order->save();
		return \wc_get_order( $order->get_id() );
	}

	/**
	 * 走真實退款鏈
	 *
	 * @param \WC_Order $order  訂單
	 * @param float     $amount 金額
	 * @return \WC_Order_Refund|\WP_Error
	 */
	private function refund( \WC_Order $order, float $amount ) {
		\WC()->payment_gateways()->init();
		return \wc_create_refund(
			[
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => 'LINE Pay 退款測試',
				'refund_payment' => true,
			]
		);
	}

	/**
	 * Happy：全額退款 → refund API 帶 refundAmount=1000，refund 保留、note 含退款交易序號
	 *
	 * @group happy
	 */
	public function test_全額退款(): void {
		$order = $this->paid_order();
		$this->fake_linepay( 'refund', $this->linepay_fixture( 'refund_ok' ) );

		$refund = $this->refund( $order, 1000.0 );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertNotFalse( \wc_get_order( $refund->get_id() ), '成功時 refund 應保留' );
		$requests = $this->linepay_requests( 'refund' );
		$this->assertCount( 1, $requests );
		$this->assertStringEndsWith( '/v4/payments/' . self::$linepay_tx . '/refund', $requests[0]['path'] );
		$this->assertSame( [ 'refundAmount' => 1000 ], $this->linepay_request_body( 'refund' ) );
		$this->assertStringContainsString( '2026111900000000999', \implode( "\n", $this->linepay_order_notes( $order ) ) );
	}

	/**
	 * Happy：部分退款 → refundAmount=300，訂單維持 processing
	 *
	 * @group happy
	 */
	public function test_部分退款(): void {
		$order = $this->paid_order();
		$this->fake_linepay( 'refund', $this->linepay_fixture( 'refund_ok' ) );

		$refund = $this->refund( $order, 300.0 );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertSame( [ 'refundAmount' => 300 ], $this->linepay_request_body( 'refund' ) );
		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Error：1165 已全退 → fail_gateway_refund（refund 刪除、全額回滾 processing、VALIDATION、raw_code 1165）
	 *
	 * @group error
	 */
	public function test_已全退1165回VALIDATION(): void {
		$order = $this->paid_order();
		$this->fake_linepay( 'refund', $this->linepay_fixture( 'refund_1165' ) );

		$refund = $this->refund( $order, 1000.0 );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertFalse( \wc_get_order( $refund->get_id() ), 'API 失敗時 refund 應被刪除' );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$error = $fresh->get_meta( '_pc_refund_error' );
		$this->assertIsArray( $error );
		$this->assertSame( ErrorCode::VALIDATION->value, $error['error_code'] );
		$this->assertSame( '1165', $error['raw_code'] );
	}

	/**
	 * Error：9000 內部錯誤 → PROVIDER
	 *
	 * @group error
	 */
	public function test_退款API內部錯誤回PROVIDER(): void {
		$order = $this->paid_order();
		$this->fake_linepay( 'refund', $this->linepay_fixture( 'internal_9000' ) );

		$this->refund( $order, 300.0 );

		$error = \wc_get_order( $order->get_id() )->get_meta( '_pc_refund_error' );
		$this->assertIsArray( $error );
		$this->assertSame( ErrorCode::PROVIDER->value, $error['error_code'] );
	}

	/**
	 * Error：連線失敗 → NETWORK
	 *
	 * @group error
	 */
	public function test_退款連線失敗回NETWORK(): void {
		$order = $this->paid_order();
		$this->fake_linepay( 'refund', new \WP_Error( 'http_request_failed', 'timeout' ) );

		$this->refund( $order, 300.0 );

		$error = \wc_get_order( $order->get_id() )->get_meta( '_pc_refund_error' );
		$this->assertIsArray( $error );
		$this->assertSame( ErrorCode::NETWORK->value, $error['error_code'] );
	}

	/**
	 * Edge：金額 ≤0 → false；超過訂單金額 → VALIDATION；缺 transactionId → VALIDATION
	 *
	 * @group edge
	 */
	public function test_退款前置檢查(): void {
		$order   = $this->paid_order();
		$gateway = new LinepayGateway();

		$this->assertFalse( $gateway->process_refund( $order->get_id(), 0.0 ) );
		$over = $gateway->process_refund( $order->get_id(), 5000.0 );
		$this->assertInstanceOf( \WP_Error::class, $over );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $over ) );
		$this->assertTrue( $gateway->process_refund( $order->get_id(), 1000.0 ) );

		$no_tx = $this->make_linepay_order( 1000.0, 'processing', false );
		$error = $gateway->process_refund( $no_tx->get_id(), 100.0 );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $error ) );
	}

	/**
	 * Edge：handler 不處理其他 gateway 的訂單
	 *
	 * @group edge
	 */
	public function test_非LINE_Pay訂單handler不處理(): void {
		$this->block_linepay_http();
		$order  = $this->paid_order( 'bacs' );
		$refund = \wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 100.0,
				'reason'   => '手動',
			]
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );

		( new LinepayGateway() )->process_gateway_refund( $order->get_id(), $refund->get_id() );

		$this->assertSame( [], $this->linepay_requests() );
	}

	/**
	 * Happy：query_trade → GET /v4/payments?transactionId=…，回明細（transactionId 為字串）
	 *
	 * @group happy
	 */
	public function test_query_trade查付款明細(): void {
		$order = $this->paid_order();
		$this->fake_linepay( 'details', $this->linepay_fixture( 'details_capture', $this->linepay_order_id_of( $order ) ) );

		$result = ( new LinepayGateway() )->query_trade( $order );

		$this->assertSame( 'transactionId=' . self::$linepay_tx, $this->linepay_requests( 'details' )[0]['query'] );
		$this->assertSame( self::$linepay_tx, $result['transactionId'] );
		$this->assertSame( 'CAPTURE', $result['payStatus'] );
	}
}
