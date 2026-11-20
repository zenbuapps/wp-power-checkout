<?php
/**
 * LINE Pay 直連補單測試（P3：Action Scheduler pc_linepay_reconcile + 後台「查詢補單」）
 *
 * 顧客認證後關掉瀏覽器 → confirmUrl 永遠不會被呼叫（R3）。補單一律「先查付款請求狀態」再決定：
 *  - 0110（已認證未 confirm）→ confirm → processing
 *  - 0123（已完成）→ 查明細 CAPTURE → processing（不再 confirm）
 *  - 0000（尚未認證）→ 依 filter 延遲重新排程，直到付款請求失效（約 20 分鐘）或達最大輪次
 *  - 0121 / 0122 → note，不改狀態（不自動取消）、不再排程
 *  - 查詢失敗 → 重排 1 次（+600 秒，attempt=2）；第 2 次仍失敗 → note 放棄
 *  - 訂單已非待付款 → no-op（不打 API）
 * ⚠️ 不可拿 confirm 當探針：未認證就 confirm 會 1169 且整筆作廢。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter LinepayDirectReconcile --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Linepay\Managers\LinepayReconcileJob;
use J7\PowerCheckout\Domains\Payment\Linepay\Services\LinepayGateway;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers\LinepayMetaKeys;
use Tests\Integration\Payment\Doubles\LinepayDirectTestTrait;
use Tests\Integration\TestCase;

/**
 * LINE Pay 補單測試
 *
 * @group integration
 * @group payment
 * @group linepay
 */
final class LinepayDirectReconcileTest extends TestCase {
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
	 * Happy：pending + check 0110 → confirm → processing；check path 帶 19 位 transactionId
	 *
	 * @group happy
	 */
	public function test_已認證未confirm由補單代為confirm(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true );
		$this->fake_linepay( 'check', $this->linepay_fixture( 'check_0110' ) );
		$this->fake_linepay( 'confirm', $this->linepay_fixture( 'confirm_ok', $this->linepay_order_id_of( $order ) ) );

