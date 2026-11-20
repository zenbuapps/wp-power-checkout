<?php
/**
 * 黑貓PAY 退款 / 請款 / 取消授權測試（P2）
 *
 * 退款依平台 process_code 分流（「請款了沒」是平台端事實，必須先 CocsOrderQuery）：
 *  - process_code >= 20（已進入請款）→ CocsOrderRefund（可部分）
 *  - process_code == 15（授權完成未請款）→ 全額：CocsOrderCancel；部分：不支援（銀行僅支援全額取消授權）
 *  - CVS / ATM → 平台無退款 API → UNSUPPORTED
 * API 失敗 → 共用 fail_gateway_refund()（刪 refund、全額回滾、記錄 _pc_refund_error）。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter TcatPayRefund --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\TcatPay\Http\TcatPayApiClient;
use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayMetaKeys;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use Tests\Integration\Payment\Doubles\TcatPayTestTrait;
use Tests\Integration\TestCase;

/**
 * 黑貓PAY 退款測試
 *
 * @group integration
 * @group payment
 * @group tcat_pay
 */
final class TcatPayRefundTest extends TestCase {
	use TcatPayTestTrait;

	/** 每個測試前：啟用黑貓PAY */
	protected function configure_dependencies(): void {
		$this->setup_tcat_pay();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_tcat_pay_http();
		parent::tear_down();
	}

