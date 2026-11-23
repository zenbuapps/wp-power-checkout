<?php
/**
 * 黑貓PAY 自動補單測試（Action Scheduler `pc_tcat_pay_reconcile`）
 *
 * 平台 APN 同一狀態只重送約 3 次（每 15 分鐘），超商 / ATM 顧客可能數天後才繳費：
 * 我方若在那段窗口無法回 OK，通知就永久遺失。補單任務主動查平台把「已收款仍 pending」的訂單補回：
 *  - 建單成功（credit / cvs_ibon / atm）即排入任務
 *  - 只經 StatusManager::settle_paid()（明確已收款 process_code + 金額快照守衛）認列
 *  - credit：短窗口退避（數分鐘起，約 1 小時內）；CVS / ATM：每數小時查一次，直到繳費期限（+寬限）
 *  - 訂單已付款 / 已取消 / 已退款 / 非黑貓PAY → no-op（不打 API）
 *  - 平台未收款 → 永不自動取消，期滿只留 note
 *  - 查詢失敗 → 稍後重試，連續失敗達上限 → note 放棄
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter TcatPayReconcile --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\TcatPay\Http\TcatPayApiClient;
use J7\PowerCheckout\Domains\Payment\TcatPay\Managers\TcatPayReconcileJob;
use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayMetaKeys;
use Tests\Integration\Payment\Doubles\TcatPayTestTrait;
use Tests\Integration\TestCase;

/**
 * 黑貓PAY 自動補單測試
 *
 * @group integration
 * @group payment
 * @group tcat_pay
 */
final class TcatPayReconcileTest extends TestCase {
	use TcatPayTestTrait;

	/** 每個測試前：啟用黑貓PAY */
	protected function configure_dependencies(): void {
		$this->setup_tcat_pay();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_tcat_pay_http();
		unset( $_POST[ TcatPayGateway::FIELD_NAME ] );
		parent::tear_down();
	}

	/**
	 * 下一次排程時間
	 *
	 * @param \WC_Order $order 訂單
	 * @return int|bool
	 */
	private function next_run( \WC_Order $order ): int|bool {
		return \as_next_scheduled_action( TcatPayReconcileJob::HOOK, [ 'order_id' => $order->get_id() ], TcatPayReconcileJob::GROUP );
	}

	/**
	 * 寫入 CVS 繳費期限
	 *
	 * @param \WC_Order $order       訂單
	 * @param string    $expire_date 期限 YYYY-MM-DD
	 * @return void
	 */
	private function set_expire_date( \WC_Order $order, string $expire_date ): void {
		( new TcatPayMetaKeys( $order ) )->update_payment_info( [ 'expire_date' => $expire_date ] );
	}

	/**
	 * 冒煙：補單 hook 無條件註冊
	 *
	 * @group smoke
	 */
	public function test_冒煙_補單hook已註冊(): void {
		$this->assertNotFalse( \has_action( TcatPayReconcileJob::HOOK, [ TcatPayReconcileJob::class, 'run' ] ) );
	}

	/**
	 * Happy：credit 建單成功 → 排入補單（約 +5 分鐘）
	 *
	 * @group happy
	 */
	public function test_credit建單後排入補單(): void {
		$order = $this->make_tcat_order( 1000.0 );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_APPEND,
			fn( array $body ): array => $this->tcat_fixture( 'cocs_order_append', (string) $body['cust_order_no'] )
		);

		$before                              = \time();
		$_POST[ TcatPayGateway::FIELD_NAME ] = 'credit';
		( new TcatPayGateway() )->process_payment( $order->get_id() );

