<?php
/**
 * SLP 導回同步查詢整合測試（issue #18 缺陷 A）
 *
 * 測試目標：
 *   J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\ReturnSyncManager
 *
 * 規格依據：
 *   - specs/open-issue/issue-18-plan.md §流程 1 §測試策略
 *
 * 涵蓋範疇：
 *   - 查詢成功 → 認列（狀態 / payment_detail / identity / transaction_id）
 *   - 冪等（已認列訂單不打 API）與節流（30 秒 transient）
 *   - never-throw：API 連線失敗 / 業務錯誤碼都不得拋出，維持 pending
 *   - 資安三道閘門：order_key、referenceOrderId、金額 / 幣別守衛
 *
 * Mock 手法：
 *   HTTP 以 WordPress pre_http_request filter 攔截（API_MODE=mock 不打真實 SLP）
 *   tear_down 移除已掛 filter 並清空 $_GET，確保測試隔離
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter SlpReturnSyncTest tests/Integration/Payment/"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\ReturnSyncManager;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ReturnSyncResult;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * SLP 導回同步查詢測試類別
 *
 * @group integration
 * @group payment
 * @group shopline
 */
final class SlpReturnSyncTest extends TestCase {

	/** @var array<int, array{0: string, 1: callable, 2: int}> 已掛的 filter，tear_down 時移除 */
	private array $registered_filters = [];

	/** @var int 攔截到的 HTTP 請求次數 */
	private int $http_call_count = 0;

	/**
	 * 封鎖「未被 mock」的 outbound HTTP（優先權 99，讓 priority 10 的 mock 先生效）
	 *
	 * 除了確保測試不打真實 SLP，也擋掉 WP 於 shutdown 觸發的 wp-cron loopback 請求——
	 * 該請求會由共用同一測試資料庫的 wp-env 測試站以另一條 DB 連線處理，
	 * 與 WP_UnitTestCase 的外層交易搶鎖造成 InnoDB deadlock，
	 * 進而讓外層交易被 rollback、後續測試連鎖失敗。
	 *
	 * @var callable|null
	 */
	private $block_http_callback = null;