	/**
	 * 已付款訂單
	 *
	 * @param string $method   子方式
	 * @param string $trade_no 交易編號
	 * @param string $payment_method gateway id
	 * @return \WC_Order
	 */
	private function make_paid_order( string $method = 'credit', string $trade_no = 'PCT1-PAID', string $payment_method = TcatPayGateway::ID ): \WC_Order {
		$order = $this->make_tcat_order( 1000.0, 'processing', $method, $trade_no, $payment_method );
		( new TcatPayMetaKeys( $order ) )->update_payment_detail( $this->tcat_fixture( 'cocs_order_query_captured', $trade_no ) );
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
				'reason'         => '黑貓PAY退款測試',
				'refund_payment' => true,
			]
		);
	}

	/**
	 * Happy：已請款（22）全額 → CocsOrderRefund（order_amount = 送單金額、refund_amount 整數）
	 *
	 * @group happy
	 */
	public function test_已請款全額退款走CocsOrderRefund(): void {
		$order = $this->make_paid_order();
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_captured', 'PCT1-PAID' ) );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_REFUND, $this->tcat_fixture( 'collect_ok', 'PCT1-PAID', [ 'refund_amount' => 1000 ] ) );

		$refund = $this->refund( $order, 1000.0 );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertNotFalse( \wc_get_order( $refund->get_id() ), '成功時 refund 應保留' );
		$body = $this->tcat_request_body( TcatPayApiClient::CMD_COCS_ORDER_REFUND );
		$this->assertSame( 'PCT1-PAID', $body['cust_order_no'] );
		$this->assertSame( 1000, $body['order_amount'] );
		$this->assertSame( 1000, $body['refund_amount'] );
		$this->assertSame( 'payuni', $body['acquirer_type'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $body['send_time'] );
	}

	/**
	 * Happy：已請款（22）部分退款 → CocsOrderRefund refund_amount=300，訂單維持 processing
	 *
	 * @group happy
	 */
	public function test_已請款部分退款(): void {
		$order = $this->make_paid_order();
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_captured', 'PCT1-PAID' ) );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_REFUND, $this->tcat_fixture( 'collect_ok', 'PCT1-PAID', [ 'refund_amount' => 300 ] ) );

		$refund = $this->refund( $order, 300.0 );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertSame( 300, $this->tcat_request_body( TcatPayApiClient::CMD_COCS_ORDER_REFUND )['refund_amount'] );
		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Happy：授權完成未請款（15）全額 → CocsOrderCancel（取消授權）
	 *
	 * @group happy
	 */
	public function test_未請款全額走取消授權(): void {
		$order = $this->make_paid_order();
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_authorized', 'PCT1-PAID' ) );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_CANCEL, $this->tcat_fixture( 'collect_ok', 'PCT1-PAID' ) );

		$refund = $this->refund( $order, 1000.0 );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertNotFalse( \wc_get_order( $refund->get_id() ) );
		$this->assertCount( 1, $this->tcat_collect_requests( TcatPayApiClient::CMD_COCS_ORDER_CANCEL ) );
		$this->assertSame( [], $this->tcat_collect_requests( TcatPayApiClient::CMD_COCS_ORDER_REFUND ) );
	}

	/**
	 * Error：未請款部分退款 → 不打退款 API，fail_gateway_refund（UNSUPPORTED）
	 *
	 * @group error
	 */
	public function test_未請款部分退款不支援(): void {
		$order = $this->make_paid_order();
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_authorized', 'PCT1-PAID' ) );

		$refund = $this->refund( $order, 300.0 );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertFalse( \wc_get_order( $refund->get_id() ), '失敗時 refund 應被刪除' );
		$this->assertSame( [], $this->tcat_collect_requests( TcatPayApiClient::CMD_COCS_ORDER_CANCEL ) );
		$this->assertSame( [], $this->tcat_collect_requests( TcatPayApiClient::CMD_COCS_ORDER_REFUND ) );
		$error = \wc_get_order( $order->get_id() )->get_meta( '_pc_refund_error' );
		$this->assertIsArray( $error );
		$this->assertSame( ErrorCode::UNSUPPORTED->value, $error['error_code'] );
	}

	/**
	 * Error：CVS / ATM 訂單 → process_refund 回 UNSUPPORTED，不打 API
	 *
	 * @group error
	 */
	public function test_CVS訂單不支援API退款(): void {
		$order = $this->make_paid_order( 'cvs_ibon', 'PCT1-CVSPAID' );
		$this->block_tcat_http();

		$result = ( new TcatPayGateway() )->process_refund( $order->get_id(), 100.0 );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( ErrorCode::UNSUPPORTED, NormalizedError::get_code( $result ) );
		$this->assertSame( [], $this->tcat_collect_requests() );
	}

	/**
	 * Error：退款 API 失敗 → fail_gateway_refund（refund 刪除、全額回滾 processing、PROVIDER）
	 *
	 * @group error
	 */
	public function test_退款API失敗走fail_gateway_refund(): void {
		$order = $this->make_paid_order();
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_captured', 'PCT1-PAID' ) );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_REFUND, $this->tcat_fixture( 'collect_error' ) );

		$refund = $this->refund( $order, 1000.0 );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertFalse( \wc_get_order( $refund->get_id() ), 'API 失敗時 refund 應被刪除' );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$error = $fresh->get_meta( '_pc_refund_error' );
		$this->assertIsArray( $error );
		$this->assertSame( ErrorCode::PROVIDER->value, $error['error_code'] );
		$this->assertStringContainsString( '目前的訂單程序不允許取消交易.', (string) $error['message'] );
	}

	/**
	 * Edge：金額 ≤0 / 超過訂單金額 → 不允許
	 *
	 * @group edge
	 */
	public function test_退款金額不合法(): void {
		$order   = $this->make_paid_order();
		$gateway = new TcatPayGateway();

		$this->assertFalse( $gateway->process_refund( $order->get_id(), 0.0 ) );
		$over = $gateway->process_refund( $order->get_id(), 5000.0 );
		$this->assertInstanceOf( \WP_Error::class, $over );
		$this->assertSame( ErrorCode::VALIDATION, NormalizedError::get_code( $over ) );
		$this->assertTrue( $gateway->process_refund( $order->get_id(), 1000.0 ) );
	}

	/**
	 * Edge：handler 不處理其他 gateway 的訂單
	 *
	 * @group edge
	 */
	public function test_非黑貓PAY訂單handler不處理(): void {
		$this->block_tcat_http();
		$order  = $this->make_paid_order( 'credit', 'PCT1-BACS', 'bacs' );
		$refund = \wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 100.0,
				'reason'   => '手動',
			]
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );

		( new TcatPayGateway() )->process_gateway_refund( $order->get_id(), $refund->get_id() );

		$this->assertSame( [], $this->tcat_collect_requests() );
	}

	/**
	 * Happy：capture → CocsCashRequest（cr_amount = 送單金額）；void_auth → CocsOrderCancel
	 *
	 * @group happy
	 */
	public function test_請款與取消授權(): void {
		$order = $this->make_paid_order();
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_CASH_REQUEST, $this->tcat_fixture( 'collect_ok', 'PCT1-PAID' ) );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_CANCEL, $this->tcat_fixture( 'collect_ok', 'PCT1-PAID' ) );

		$gateway = new TcatPayGateway();
		$gateway->capture( $order );
		$cash = $this->tcat_request_body( TcatPayApiClient::CMD_COCS_CASH_REQUEST );
		$this->assertSame( 1000, $cash['order_amount'] );
		$this->assertSame( 1000, $cash['cr_amount'] );

		$gateway->void_auth( $order );
		$cancel = $this->tcat_request_body( TcatPayApiClient::CMD_COCS_ORDER_CANCEL );
		$this->assertSame( 1000, $cancel['order_amount'] );
		$this->assertSame( 'payuni', $cancel['acquirer_type'] );

		$notes = \implode( "\n", $this->tcat_order_notes( $order ) );
		$this->assertStringContainsString( '請款成功', $notes );
		$this->assertStringContainsString( '取消授權成功', $notes );
	}

	/**
	 * Error：CVS 訂單請款 / 取消授權 → 不打 API，留 note
	 *
	 * @group error
	 */
	public function test_CVS訂單請款與取消授權不打API(): void {
		$order = $this->make_paid_order( 'atm', 'PCT1-ATMPAID' );
		$this->block_tcat_http();

		$gateway = new TcatPayGateway();
		$gateway->capture( $order );
		$gateway->void_auth( $order );

		$this->assertSame( [], $this->tcat_collect_requests() );
		$this->assertStringContainsString( '不支援', \implode( "\n", $this->tcat_order_notes( $order ) ) );
	}

	/**
	 * Happy：query_trade 依子方式打 CocsOrderQuery / CvsOrderQuery
	 *
	 * @group happy
	 */
	public function test_query_trade依子方式查詢(): void {
		$order = $this->make_paid_order();
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_captured', 'PCT1-PAID' ) );

		$result = ( new TcatPayGateway() )->query_trade( $order );

		$this->assertSame( 22, $result['process_code'] );
		$this->assertSame( 'PCT1-PAID', $this->tcat_request_body( TcatPayApiClient::CMD_COCS_ORDER_QUERY )['cust_order_no'] );
	}
}
