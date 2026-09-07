<?php
/**
 * StatusManager 整合測試
 * 驗證 Shopline Payment 收到付款狀態後，訂單狀態是否正確更新，
 * 以及 issue #18 補上的冪等 / 終態 / 幣別 / 金額四道守衛。
 *
 * ⚠️ 訂單必須帶正確的 total 與 currency：
 *    加入金額 / 幣別守衛後，total = 0 或幣別非 TWD 的訂單會被守衛擋在 SUCCEEDED 之外。
 *
 * @see specs/open-issue/issue-18-plan.md §流程 3
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\StatusSource;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use Tests\Integration\TestCase;

/**
 * StatusManager 測試類別
 *
 * @group integration
 * @group payment
 */
final class StatusManagerTest extends TestCase {

	/**
	 * 封鎖所有 outbound HTTP 的 filter callback
	 *
	 * 本測試類別不發出任何外部請求。封鎖它是為了擋掉 WP 於 shutdown 觸發的 wp-cron
	 * loopback 請求——該請求由共用同一測試資料庫的 wp-env 測試站以另一條 DB 連線處理，
	 * 其 Action Scheduler 同樣寫 wp_posts，會與本測試的 wc_create_order() INSERT 互鎖，
	 * 造成 InnoDB deadlock。deadlock 會把 WP_UnitTestCase 包覆測試的外層交易整個 rollback，
	 * 之後 WC 內建 hook（PayPal gateway、wc_order_fully_refunded）裡的 wc_get_order()
	 * 就會拿到 false 而爆 "Call to a member function ... on false"。
	 *
	 * @var callable|null
	 */
	private $block_http_callback = null;

	/** 每次測試前封鎖 outbound HTTP */
	protected function configure_dependencies(): void {
		$this->block_http_callback = static fn() => new \WP_Error(
			'http_request_blocked',
			'測試中不允許 outbound HTTP'
		);
		\add_filter( 'pre_http_request', $this->block_http_callback, 1, 3 );
	}

	/** 每次測試後移除 filter */
	public function tear_down(): void {
		if ( null !== $this->block_http_callback ) {
			\remove_filter( 'pre_http_request', $this->block_http_callback, 1 );
			$this->block_http_callback = null;
		}

		parent::tear_down();
	}

	/**
	 * 建立帶有正確 total / currency 的測試訂單
	 *
	 * 寫入次數刻意壓到與 TestCase::create_wc_order() 相同（1 次 create + 1 次 save）：
	 * update_status() 內含 save()，會一併持久化 currency 與 total，不需額外 save()。
	 * 訂單建立的寫入越少，撞上 wp_posts deadlock 的機會越低。
	 *
	 * @param string $status   訂單狀態
	 * @param string $total    訂單金額（元）
	 * @param string $currency 訂單幣別（store 預設可能是 USD，必須顯式指定）
	 * @return \WC_Order
	 */
	private function make_order( string $status = 'pending', string $total = '100.00', string $currency = 'TWD' ): \WC_Order {
		$order = \wc_create_order();
		$order->set_currency( $currency );
		$order->set_total( $total );
		$order->update_status( $status );
		return $order;
	}

	/**
	 * 建立最小可用的 PaymentDTO（依照 PaymentDTO::$require_properties 及子 DTO 必填欄位）
	 *
	 * @param string $status         ResponseStatus 枚舉值
	 * @param string $trade_order_id tradeOrderId
	 * @param int    $amount_cents   通知的金額（cents）
	 * @param string $currency       通知的幣別
	 * @return PaymentDTO
	 */
	private function make_payment_dto(
		string $status,
		string $trade_order_id = 'TRADE_001',
		int $amount_cents = 10000,
		string $currency = 'TWD',
		?int $paid_amount_cents = null,
		?string $paid_currency = null
	): PaymentDTO {
		return PaymentDTO::create(
			[
				'referenceOrderId' => 'REF_001',
				'tradeOrderId'     => $trade_order_id,
				'status'           => $status,
				'order'            => [
					'merchantId'       => 'MERCHANT_TEST',
					'referenceOrderId' => 'REF_001',
					'createTime'       => time(),
					'amount'           => [
						'value'    => $amount_cents,
						'currency' => $currency,
					],
					'customer'         => [
						'referenceCustomerId' => 'CUSTOMER_001',
						'customerId'          => 'SLP_CUSTOMER_001',
					],
				],
				'payment'          => [
					'paymentMethod'   => 'CreditCard',
					'paymentBehavior' => 'Regular',
					'paidAmount'      => [
						'value'    => $paid_amount_cents ?? $amount_cents,
						'currency' => $paid_currency ?? $currency,
					],
				],
			]
		);
	}