	/** 每次測試前啟用 SLP 並設為測試模式 */
	protected function configure_dependencies(): void {
		\putenv( 'API_MODE=mock' );

		$this->block_http_callback = static fn( $preempt ) => false === $preempt
			? new \WP_Error( 'http_request_blocked', '測試中不允許未 mock 的 outbound HTTP' )
			: $preempt;
		\add_filter( 'pre_http_request', $this->block_http_callback, 99, 3 );

		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'enabled' => 'yes',
				'mode'    => 'test',
				'signKey' => 'test_sign_key_for_return_sync',
			]
		);
		$this->http_call_count = 0;
	}

	/** 每次測試後清理 filter、$_GET 與環境 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );

		if ( null !== $this->block_http_callback ) {
			\remove_filter( 'pre_http_request', $this->block_http_callback, 99 );
			$this->block_http_callback = null;
		}

		foreach ( $this->registered_filters as [ $tag, $callback, $priority ] ) {
			\remove_filter( $tag, $callback, $priority );
		}
		$this->registered_filters = [];

		unset( $_GET['tradeOrderId'], $_GET['key'] );

		parent::tear_down();
	}

	// ========== Helper ==========

	/**
	 * 建立一筆 SLP pending 訂單（帶正確 total / currency，以通過金額 / 幣別守衛）
	 *
	 * @param string $status   訂單狀態
	 * @param string $total    訂單金額（元）
	 * @param string $currency 幣別
	 * @return \WC_Order
	 */
	private function make_slp_order( string $status = 'pending', string $total = '100.00', string $currency = 'TWD' ): \WC_Order {
		$order = \wc_create_order();
		$order->set_payment_method( RedirectGateway::ID );
		$order->set_currency( $currency );
		$order->set_total( $total );
		$order->save();
		$order->update_status( $status );
		$order->save();
		return $order;
	}

	/**
	 * 模擬客戶自 SLP 導回（帶 tradeOrderId 與正確的 order_key）
	 *
	 * @param \WC_Order   $order          訂單
	 * @param string      $trade_order_id tradeOrderId
	 * @param string|null $order_key      order_key，null 代表使用訂單真實的 key
	 * @return void
	 */
	private function simulate_return( \WC_Order $order, string $trade_order_id, ?string $order_key = null ): void {
		$_GET['tradeOrderId'] = $trade_order_id;
		$_GET['key']          = $order_key ?? $order->get_order_key();
	}

	/**
	 * 組出 SLP /trade/payment/get 的回應 body
	 *
	 * @param string $trade_order_id     tradeOrderId
	 * @param string $reference_order_id referenceOrderId（= WC order id）
	 * @param string $status             ResponseStatus
	 * @param int    $amount_cents       金額（cents）
	 * @param string $currency           幣別
	 * @return array<string, mixed>
	 */
	private function make_payment_body(
		string $trade_order_id,
		string $reference_order_id,
		string $status = 'SUCCEEDED',
		int $amount_cents = 10000,
		string $currency = 'TWD'
	): array {
		return [
			'referenceOrderId' => $reference_order_id,
			'tradeOrderId'     => $trade_order_id,
			'status'           => $status,
			'order'            => [
				'merchantId'       => 'MERCHANT_TEST',
				'referenceOrderId' => $reference_order_id,
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
					'value'    => $amount_cents,
					'currency' => $currency,
				],
			],
		];
	}

	/**
	 * 掛 pre_http_request，回傳固定的 JSON body
	 *
	 * @param array<string, mixed> $body 回應 body
	 * @return void
	 */
	private function mock_http_json( array $body ): void {
		$callback = function ( $preempt, $args, $url ) use ( $body ) { // phpcs:ignore
			++$this->http_call_count;
			return [
				'headers'  => [],
				'body'     => (string) \wp_json_encode( $body ),
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};

		\add_filter( 'pre_http_request', $callback, 10, 3 );
		$this->registered_filters[] = [ 'pre_http_request', $callback, 10 ];
	}

	/**
	 * 掛 pre_http_request，回傳 WP_Error（模擬連線失敗）
	 *
	 * @return void
	 */
	private function mock_http_error(): void {
		$callback = function ( $preempt, $args, $url ) { // phpcs:ignore
			++$this->http_call_count;
			return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		};

		\add_filter( 'pre_http_request', $callback, 10, 3 );
		$this->registered_filters[] = [ 'pre_http_request', $callback, 10 ];
	}

	/**
	 * 執行一次導回同步
	 *
	 * @param \WC_Order $order 訂單
	 * @return ReturnSyncResult
	 */
	private function sync( \WC_Order $order ): ReturnSyncResult {
		return ( new ReturnSyncManager( new RedirectGateway(), $order ) )->sync();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_ReturnSyncManager_可以被實例化(): void {
		$order   = $this->make_slp_order();
		$manager = new ReturnSyncManager( new RedirectGateway(), $order );

		$this->assertInstanceOf( ReturnSyncManager::class, $manager );
	}

	/**
	 * 接線測試：確認「電源真的有接上」
	 *
	 * 其餘測試都直接 new ReturnSyncManager()->sync()，驗的是核心邏輯；
	 * 本測試改走真實入口 RedirectGateway::before_order_received()（protected，以
	 * ReflectionMethod 呼叫，樣板見 MpgSandboxFixesTest::invoke_before_order_received），
	 * 確保有人刪掉或寫壞那段委派時測試會轉紅，而不是「核心全綠、生產退回 issue #18 原狀」。
	 *
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_before_order_received真的會委派給ReturnSyncManager(): void {
		// Given: 一筆 pending 的 SLP 訂單，客戶帶著合法 tradeOrderId + order_key 導回
		$order          = $this->make_slp_order();
		$trade_order_id = 'TRADE_WIRING_001';
		$this->simulate_return( $order, $trade_order_id );
		$this->mock_http_json( $this->make_payment_body( $trade_order_id, (string) $order->get_id() ) );

		// When: 走真實入口（不是直接 new ReturnSyncManager）
		$gateway = new RedirectGateway();
		$method  = new \ReflectionMethod( $gateway, 'before_order_received' );
		$method->setAccessible( true );
		$method->invoke( $gateway, $order );

		// Then: 真的打了查詢 API，且訂單被認列
		$this->assertSame( 1, $this->http_call_count, 'before_order_received 應觸發同步查詢' );
		$this->assert_order_status( $order, 'processing' );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( $trade_order_id, ( new MetaKeys( $fresh ) )->get_payment_identity() );
	}

	/**
	 * 接線測試（反向）：沒有 tradeOrderId 時真實入口不得打 API，也不得改變訂單
	 *
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_before_order_received在無tradeOrderId時不動作(): void {
		// Given: 客戶自行重訪 order-received 頁
		$order       = $this->make_slp_order();
		$_GET['key'] = $order->get_order_key();
		$this->mock_http_json( $this->make_payment_body( 'X', (string) $order->get_id() ) );

		// When: 走真實入口
		$gateway = new RedirectGateway();
		$method  = new \ReflectionMethod( $gateway, 'before_order_received' );
		$method->setAccessible( true );
		$method->invoke( $gateway, $order );

		// Then: 不打 API、訂單維持 pending（且不得拋出例外）
		$this->assertSame( 0, $this->http_call_count );
		$this->assert_order_status( $order, 'pending' );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_查詢回SUCCEEDED時當場認列付款(): void {
		// Given: 一筆 pending 的 SLP 訂單，客戶帶著 tradeOrderId 導回
		$order          = $this->make_slp_order();
		$trade_order_id = 'TRADE_SYNC_HAPPY_001';
		$this->simulate_return( $order, $trade_order_id );
		$this->mock_http_json( $this->make_payment_body( $trade_order_id, (string) $order->get_id() ) );

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 訂單當場轉為 processing
		$this->assertSame( ReturnSyncResult::UPDATED, $result );
		$this->assert_order_status( $order, 'processing' );

		// 並且寫入付款詳情、付款識別碼、transaction_id
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertNotEmpty( $this->get_payment_detail( $fresh ), '應寫入 _pc_payment_detail' );
		$this->assertSame( $trade_order_id, ( new MetaKeys( $fresh ) )->get_payment_identity() );
		$this->assertSame( $trade_order_id, $fresh->get_transaction_id() );
	}

	/**
	 * 冪等：已認列（processing）的訂單再次進入，不得再打 API
	 *
	 * @test
	 * @group happy
	 */
	public function test_已認列訂單再次導回時不呼叫API(): void {
		// Given: 一筆已 processing 的訂單
		$order = $this->make_slp_order( 'processing' );
		$this->simulate_return( $order, 'TRADE_SYNC_DONE_001' );
		$this->mock_http_json( $this->make_payment_body( 'TRADE_SYNC_DONE_001', (string) $order->get_id() ) );

		// When: 客戶重新整理 order-received 頁
		$result = $this->sync( $order );

		// Then: 直接跳過，API 呼叫次數為 0
		$this->assertSame( ReturnSyncResult::SKIPPED_NOT_PENDING, $result );
		$this->assertSame( 0, $this->http_call_count, '已認列訂單不得再打 API' );
		$this->assert_order_status( $order, 'processing' );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_API連線失敗時不拋出例外且維持pending(): void {
		// Given: SLP API 連線逾時
		$order = $this->make_slp_order();
		$this->simulate_return( $order, 'TRADE_SYNC_TIMEOUT' );
		$this->mock_http_error();

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 不 throw，回 API_FAILED，訂單維持 pending（改由 Webhook 認列）
		$this->assertSame( ReturnSyncResult::API_FAILED, $result );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_API回業務錯誤碼時不拋出例外且維持pending(): void {
		// Given: SLP 回業務錯誤碼
		$order = $this->make_slp_order();
		$this->simulate_return( $order, 'TRADE_SYNC_BIZ_ERROR' );
		$this->mock_http_json(
			[
				'code' => '4001',
				'msg'  => 'trade order not found',
			]
		);

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 不 throw，回 API_FAILED，訂單維持 pending
		$this->assertSame( ReturnSyncResult::API_FAILED, $result );
		$this->assert_order_status( $order, 'pending' );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * 節流：30 秒內第二次進入不得再打 API（避免客戶狂按重新整理造成 API 風暴）
	 *
	 * @test
	 * @group edge
	 */
	public function test_節流_30秒內第二次導回不再呼叫API(): void {
		// Given: 第一次查詢回 PENDING（訂單維持 pending，狀態閘門不會擋住第二次）
		$order          = $this->make_slp_order();
		$trade_order_id = 'TRADE_SYNC_THROTTLE';
		$this->simulate_return( $order, $trade_order_id );
		$this->mock_http_json( $this->make_payment_body( $trade_order_id, (string) $order->get_id(), 'PENDING' ) );
		$this->sync( $order );
		$this->assertSame( 1, $this->http_call_count );

		// When: 客戶立刻重新整理
		$result = $this->sync( $order );

		// Then: 被節流擋下，API 呼叫次數維持 1
		$this->assertSame( ReturnSyncResult::SKIPPED_THROTTLED, $result );
		$this->assertSame( 1, $this->http_call_count, '節流期間不得重複呼叫 API' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_查詢回PENDING時維持pending(): void {
		// Given: 查詢結果為非終態（例如 ATM 尚未繳費）
		$order          = $this->make_slp_order();
		$trade_order_id = 'TRADE_SYNC_PENDING';
		$this->simulate_return( $order, $trade_order_id );
		$this->mock_http_json( $this->make_payment_body( $trade_order_id, (string) $order->get_id(), 'PENDING' ) );

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 訂單維持 pending（由後續 webhook 補上，此為正確行為）
		$this->assertSame( ReturnSyncResult::UPDATED, $result );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_query_string無tradeOrderId時直接跳過(): void {
		// Given: 客戶自行重訪 order-received 頁（沒有 tradeOrderId）
		$order       = $this->make_slp_order();
		$_GET['key'] = $order->get_order_key();
		$this->mock_http_json( $this->make_payment_body( 'X', (string) $order->get_id() ) );

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 直接跳過，不打 API
		$this->assertSame( ReturnSyncResult::SKIPPED_NO_TRADE_ID, $result );
		$this->assertSame( 0, $this->http_call_count );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_tradeOrderId含非法字元時直接跳過(): void {
		// Given: tradeOrderId 被塞入 XSS 內容
		$order = $this->make_slp_order();
		$this->simulate_return( $order, '<script>alert(1)</script>' );
		$this->mock_http_json( $this->make_payment_body( 'X', (string) $order->get_id() ) );

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 白名單正則擋下，不打 API
		$this->assertSame( ReturnSyncResult::SKIPPED_NO_TRADE_ID, $result );
		$this->assertSame( 0, $this->http_call_count );
	}

	/**
	 * R6 迴歸：GetPaymentDTO 長度上限由 32 放寬至 64，
	 * 40 字元的 tradeOrderId 不得被 DTO 驗證擋下而無聲失敗
	 *
	 * @test
	 * @group edge
	 */
	public function test_tradeOrderId長度40字元仍可完成同步(): void {
		// Given: 一個 40 字元的 tradeOrderId
		$order          = $this->make_slp_order();
		$trade_order_id = str_repeat( 'A', 40 );
		$this->simulate_return( $order, $trade_order_id );
		$this->mock_http_json( $this->make_payment_body( $trade_order_id, (string) $order->get_id() ) );

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 正常完成（若仍是 32 字元上限，此處會退化為 API_FAILED）
		$this->assertSame( ReturnSyncResult::UPDATED, $result );
		$this->assertSame( 1, $this->http_call_count );
		$this->assert_order_status( $order, 'processing' );
	}

	// ========== 安全性（Security） ==========

	/**
	 * 第一道閘門：order_key 不符不得打 API、不得寫任何 meta
	 *
	 * @test
	 * @group security
	 */
	public function test_order_key不符時不打API也不寫meta(): void {
		// Given: 攻擊者用別人的 tradeOrderId 打自己不擁有的訂單
		$order = $this->make_slp_order();
		$this->simulate_return( $order, 'TRADE_SYNC_BAD_KEY', 'wc_order_forged_key' );
		$this->mock_http_json( $this->make_payment_body( 'TRADE_SYNC_BAD_KEY', (string) $order->get_id() ) );

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 閘門擋下
		$this->assertSame( ReturnSyncResult::SKIPPED_INVALID_KEY, $result );
		$this->assertSame( 0, $this->http_call_count, 'order_key 不符不得打 API' );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( '', ( new MetaKeys( $fresh ) )->get_payment_identity() );
		$this->assert_order_status( $order, 'pending' );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_order_key缺席時不打API(): void {
		// Given: query string 沒有 key
		$order                = $this->make_slp_order();
		$_GET['tradeOrderId'] = 'TRADE_SYNC_NO_KEY';
		$this->mock_http_json( $this->make_payment_body( 'TRADE_SYNC_NO_KEY', (string) $order->get_id() ) );

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 閘門擋下
		$this->assertSame( ReturnSyncResult::SKIPPED_INVALID_KEY, $result );
		$this->assertSame( 0, $this->http_call_count );
	}

	/**
	 * 第二道閘門：查詢結果的 referenceOrderId 必須等於本訂單 ID，
	 * 否則代表 tradeOrderId 屬於別人的付款（白嫖攻擊），不得認列也不得寫 identity
	 *
	 * @test
	 * @group security
	 */
	public function test_referenceOrderId指向別的訂單時拒絕認列(): void {
		// Given: 攻擊者把受害者的 tradeOrderId 帶到自己的訂單上
		$order           = $this->make_slp_order();
		$victim_order_id = $order->get_id() + 9999;
		$trade_order_id  = 'TRADE_SYNC_VICTIM';
		$this->simulate_return( $order, $trade_order_id );
		$this->mock_http_json( $this->make_payment_body( $trade_order_id, (string) $victim_order_id ) );

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 拒絕認列，且不得寫入 identity（避免污染 webhook 的查單主鍵）
		$this->assertSame( ReturnSyncResult::MISMATCHED_ORDER, $result );
		$this->assert_order_status( $order, 'pending' );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( '', ( new MetaKeys( $fresh ) )->get_payment_identity() );
		$this->assert_order_note_contains( $order, '不屬於本訂單' );
	}

	/**
	 * 第三道閘門（StatusManager 金額守衛）
	 *
	 * @test
	 * @group security
	 */
	public function test_查詢結果金額不符時拒絕認列(): void {
		// Given: 訂單應收 100 元，但查詢結果金額是 99 元
		$order          = $this->make_slp_order( 'pending', '100.00' );
		$trade_order_id = 'TRADE_SYNC_AMOUNT';
		$this->simulate_return( $order, $trade_order_id );
		$this->mock_http_json(
			$this->make_payment_body( $trade_order_id, (string) $order->get_id(), 'SUCCEEDED', 9900 )
		);

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 同步流程本身完成，但 StatusManager 的金額守衛擋下狀態變更
		$this->assertSame( ReturnSyncResult::UPDATED, $result );
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, '疑似竄改' );
	}

	/**
	 * 第三道閘門（StatusManager 幣別守衛）
	 *
	 * @test
	 * @group security
	 */
	public function test_查詢結果幣別不符時拒絕認列(): void {
		// Given: 一筆 USD 訂單，查詢結果卻是 TWD
		$order          = $this->make_slp_order( 'pending', '100.00', 'USD' );
		$trade_order_id = 'TRADE_SYNC_CURRENCY';
		$this->simulate_return( $order, $trade_order_id );
		$this->mock_http_json(
			$this->make_payment_body( $trade_order_id, (string) $order->get_id(), 'SUCCEEDED', 10000, 'TWD' )
		);

		// When: 導回同步查詢
		$result = $this->sync( $order );

		// Then: 幣別守衛擋下狀態變更
		$this->assertSame( ReturnSyncResult::UPDATED, $result );
		$this->assert_order_status( $order, 'pending' );
	}
}