		\do_action( LinepayReconcileJob::HOOK, $order->get_id() );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( self::$linepay_tx, $fresh->get_transaction_id() );
		$this->assertStringEndsWith( '/v4/payments/requests/' . self::$linepay_tx . '/check', $this->linepay_requests( 'check' )[0]['path'] );
		$this->assertSame( 1000, $this->linepay_request_body( 'confirm' )['amount'] );
		$this->assertStringContainsString( '補單', \implode( "\n", $this->linepay_order_notes( $order ) ) );
	}

	/**
	 * Edge：已 processing → no-op（不打 API）
	 *
	 * @group edge
	 */
	public function test_已付款訂單補單不打API(): void {
		$order = $this->make_linepay_order( 1000.0, 'processing', true );
		$this->block_linepay_http();

		\do_action( LinepayReconcileJob::HOOK, $order->get_id() );

		$this->assertSame( [], $this->linepay_requests() );
		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Edge：非 LINE Pay 訂單 / 無 transactionId → no-op
	 *
	 * @group edge
	 */
	public function test_非LINE_Pay或無交易序號補單不打API(): void {
		$other   = $this->make_linepay_order( 1000.0, 'pending', true, 'bacs' );
		$no_txid = $this->make_linepay_order( 1000.0, 'pending', false );
		$this->block_linepay_http();

		\do_action( LinepayReconcileJob::HOOK, $other->get_id() );
		\do_action( LinepayReconcileJob::HOOK, $no_txid->get_id() );

		$this->assertSame( [], $this->linepay_requests() );
	}

	/**
	 * Edge：check 0123（已完成，前次 confirm 回應遺失）→ 查明細 CAPTURE → processing，不再 confirm
	 *
	 * @group edge
	 */
	public function test_已完成交易查明細補單不重複confirm(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true );
		$this->fake_linepay( 'check', $this->linepay_fixture( 'check_0123' ) );
		$this->fake_linepay( 'details', $this->linepay_fixture( 'details_capture', $this->linepay_order_id_of( $order ) ) );

		\do_action( LinepayReconcileJob::HOOK, $order->get_id() );

		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( [], $this->linepay_requests( 'confirm' ) );
	}

	/**
	 * Edge：check 0121（顧客取消 / 逾時）→ note，不改狀態、不 confirm
	 *
	 * @group edge
	 */
	public function test_取消或逾時只留note(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true );
		$this->fake_linepay( 'check', $this->linepay_fixture( 'check_0121' ) );

		\do_action( LinepayReconcileJob::HOOK, $order->get_id() );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( [], $this->linepay_requests( 'confirm' ) );
		$this->assertStringContainsString( '0121', \implode( "\n", $this->linepay_order_notes( $order ) ) );
	}

	/**
	 * Edge：check 0000（顧客尚未認證）→ 不 confirm、不改狀態，依 filter 延遲重新排程（pending_round=1）
	 *
	 * @group edge
	 */
	public function test_尚未認證時重新排程補單(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true );
		$this->fake_linepay( 'check', $this->linepay_fixture( 'check_0000' ) );

		$before = \time();
		\do_action( LinepayReconcileJob::HOOK, $order->get_id() );

		$next = \as_next_scheduled_action( LinepayReconcileJob::HOOK, $this->pending_args( $order, 1 ), LinepayReconcileJob::GROUP );
		$this->assertIsInt( $next );
		$this->assertEqualsWithDelta( $before + LinepayReconcileJob::DEFAULT_DELAY, $next, 60 );
		$this->assertSame( [], $this->linepay_requests( 'confirm' ) );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Edge：重新排程沿用 filter `power_checkout_linepay_reconcile_delay` 的延遲，且 pending_round 遞增
	 *
	 * @group edge
	 */
	public function test_尚未認證重新排程沿用filter延遲並遞增輪次(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true );
		$this->fake_linepay( 'check', $this->linepay_fixture( 'check_0000' ) );
		$delay = static fn(): int => 120;
		\add_filter( 'power_checkout_linepay_reconcile_delay', $delay );

		$before = \time();
		\do_action( LinepayReconcileJob::HOOK, $order->get_id(), 1, 2 );
		\remove_filter( 'power_checkout_linepay_reconcile_delay', $delay );

		$next = \as_next_scheduled_action( LinepayReconcileJob::HOOK, $this->pending_args( $order, 3 ), LinepayReconcileJob::GROUP );
		$this->assertIsInt( $next );
		$this->assertEqualsWithDelta( $before + 120, $next, 30 );
	}

	/**
	 * Edge：付款請求已超過約 20 分鐘仍未認證 → 不再排程，留「停止自動補單」note
	 *
	 * @group edge
	 */
	public function test_付款請求失效後停止重新排程(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true );
		$order->update_meta_data( LinepayMetaKeys::REQUESTED_AT_KEY, (string) ( \time() - LinepayReconcileJob::REQUEST_EXPIRY - 60 ) );
		$order->save();
		$this->fake_linepay( 'check', $this->linepay_fixture( 'check_0000' ) );

		\do_action( LinepayReconcileJob::HOOK, $order->get_id(), 1, 3 );

		$this->assertFalse( \as_next_scheduled_action( LinepayReconcileJob::HOOK ) );
		$this->assertStringContainsString( '停止自動補單', \implode( "\n", $this->linepay_order_notes( $order ) ) );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Edge：達最大重新排程次數 → 不再排程（防無限迴圈；無請求時間 meta 的舊訂單也會停）
	 *
	 * @group edge
	 */
	public function test_達最大次數停止重新排程(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true );
		$order->delete_meta_data( LinepayMetaKeys::REQUESTED_AT_KEY );
		$order->save();
		$this->fake_linepay( 'check', $this->linepay_fixture( 'check_0000' ) );

		\do_action( LinepayReconcileJob::HOOK, $order->get_id(), 1, LinepayReconcileJob::MAX_PENDING_ROUNDS );

		$this->assertFalse( \as_next_scheduled_action( LinepayReconcileJob::HOOK ) );
		$this->assertStringContainsString( '停止自動補單', \implode( "\n", $this->linepay_order_notes( $order ) ) );
	}

	/**
	 * Edge：付款請求寫入請求時間 meta（失效判斷依據）
	 *
	 * @group edge
	 */
	public function test_付款請求寫入請求時間(): void {
		$before = \time();
		$order  = $this->make_linepay_order( 1000.0, 'pending', true );

		$this->assertEqualsWithDelta( $before, (int) $order->get_meta( LinepayMetaKeys::REQUESTED_AT_KEY ), 5 );
	}

	/**
	 * Edge：0121（取消 / 逾時）→ 不重新排程
	 *
	 * @group edge
	 */
	public function test_取消或逾時不重新排程(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true );
		$this->fake_linepay( 'check', $this->linepay_fixture( 'check_0121' ) );

		\do_action( LinepayReconcileJob::HOOK, $order->get_id() );

		$this->assertFalse( \as_next_scheduled_action( LinepayReconcileJob::HOOK ) );
	}

	/**
	 * Edge：後台手動查詢補單遇 0000 → 只留 note，不排程（重新排程只屬於自動補單）
	 *
	 * @group edge
	 */
	public function test_後台查詢補單尚未認證不排程(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true );
		$this->fake_linepay( 'check', $this->linepay_fixture( 'check_0000' ) );

		LinepayGateway::handle_query_action( $order );

		$this->assertFalse( \as_next_scheduled_action( LinepayReconcileJob::HOOK ) );
		$this->assertStringContainsString( '0000', \implode( "\n", $this->linepay_order_notes( $order ) ) );
	}

	/**
	 * 未認證重新排程的 AS args
	 *
	 * @param \WC_Order $order 訂單
	 * @param int       $round 第幾輪
	 * @return array<string, int>
	 */
	private function pending_args( \WC_Order $order, int $round ): array {
		return [
			'order_id'      => $order->get_id(),
			'attempt'       => 1,
			'pending_round' => $round,
		];
	}

	/**
	 * Error：查詢失敗 → 重排 1 次（attempt=2，約 +600 秒）；第 2 次仍失敗 → note 放棄、不再重排
	 *
	 * @group error
	 */
	public function test_查詢失敗重排一次後放棄(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true );
		$this->fake_linepay( 'check', new \WP_Error( 'http_request_failed', 'timeout' ) );

		$before = \time();
		\do_action( LinepayReconcileJob::HOOK, $order->get_id() );

		$retry_args = [
			'order_id' => $order->get_id(),
			'attempt'  => 2,
		];
		$next       = \as_next_scheduled_action( LinepayReconcileJob::HOOK, $retry_args );
		$this->assertIsInt( $next );
		$this->assertEqualsWithDelta( $before + 600, $next, 60 );

		\as_unschedule_all_actions( LinepayReconcileJob::HOOK );
		\do_action( LinepayReconcileJob::HOOK, $order->get_id(), 2 );

		$this->assertFalse( \as_next_scheduled_action( LinepayReconcileJob::HOOK ) );
		$this->assertStringContainsString( '放棄', \implode( "\n", $this->linepay_order_notes( $order ) ) );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Happy：後台「查詢補單」order action → 同一套補單流程
	 *
	 * @group happy
	 */
	public function test_後台查詢補單(): void {
		$order = $this->make_linepay_order( 1000.0, 'pending', true );
		$this->fake_linepay( 'check', $this->linepay_fixture( 'check_0110' ) );
		$this->fake_linepay( 'confirm', $this->linepay_fixture( 'confirm_ok', $this->linepay_order_id_of( $order ) ) );

		$actions = LinepayGateway::add_order_actions( [], $order );
		$this->assertArrayHasKey( LinepayGateway::ACTION_QUERY, $actions );

		LinepayGateway::handle_query_action( $order );

		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Edge：後台查詢補單只對 LINE Pay 訂單顯示
	 *
	 * @group edge
	 */
	public function test_查詢補單只對LINE_Pay訂單顯示(): void {
		$other = $this->make_linepay_order( 1000.0, 'pending', false, 'bacs' );

		$this->assertArrayNotHasKey( LinepayGateway::ACTION_QUERY, LinepayGateway::add_order_actions( [], $other ) );
	}
}