		$next = $this->next_run( $order );
		$this->assertIsInt( $next );
		$this->assertEqualsWithDelta( $before + TcatPayReconcileJob::CREDIT_DELAYS[0], $next, 60 );
	}

	/**
	 * Happy：ATM 取號成功 → 排入補單（約 +CVS_INTERVAL）
	 *
	 * @group happy
	 */
	public function test_ATM取號後排入補單(): void {
		$order = $this->make_tcat_order( 1000.0 );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_CVS_ORDER_APPEND,
			fn( array $body ): array => $this->tcat_fixture( 'cvs_order_append_atm', (string) $body['cust_order_no'] )
		);

		$before                              = \time();
		$_POST[ TcatPayGateway::FIELD_NAME ] = 'atm';
		( new TcatPayGateway() )->process_payment( $order->get_id() );

		$next = $this->next_run( $order );
		$this->assertIsInt( $next );
		$this->assertEqualsWithDelta( $before + TcatPayReconcileJob::CVS_INTERVAL, $next, 60 );
	}

	/**
	 * Error：建單失敗 → 不排補單
	 *
	 * @group error
	 */
	public function test_建單失敗不排補單(): void {
		$order = $this->make_tcat_order( 1000.0 );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_CVS_ORDER_APPEND, $this->tcat_fixture( 'cvs_order_append_error' ) );

		$_POST[ TcatPayGateway::FIELD_NAME ] = 'cvs_ibon';
		( new TcatPayGateway() )->process_payment( $order->get_id() );

		$this->assertFalse( $this->next_run( $order ) );
	}

	/**
	 * Happy：credit 平台已授權 → 經 settle_paid 認列 processing，不再排程
	 *
	 * @group happy
	 */
	public function test_平台已收款時補單認列(): void {
		$trade_no = 'PCT1-RECOK';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_authorized', $trade_no ) );

		\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( $trade_no, $fresh->get_transaction_id() );
		$this->assertSame( $trade_no, $this->tcat_request_body( TcatPayApiClient::CMD_COCS_ORDER_QUERY )['cust_order_no'] );
		$this->assertStringContainsString( '自動補單', \implode( "\n", $this->tcat_order_notes( $order ) ) );
		$this->assertFalse( $this->next_run( $order ) );
	}

	/**
	 * Happy：超商代碼數天後才繳費 → CvsOrderQuery 已繳納 → 認列
	 *
	 * @group happy
	 */
	public function test_CVS平台已繳納時補單認列(): void {
		$trade_no = 'PCT1-RECCVS';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'cvs_ibon', $trade_no );
		$this->set_expire_date( $order, \wp_date( 'Y-m-d', \time() + 3 * DAY_IN_SECONDS ) );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_CVS_ORDER_QUERY, $this->tcat_fixture( 'cvs_order_query_paid', $trade_no ) );

		\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );

		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertFalse( $this->next_run( $order ) );
	}

	/**
	 * Edge：已付款 / 已取消 / 已退款 / 非黑貓PAY / 無交易編號 → no-op（不打 API、不排程）
	 *
	 * @group edge
	 */
	public function test_已結案或非本付款方式訂單不打API(): void {
		$this->block_tcat_http();
		$orders = [
			$this->make_tcat_order( 1000.0, 'processing', 'credit', 'PCT1-PAID' ),
			$this->make_tcat_order( 1000.0, 'cancelled', 'cvs_ibon', 'PCT1-CANCEL' ),
			$this->make_tcat_order( 1000.0, 'refunded', 'credit', 'PCT1-REFUND' ),
			$this->make_tcat_order( 1000.0, 'pending', 'credit', 'PCT1-BACSX', 'bacs' ),
			$this->make_tcat_order( 1000.0, 'pending', 'credit', null ),
		];

		foreach ( $orders as $order ) {
			\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );
			$this->assertFalse( $this->next_run( $order ) );
		}

		$this->assertSame( [], $this->tcat_collect_requests() );
		$this->assertSame( 'cancelled', \wc_get_order( $orders[1]->get_id() )->get_status() );
	}

	/**
	 * Edge：credit 平台未收款 → 不改狀態，依退避表排下一輪
	 *
	 * @group edge
	 */
	public function test_credit未收款依退避表重新排程(): void {
		$trade_no = 'PCT1-RECWAIT';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_unpaid', $trade_no ) );

		$before = \time();
		\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );

		$next = $this->next_run( $order );
		$this->assertIsInt( $next );
		$this->assertEqualsWithDelta( $before + TcatPayReconcileJob::CREDIT_DELAYS[1], $next, 60 );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 1, ( new TcatPayMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_reconcile_round() );
	}

	/**
	 * Edge：credit 退避表用完仍未收款 → 停止並留 note，不自動取消
	 *
	 * @group edge
	 */
	public function test_credit查詢次數用完停止補單(): void {
		$trade_no = 'PCT1-RECEND';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		( new TcatPayMetaKeys( $order ) )->update_reconcile_state( \count( TcatPayReconcileJob::CREDIT_DELAYS ) - 1, 0 );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_unpaid', $trade_no ) );

		\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );

		$this->assertFalse( $this->next_run( $order ) );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( '停止自動補單', \implode( "\n", $this->tcat_order_notes( $order ) ) );
	}

	/**
	 * Edge：CVS 繳費期限內未繳 → 依 CVS_INTERVAL 重新排程
	 *
	 * @group edge
	 */
	public function test_CVS期限內未繳持續排程(): void {
		$trade_no = 'PCT1-CVSWAIT';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'atm', $trade_no );
		$this->set_expire_date( $order, \wp_date( 'Y-m-d', \time() + 3 * DAY_IN_SECONDS ) );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_CVS_ORDER_QUERY, $this->tcat_fixture( 'cvs_order_query_unpaid', $trade_no ) );

		$before = \time();
		\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );

		$next = $this->next_run( $order );
		$this->assertIsInt( $next );
		$this->assertEqualsWithDelta( $before + TcatPayReconcileJob::CVS_INTERVAL, $next, 60 );
	}

	/**
	 * Edge：CVS 已過繳費期限（含寬限）仍未繳 → 停止並留 note，保持 pending（永不自動取消）
	 *
	 * @group edge
	 */
	public function test_CVS逾期停止補單且不取消(): void {
		$trade_no = 'PCT1-CVSEXP';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'cvs_ibon', $trade_no );
		$this->set_expire_date( $order, \wp_date( 'Y-m-d', \time() - 10 * DAY_IN_SECONDS ) );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_CVS_ORDER_QUERY, $this->tcat_fixture( 'cvs_order_query_unpaid', $trade_no ) );

		\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );

		$this->assertFalse( $this->next_run( $order ) );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$notes = \implode( "\n", $this->tcat_order_notes( $order ) );
		$this->assertStringContainsString( '停止自動補單', $notes );
		$this->assertStringContainsString( '繳費期限', $notes );
	}

	/**
	 * Edge：CVS 無期限資訊 → 以最大輪次為上限
	 *
	 * @group edge
	 */
	public function test_CVS無期限資訊達最大輪次停止(): void {
		$trade_no = 'PCT1-CVSCAP';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'cvs_ibon', $trade_no );
		( new TcatPayMetaKeys( $order ) )->update_reconcile_state( TcatPayReconcileJob::CVS_MAX_ROUNDS - 1, 0 );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_CVS_ORDER_QUERY, $this->tcat_fixture( 'cvs_order_query_unpaid', $trade_no ) );

		\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );

		$this->assertFalse( $this->next_run( $order ) );
		$this->assertStringContainsString( '停止自動補單', \implode( "\n", $this->tcat_order_notes( $order ) ) );
	}

	/**
	 * Security：平台已收款但實收金額 ≠ 快照（疑似重複繳款）→ 不認列、留 note、不再排程
	 *
	 * @group security
	 */
	public function test_金額不符不認列(): void {
		$trade_no = 'PCT1-RECAMT';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'cvs_ibon', $trade_no );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_CVS_ORDER_QUERY,
			$this->tcat_fixture( 'cvs_order_query_paid', $trade_no, [ 'pay_amount' => 2000 ] )
		);

		\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( '金額不符', \implode( "\n", $this->tcat_order_notes( $order ) ) );
		$this->assertFalse( $this->next_run( $order ) );
	}

	/**
	 * Error：查詢失敗 → 約 +RETRY_DELAY 重試；連續失敗達上限 → note 放棄、不再排程
	 *
	 * @group error
	 */
	public function test_查詢失敗重試並有上限(): void {
		$trade_no = 'PCT1-RECERR';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, new \WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );

		$before = \time();
		\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );

		$next = $this->next_run( $order );
		$this->assertIsInt( $next );
		$this->assertEqualsWithDelta( $before + TcatPayReconcileJob::RETRY_DELAY, $next, 60 );
		$this->assertSame( 1, ( new TcatPayMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_reconcile_failures() );

		\as_unschedule_all_actions( TcatPayReconcileJob::HOOK );
		( new TcatPayMetaKeys( \wc_get_order( $order->get_id() ) ) )->update_reconcile_state( 1, TcatPayReconcileJob::MAX_FAILURES - 1 );
		\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );

		$this->assertFalse( $this->next_run( $order ) );
		$this->assertStringContainsString( '放棄', \implode( "\n", $this->tcat_order_notes( $order ) ) );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Edge：延遲可由 filter `power_checkout_tcat_pay_reconcile_delay` 調整（下限 60 秒）
	 *
	 * @group edge
	 */
	public function test_延遲可由filter調整(): void {
		$trade_no = 'PCT1-RECFLT';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_unpaid', $trade_no ) );
		$delay = static fn(): int => 5;
		\add_filter( 'power_checkout_tcat_pay_reconcile_delay', $delay );

		$before = \time();
		\do_action( TcatPayReconcileJob::HOOK, $order->get_id() );
		\remove_filter( 'power_checkout_tcat_pay_reconcile_delay', $delay );

		$next = $this->next_run( $order );
		$this->assertIsInt( $next );
		$this->assertEqualsWithDelta( $before + 60, $next, 30, '低於 60 秒時取下限' );
	}
}