	/**
	 * 計算訂單的 order note 數量
	 *
	 * @param \WC_Order $order 訂單
	 * @return int
	 */
	private function count_order_notes( \WC_Order $order ): int {
		return count( \wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_StatusManager_可以被實例化(): void {
		$order   = $this->make_order();
		$dto     = $this->make_payment_dto( 'SUCCEEDED' );
		$manager = new StatusManager( $dto, $order );

		$this->assertInstanceOf( StatusManager::class, $manager );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款成功時訂單狀態變更為processing(): void {
		// Given: 一筆 pending、應收 100 元的訂單
		$order = $this->make_order( 'pending', '100.00' );

		// When: 收到付款 SUCCEEDED webhook（10000 cents = 100 元）
		$dto     = $this->make_payment_dto( 'SUCCEEDED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態變更為 processing
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款成功時付款詳情儲存至meta(): void {
		// Given: 一筆 pending 訂單
		$order = $this->make_order( 'pending', '100.00' );

		// When: 收到付款 SUCCEEDED webhook
		$dto     = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_DETAIL_001' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 付款詳情儲存至 _pc_payment_detail
		$payment_detail = $this->get_payment_detail( $order );
		$this->assertNotEmpty( $payment_detail, '付款詳情不應為空' );
		$this->assertArrayHasKey( 'tradeOrderId', $payment_detail );
		$this->assertSame( 'TRADE_DETAIL_001', $payment_detail['tradeOrderId'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款成功時order_note被新增(): void {
		// Given: 一筆 pending 訂單
		$order = $this->make_order( 'pending', '100.00' );

		// When: 收到付款 SUCCEEDED webhook
		$dto     = $this->make_payment_dto( 'SUCCEEDED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單備忘錄中有付款狀態記錄（包含「成功」字樣）
		$this->assert_order_note_contains( $order, '成功' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_付款成功時寫入transaction_id(): void {
		// Given: 一筆 pending 訂單
		$order = $this->make_order( 'pending', '100.00' );

		// When: 收到付款 SUCCEEDED webhook
		$dto     = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_TXN_001' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: transaction_id = tradeOrderId
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( 'TRADE_TXN_001', $fresh->get_transaction_id() );
	}

	/**
	 * order note 標題冠上來源標籤，讓客服一眼看出是誰認列的
	 *
	 * @test
	 * @group happy
	 */
	public function test_order_note標題帶有狀態來源標籤(): void {
		// Given: 一筆 pending 訂單
		$order = $this->make_order( 'pending', '100.00' );

		// When: 由導回同步路徑認列
		$dto     = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_SOURCE_001' );
		$manager = new StatusManager( $dto, $order, StatusSource::RETURN_SYNC );
		$manager->update_order_status();

		// Then: order note 含 [導回同步] 標籤
		$this->assert_order_note_contains( $order, '[導回同步]' );
	}

	/**
	 * issue #18 真實案例金額（skyisland.tw 訂單 #40165，NT$48,800）
	 *
	 * @test
	 * @group happy
	 */
	public function test_真實案例金額48800可以通過金額守衛(): void {
		// Given: 一筆應收 48800 元的訂單
		$order = $this->make_order( 'pending', '48800.00' );

		// When: 收到 4880000 cents 的付款成功通知
		$dto     = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_48800', 4880000 );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 通過守衛，轉為 processing
		$this->assert_order_status( $order, 'processing' );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_付款逾期時訂單狀態變更為cancelled(): void {
		// Given: 一筆 pending 訂單
		$order = $this->make_order( 'pending', '100.00' );

		// When: 收到付款 EXPIRED webhook
		$dto     = $this->make_payment_dto( 'EXPIRED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態變更為 cancelled（終態守衛只作用於 SUCCEEDED，不影響此分支）
		$this->assert_order_status( $order, 'cancelled' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_付款失敗時訂單狀態保持pending(): void {
		// Given: 一筆 pending 訂單
		$order = $this->make_order( 'pending', '100.00' );

		// When: 收到 FAILED webhook
		$dto     = $this->make_payment_dto( 'FAILED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態保持 pending（未知狀態 fallback 到 pending）
		$this->assert_order_status( $order, 'pending' );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_未知狀態時訂單狀態fallback為pending(): void {
		// Given: 一筆 pending 訂單
		$order = $this->make_order( 'pending', '100.00' );

		// When: 收到未知狀態的 webhook（不在 ResponseStatus 枚舉中）
		$dto     = $this->make_payment_dto( 'PROCESSING' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態保持 pending（match default 分支）
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_同一訂單收到兩次SUCCEEDED_webhook(): void {
		// Given: 一筆 processing 訂單（已付款）
		$order = $this->make_order( 'processing', '100.00' );

		// When: 再次收到 SUCCEEDED webhook（重複通知）
		$dto     = $this->make_payment_dto( 'SUCCEEDED' );
		$manager = new StatusManager( $dto, $order );
		$manager->update_order_status();

		// Then: 訂單狀態仍為 processing（不應因重複而產生異常）
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * 冪等守衛：同一 tradeOrderId + status 只處理一次，不重複寫 order note
	 *
	 * @test
	 * @group edge
	 */
	public function test_冪等守衛_相同付款狀態第二次不重複處理(): void {
		// Given: 一筆 pending 訂單已被認列一次
		$order = $this->make_order( 'pending', '100.00' );
		$dto   = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_IDEMPOTENT_001' );
		( new StatusManager( $dto, $order ) )->update_order_status();
		$notes_after_first = $this->count_order_notes( $order );

		// When: 相同的付款狀態再次進來（導回同步與 Webhook 幾乎同時到達）
		( new StatusManager( $dto, $order, StatusSource::RETURN_SYNC ) )->update_order_status();

		// Then: 不再新增 order note，狀態維持 processing
		$this->assertSame(
			$notes_after_first,
			$this->count_order_notes( $order ),
			'冪等守衛應阻止重複寫入 order note'
		);
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * 冪等鍵已寫入 meta，供另一條認列路徑判讀
	 *
	 * @test
	 * @group edge
	 */
	public function test_冪等鍵寫入_pc_payment_processed_status(): void {
		// Given / When: 一筆訂單被認列
		$order = $this->make_order( 'pending', '100.00' );
		$dto   = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_PROCESSED_KEY' );
		( new StatusManager( $dto, $order ) )->update_order_status();

		// Then: 冪等鍵格式為 "{tradeOrderId}:{status}"
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$meta_keys = new MetaKeys( $fresh );
		$this->assertContains( 'TRADE_PROCESSED_KEY:SUCCEEDED', $meta_keys->get_processed_status() );
	}

	/**
	 * 已處理中守衛：訂單已被某筆付款認列為 processing 後，
	 * 收到「不同 tradeOrderId」的合法 SUCCEEDED 不得覆寫既有付款明細與 transaction_id
	 *
	 * 冪等鍵是 "{tradeOrderId}:{status}"，不同 tradeOrderId 不會命中，
	 * 終態守衛也不擋 processing——需靠這道守衛把關（對齊 PayNow / PAYUNi）。
	 *
	 * @test
	 * @group edge
	 */
	public function test_已processing訂單收到不同tradeOrderId的成功通知時不覆寫(): void {
		// Given: 訂單已被 T1 認列
		$order = $this->make_order( 'pending', '100.00' );
		( new StatusManager( $this->make_payment_dto( 'SUCCEEDED', 'TRADE_T1' ), $order ) )->update_order_status();
		$this->assert_order_status( $order, 'processing' );

		$detail_after_first = $this->get_payment_detail( \wc_get_order( $order->get_id() ) );
		$notes_after_first  = $this->count_order_notes( $order );

		// When: 收到另一筆 tradeOrderId（T2）的合法 SUCCEEDED，金額幣別皆相符
		( new StatusManager( $this->make_payment_dto( 'SUCCEEDED', 'TRADE_T2' ), $order ) )->update_order_status();

		// Then: 狀態不變，且付款明細 / transaction_id 不被覆寫、不多寫 order note
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assert_order_status( $order, 'processing' );
		$this->assertSame(
			'TRADE_T1',
			$fresh->get_transaction_id(),
			'transaction_id 不得被後續通知覆寫'
		);
		$this->assertSame(
			$detail_after_first['tradeOrderId'] ?? null,
			$this->get_payment_detail( $fresh )['tradeOrderId'] ?? null,
			'_pc_payment_detail 不得被後續通知覆寫'
		);
		$this->assertSame(
			$notes_after_first,
			$this->count_order_notes( $order ),
			'已處理中守衛不應再寫 order note'
		);
	}

	/**
	 * 已處理中守衛只作用於 SUCCEEDED：已 processing 的訂單收到 FAILED 是**異常訊號**
	 *（款已認列卻被通知失敗），必須留下 order note，不可被冪等層靜默吃掉
	 *
	 * @test
	 * @group edge
	 */
	public function test_已processing訂單收到FAILED通知時仍留下order_note(): void {
		// Given: 訂單已被認列為 processing
		$order = $this->make_order( 'pending', '100.00' );
		( new StatusManager( $this->make_payment_dto( 'SUCCEEDED', 'TRADE_OK' ), $order ) )->update_order_status();
		$this->assert_order_status( $order, 'processing' );
		$notes_after_success = $this->count_order_notes( $order );

		// When: 收到 FAILED 通知（異常訊號）
		( new StatusManager( $this->make_payment_dto( 'FAILED', 'TRADE_LATE_FAIL' ), $order ) )->update_order_status();

		// Then: 必須留下 note（已處理中守衛只在 SUCCEEDED 路徑上生效，不得吞掉此訊號）
		$this->assertGreaterThan(
			$notes_after_success,
			$this->count_order_notes( $order ),
			'已 processing 收到 FAILED 是異常訊號，必須留下 order note'
		);
		$this->assert_order_note_contains( $order, '失敗' );
	}

	/**
	 * 同上，EXPIRED 也是異常訊號，同樣不可被靜默吃掉
	 *
	 * @test
	 * @group edge
	 */
	public function test_已processing訂單收到EXPIRED通知時仍留下order_note(): void {
		// Given: 訂單已被認列為 processing
		$order = $this->make_order( 'pending', '100.00' );
		( new StatusManager( $this->make_payment_dto( 'SUCCEEDED', 'TRADE_OK_2' ), $order ) )->update_order_status();
		$notes_after_success = $this->count_order_notes( $order );

		// When: 收到 EXPIRED 通知
		( new StatusManager( $this->make_payment_dto( 'EXPIRED', 'TRADE_LATE_EXPIRED' ), $order ) )->update_order_status();

		// Then: 留下 note（狀態轉換屬既有行為，此處只鎖可觀測性不被吞掉）
		$this->assertGreaterThan(
			$notes_after_success,
			$this->count_order_notes( $order ),
			'已 processing 收到 EXPIRED 是異常訊號，必須留下 order note'
		);
	}

	/**
	 * 降級路徑端到端：`paidAmount` 缺席但 `order.amount` 正常 → 仍應認列成功
	 *
	 * 驗的是降級路徑「真的通」，而不是靜默失敗。
	 * （守衛層的單點驗證見 test_實付金額缺席時降級比對應收回音且不誤擋）
	 *
	 * @test
	 * @group edge
	 */
	public function test_實付金額缺席時仍可完成認列(): void {
		// Given: 訂單應收 100 元；回音 10000 相符；實付金額的 value 被還原為未初始化
		$order = $this->make_order( 'pending', '100.00' );
		$dto   = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_DEGRADED_E2E', 10000 );
		unset( $dto->payment->paidAmount->value );

		// When: 走完整流程
		( new StatusManager( $dto, $order ) )->update_order_status();

		// Then: 合法通知照常認列，未因通知殘缺而靜默失敗
		$this->assert_order_status( $order, 'processing' );
		$this->assertNotEmpty( $this->get_payment_detail( $order ), '應寫入付款明細' );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( 'TRADE_DEGRADED_E2E', $fresh->get_transaction_id() );
		$this->assert_order_note_contains( $order, '通知內容不完整' );
	}

	/**
	 * 金額守衛容差：Components\Amount 以 $amount * 100 產生 cents，
	 * 浮點運算可能產生 ±1 cent 誤差（19.99 * 100 → 1998.9999...），不得誤判為竄改
	 *
	 * @test
	 * @group edge
	 */
	public function test_金額守衛容許1cent浮點誤差(): void {
		// Given: 一筆應收 19.99 元（1999 cents）的訂單
		$order = $this->make_order( 'pending', '19.99' );

		// When: 通知金額為 1998 cents（差 1 cent，浮點截斷的典型結果）
		$dto = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_FLOAT_001', 1998 );
		( new StatusManager( $dto, $order ) )->update_order_status();

		// Then: 視為合法付款，轉為 processing
		$this->assert_order_status( $order, 'processing' );
	}

	// ========== 安全性（Security） ==========

	/**
	 * 金額守衛：通知金額與訂單應收差距超過容差 → 拒絕認列
	 *
	 * @test
	 * @group security
	 */
	public function test_金額不符時拒絕認列並記錄疑似竄改(): void {
		// Given: 一筆應收 100 元（10000 cents）的訂單
		$order = $this->make_order( 'pending', '100.00' );

		// When: 收到金額被竄改為 9900 cents（差 100 cents）的付款成功通知
		$dto = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_TAMPER_001', 9900 );
		( new StatusManager( $dto, $order ) )->update_order_status();

		// Then: 維持 pending，且留下「疑似竄改」告警
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, '疑似竄改' );
		$this->assertEmpty( $this->get_payment_detail( $order ), '守衛攔截時不得寫入付款明細' );
	}

	/**
	 * 幣別守衛：store 預設幣別可能是 USD，不比對幣別則 USD 訂單金額有機會與 TWD 通知偶然相符
	 *
	 * @test
	 * @group security
	 */
	public function test_幣別不符時拒絕認列(): void {
		// Given: 一筆 USD 訂單
		$order = $this->make_order( 'pending', '100.00', 'USD' );

		// When: 收到 TWD 的付款成功通知（金額 cents 相同）
		$dto = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_CURRENCY_001', 10000, 'TWD' );
		( new StatusManager( $dto, $order ) )->update_order_status();

		// Then: 維持 pending
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, '疑似竄改' );
	}

	/**
	 * 終態守衛：已退款 / 已取消 / 已完成的訂單不得被重放「復活」為 processing
	 *
	 * @test
	 * @group security
	 */
	public function test_終態訂單收到付款成功通知時拒絕復活(): void {
		// Given: 一筆已退款的訂單
		$order = $this->make_order( 'refunded', '100.00' );

		// When: 收到（可能是重放的）付款成功通知
		$dto = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_TERMINAL_001' );
		( new StatusManager( $dto, $order ) )->update_order_status();

		// Then: 維持 refunded，且留下告警
		$this->assert_order_status( $order, 'refunded' );
		$this->assert_order_note_contains( $order, '終態' );
	}

	/**
	 * 金額守衛必須比對「顧客實付金額」（payment->paidAmount），
	 * 而不是我方建立 session 時送出、SLP 原樣回傳的「應收回音」（order->amount）。
	 *
	 * 本測試刻意讓回音與訂單金額相符、實付金額不符——
	 * 若守衛讀的是回音，這筆會被放行（守衛形同虛設）。
	 *
	 * @test
	 * @group security
	 */
	public function test_實付金額不符時拒絕認列即使應收回音相符(): void {
		// Given: 訂單應收 100 元；通知的 order.amount 回音是正確的 10000，但實付只有 100 cents
		$order = $this->make_order( 'pending', '100.00' );
		$dto   = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_PAID_TAMPER', 10000, 'TWD', 100 );

		// When
		( new StatusManager( $dto, $order ) )->update_order_status();

		// Then: 以實付金額為準攔下，維持 pending
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, '疑似竄改' );
		$this->assert_order_note_contains( $order, '實付金額' );
		$this->assertEmpty( $this->get_payment_detail( $order ), '守衛攔截時不得寫入付款明細' );
	}

	/**
	 * 幣別守衛同樣以實付金額的幣別為準
	 *
	 * @test
	 * @group security
	 */
	public function test_實付幣別不符時拒絕認列即使應收回音幣別相符(): void {
		// Given: TWD 訂單；回音幣別為 TWD（相符），但實付幣別是 USD
		$order = $this->make_order( 'pending', '100.00', 'TWD' );
		$dto   = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_PAID_CURRENCY', 10000, 'TWD', 10000, 'USD' );

		// When
		( new StatusManager( $dto, $order ) )->update_order_status();

		// Then: 以實付幣別為準攔下
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, '疑似竄改' );
		$this->assert_order_note_contains( $order, '實付金額' );
	}

	/**
	 * 實付金額相符時正常認列（確認新的取值來源沒有誤擋合法付款）
	 *
	 * @test
	 * @group security
	 */
	public function test_實付金額相符時正常認列(): void {
		$order = $this->make_order( 'pending', '100.00' );
		$dto   = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_PAID_OK', 10000, 'TWD', 10000 );

		( new StatusManager( $dto, $order ) )->update_order_status();

		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * 降級行為：實付金額取不到時，退回比對應收回音，且不得誤擋合法通知
	 *
	 * 以 unset 把 Amount::$value 還原為未初始化，模擬 production 環境下
	 * DTO 基底吞掉建構錯誤、殘缺 payload 留下未初始化屬性的情形
	 * （local 環境的 DTO 會直接 throw，無法用工廠方法造出此狀態）。
	 *
	 * 這裡直接驗守衛方法而非跑完整流程：完整流程接著會呼叫 to_human_html()，
	 * 而它同樣會讀 paidAmount（既有行為，與本守衛無關），在此人造狀態下會拋 Error
	 * ——實際執行時由 WebHook / ReturnSyncManager 的 never-throw 邊界吸收。
	 * 本測試要證明的是「守衛本身不會誤擋」。
	 *
	 * @test
	 * @group security
	 */
	public function test_實付金額缺席時降級比對應收回音且不誤擋(): void {
		// Given: 訂單應收 100 元，回音 10000 相符；實付金額的 value 被還原為未初始化
		$order = $this->make_order( 'pending', '100.00' );
		$dto   = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_PAID_MISSING', 10000 );
		unset( $dto->payment->paidAmount->value );

		// When: 直接呼叫守衛
		$method = new \ReflectionMethod( StatusManager::class, 'can_mark_as_paid' );
		$method->setAccessible( true );
		$can_mark_as_paid = $method->invoke( new StatusManager( $dto, $order ) );

		// Then: 降級到應收回音比對後放行，合法通知不被誤擋
		$this->assertTrue( $can_mark_as_paid, '實付金額缺席時應降級比對回音，不得誤擋合法通知' );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * 降級行為：實付金額取不到且回音也不符時，仍須被守衛擋下（降級不等於放行）
	 *
	 * @test
	 * @group security
	 */
	public function test_實付金額缺席且回音不符時仍拒絕認列(): void {
		// Given: 訂單應收 100 元，回音是 9900（不符）；實付金額未初始化
		$order = $this->make_order( 'pending', '100.00' );
		$dto   = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_PAID_MISSING_BAD', 9900 );
		unset( $dto->payment->paidAmount->value );

		// When
		( new StatusManager( $dto, $order ) )->update_order_status();

		// Then: 降級後仍以回音比對並攔下，note 標明來源為降級
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, '疑似竄改' );
		$this->assert_order_note_contains( $order, '降級' );
	}

	/**
	 * 終態守衛只作用於 SUCCEEDED，EXPIRED → cancelled 的既有行為不受影響
	 *
	 * @test
	 * @group security
	 */
	public function test_已取消訂單收到付款成功通知時不轉為processing(): void {
		// Given: 一筆已取消的訂單
		$order = $this->make_order( 'cancelled', '100.00' );

		// When: 收到付款成功通知
		$dto = $this->make_payment_dto( 'SUCCEEDED', 'TRADE_TERMINAL_002' );
		( new StatusManager( $dto, $order ) )->update_order_status();

		// Then: 維持 cancelled
		$this->assert_order_status( $order, 'cancelled' );
	}
}
