<?php
/**
 * SLP Webhook 查單整合測試（issue #18 缺陷 C）
 *
 * 測試目標：
 *   J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\OrderResolver
 *
 * 規格依據：
 *   - specs/open-issue/issue-18-plan.md §流程 2 §測試策略
 *
 * 涵蓋範疇：
 *   - identity 優先 → referenceOrderId 備援 → 皆未命中回 null（不 throw）
 *   - 備援命中時回填 _pc_payment_identity
 *   - 資安：gateway 驗證（不得誤配他金流訂單）、identity 汙染時的一致性複驗
 *   - 舊單救援迴歸：修復上線後，SLP 對舊單的下一次重試必須能靠 referenceOrderId 認列
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter SlpOrderResolverTest tests/Integration/Payment/"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\WebHook;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\OrderResolver;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * SLP Webhook 查單測試類別
 *
 * @group integration
 * @group payment
 * @group shopline
 */
final class SlpOrderResolverTest extends TestCase {

	/** @var string|null 原始環境，tear_down 時還原 */
	private ?string $original_env = null;

	/**
	 * 封鎖所有 outbound HTTP 的 filter callback
	 *
	 * 本測試類別的所有路徑都不該發出任何外部請求。封鎖它有兩個作用：
	 *  1. 斷言「查單 / 認列不打外部 API」；
	 *  2. 擋掉 WP 於 shutdown 觸發的 wp-cron loopback 請求——該請求會由共用同一測試資料庫的
	 *     wp-env 測試站以另一條 DB 連線處理，與 WP_UnitTestCase 包覆測試的外層交易搶鎖，
	 *     造成 InnoDB deadlock；deadlock 會把外層交易整個 rollback，令後續測試連鎖失敗。
	 *
	 * @var callable|null
	 */
	private $block_http_callback = null;

	/** 每次測試前記錄原始環境、封鎖 outbound HTTP 並啟用 SLP */
	protected function configure_dependencies(): void {
		$this->original_env = Plugin::$env;

		$this->block_http_callback = static fn() => new \WP_Error(
			'http_request_blocked',
			'測試中不允許 outbound HTTP'
		);
		\add_filter( 'pre_http_request', $this->block_http_callback, 1, 3 );

		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'enabled' => 'yes',
				'mode'    => 'test',
				'signKey' => 'test_sign_key_for_order_resolver',
			]
		);
	}

	/** 每次測試後還原環境與 filter，避免污染其他測試 */
	public function tear_down(): void {
		if ( null !== $this->block_http_callback ) {
			\remove_filter( 'pre_http_request', $this->block_http_callback, 1 );
			$this->block_http_callback = null;
		}

		if ( null !== $this->original_env ) {
			Plugin::$env = $this->original_env;
		}

		parent::tear_down();
	}

	/**
	 * 建立一筆指定付款方式的訂單
	 *
	 * @param string      $payment_method 付款方式 ID
	 * @param string|null $trade_order_id 要寫入 _pc_payment_identity 的值，null 代表不寫
	 * @param string|null $status         訂單狀態，null 代表不變更
	 * @return \WC_Order
	 */
	private function make_order(
		string $payment_method = RedirectGateway::ID,
		?string $trade_order_id = null,
		?string $status = null
	): \WC_Order {
		$order = \wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->set_currency( 'TWD' );
		$order->set_total( '100.00' );
		$order->save();

		if ( null !== $status ) {
			$order->update_status( $status );
			$order->save();
		}

		if ( null !== $trade_order_id ) {
			( new MetaKeys( $order ) )->update_payment_identity( $trade_order_id );
		}

		return $order;
	}

	/**
	 * 送一則 trade webhook 進 callback（env=local，免驗簽）
	 *
	 * @param string $trade_order_id     tradeOrderId
	 * @param string $reference_order_id referenceOrderId（= WC order id）
	 * @param string $status             ResponseStatus（SUCCEEDED / FAILED …）
	 * @return \WP_REST_Response
	 */
	private function post_trade_webhook( string $trade_order_id, string $reference_order_id, string $status ): \WP_REST_Response {
		Plugin::$env = 'local';

		$body = [
			'id'      => 'EVT_RESCUE_' . $trade_order_id,
			'type'    => 'SUCCEEDED' === $status ? 'trade.succeeded' : 'trade.failed',
			'created' => \time(),
			'data'    => [
				'referenceOrderId' => $reference_order_id,
				'tradeOrderId'     => $trade_order_id,
				'status'           => $status,
				'order'            => [
					'merchantId'       => 'MERCHANT_TEST',
					'referenceOrderId' => $reference_order_id,
					'createTime'       => \time(),
					'amount'           => [
						'value'    => 10000,
						'currency' => 'TWD',
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
						'value'    => 10000,
						'currency' => 'TWD',
					],
				],
			],
		];

		$request = new \WP_REST_Request( 'POST', '/power-checkout/slp/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'timestamp', (string) ( \time() * 1000 ) );
		$request->set_header( 'sign', 'not_checked_in_local_env' );
		$request->set_body( (string) \wp_json_encode( $body ) );

		return WebHook::instance()->post_webhook_callback( $request );
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * 皆未命中時回 null 而非 throw（缺陷 C 修復前，webhook 會因此回 500 造成 SLP 無限重試）
	 *
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_皆未命中時回null不拋出例外(): void {
		$order = OrderResolver::resolve( 'TRADE_NOT_EXIST', '' );

		$this->assertNull( $order );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_identity命中時回傳正確訂單(): void {
		// Given: 一筆已寫入 identity 的 SLP 訂單
		$trade_order_id = 'TRADE_RESOLVE_IDENTITY_001';
		$order          = $this->make_order( RedirectGateway::ID, $trade_order_id );

		// When: 以 identity 查單
		$resolved = OrderResolver::resolve( $trade_order_id, (string) $order->get_id() );

		// Then: 回傳正確訂單
		$this->assertInstanceOf( \WC_Order::class, $resolved );
		$this->assertSame( $order->get_id(), $resolved->get_id() );
	}

	/**
	 * 缺陷 C 的核心：客戶沒導回 → identity 從未寫入 → 必須靠 referenceOrderId 備援找到訂單
	 *
	 * @test
	 * @group happy
	 */
	public function test_identity未命中時以referenceOrderId備援並回填identity(): void {
		// Given: 一筆沒有 identity 的 SLP 訂單（客戶付款後從未導回）
		$order          = $this->make_order();
		$trade_order_id = 'TRADE_RESOLVE_FALLBACK_001';

		// When: webhook 帶著 referenceOrderId 進來
		$resolved = OrderResolver::resolve( $trade_order_id, (string) $order->get_id() );

		// Then: 找得到訂單
		$this->assertInstanceOf( \WC_Order::class, $resolved );
		$this->assertSame( $order->get_id(), $resolved->get_id() );

		// 並且回填 identity，讓後續通知能走較快的 identity 路徑
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( $trade_order_id, ( new MetaKeys( $fresh ) )->get_payment_identity() );
	}

	// ========== 舊單救援迴歸（生產站既有 pending 訂單） ==========
	// 情境：修復前有一批訂單客戶已付款但當初沒導回 → _pc_payment_identity 為空 →
	//      webhook 至今查無訂單、被 SLP 每 60 分鐘重試中。
	//      修復上線後，SLP 的下一次重試必須能靠 referenceOrderId 備援查到訂單並認列。

	/**
	 * (a) 舊單救援：SLP 重試帶 referenceOrderId → 查到訂單 → 認列 → 回填 identity
	 *
	 * @test
	 * @group happy
	 */
	public function test_舊單救援_無identity的pending舊單收到重試通知時被認列(): void {
		// Given: 一筆客戶已付款但從未導回的舊單（_pc_payment_identity 為空、狀態 pending）
		$order = $this->make_order( RedirectGateway::ID, null, 'pending' );
		$this->assertSame( '', ( new MetaKeys( $order ) )->get_payment_identity(), '前提：舊單沒有 identity' );

		// When: SLP 重送付款成功通知（帶 referenceOrderId）
		$trade_order_id = 'TRADE_RESCUE_OLD_001';
		$response       = $this->post_trade_webhook( $trade_order_id, (string) $order->get_id(), 'SUCCEEDED' );

		// Then: 回 200，舊單被自動救回為 processing
		$this->assertSame( 200, $response->get_status() );
		$this->assert_order_status( $order, 'processing' );

		// 並且 identity 被回填，後續通知可走較快的 identity 路徑
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( $trade_order_id, ( new MetaKeys( $fresh ) )->get_payment_identity() );
		$this->assertNotEmpty( $this->get_payment_detail( $fresh ), '應寫入付款明細' );
	}

	/**
	 * (b) 舊單裡有大量「真失敗」的拒刷單也在重試，絕不可被誤認列為已付款
	 *
	 * @test
	 * @group edge
	 */
	public function test_舊單救援_FAILED通知不得將舊單轉為processing(): void {
		// Given: 一筆沒有 identity 的 pending 舊單
		$order = $this->make_order( RedirectGateway::ID, null, 'pending' );

		// When: SLP 重送的是付款「失敗」通知（拒刷）
		$response = $this->post_trade_webhook( 'TRADE_RESCUE_FAILED_001', (string) $order->get_id(), 'FAILED' );

		// Then: 回 200，但訂單絕不可轉為 processing
		$this->assertSame( 200, $response->get_status() );
		$this->assert_order_status( $order, 'pending' );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertNotSame( 'processing', $fresh->get_status(), 'FAILED 通知不得誤認列' );
		$this->assertSame( '', $fresh->get_transaction_id(), 'FAILED 不得寫入 transaction_id' );
	}

	/**
	 * (c) 舊單已被商家人工處理成終態時，不得靜默跳過——必須留下人工確認的 order note
	 *
	 * 「客戶付了錢但商家已手動取消 / 已退款」這種情況若靜默跳過會無人察覺。
	 *
	 * @test
	 * @group edge
	 */
	public function test_舊單救援_終態舊單收到遲到通知時留下人工確認note(): void {
		foreach ( [ 'completed', 'cancelled', 'refunded' ] as $index => $terminal_status ) {
			// Given: 一筆已被商家人工處理為終態的舊單
			$order = $this->make_order( RedirectGateway::ID, null, $terminal_status );

			// When: SLP 重送遲到的付款成功通知
			$response = $this->post_trade_webhook(
				"TRADE_RESCUE_TERMINAL_{$index}",
				(string) $order->get_id(),
				'SUCCEEDED'
			);

			// Then: 回 200，狀態不得被「復活」
			$this->assertSame( 200, $response->get_status() );
			$this->assert_order_status( $order, $terminal_status );

			// 且必須留下明確的人工確認告警（不可靜默跳過）
			$this->assert_order_note_contains( $order, '收到遲到的付款通知' );
			$this->assert_order_note_contains( $order, '請人工確認' );
			$this->assert_order_note_contains( $order, $terminal_status );

			// 也不得寫入付款明細（未認列）
			$fresh = \wc_get_order( $order->get_id() );
			$this->assertInstanceOf( \WC_Order::class, $fresh );
			$this->assertEmpty( $this->get_payment_detail( $fresh ), "終態（{$terminal_status}）不得寫入付款明細" );
		}
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_兩者皆未命中時回null(): void {
		// Given: 一個不存在的訂單 ID
		$resolved = OrderResolver::resolve( 'TRADE_NONE', '999999999' );

		// Then: 回 null（webhook 會據此回 200，而非 500 讓 SLP 無限重試）
		$this->assertNull( $resolved );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_referenceOrderId為非數字字串時回null不拋出例外(): void {
		// Given: referenceOrderId 是非數字字串
		$resolved = OrderResolver::resolve( 'TRADE_NONE_2', 'not-a-number' );

		// Then: 安全回 null
		$this->assertNull( $resolved );
	}

	/**
	 * R11：升級前的既有訂單沒有 identity，也不得因此拒絕處理 webhook
	 *
	 * @test
	 * @group edge
	 */
	public function test_tradeOrderId為空字串時仍可用referenceOrderId查單(): void {
		// Given: 一筆沒有 identity 的 SLP 訂單
		$order = $this->make_order();

		// When: tradeOrderId 為空
		$resolved = OrderResolver::resolve( '', (string) $order->get_id() );

		// Then: 仍能以 referenceOrderId 找到訂單，且不會寫入空的 identity
		$this->assertInstanceOf( \WC_Order::class, $resolved );
		$this->assertSame( $order->get_id(), $resolved->get_id() );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( '', ( new MetaKeys( $fresh ) )->get_payment_identity() );
	}

	// ========== 安全性（Security） ==========

	/**
	 * gateway 驗證：referenceOrderId 命中但訂單不是 SLP 付款 → 不得誤配
	 *
	 * @test
	 * @group security
	 */
	public function test_referenceOrderId命中但付款方式非SLP時回null(): void {
		// Given: 一筆以其他金流結帳的訂單
		$order = $this->make_order( 'ecpay_aio' );

		// When: SLP webhook 以 referenceOrderId 查單
		$resolved = OrderResolver::resolve( 'TRADE_WRONG_GATEWAY', (string) $order->get_id() );

		// Then: 拒絕誤配
		$this->assertNull( $resolved );
	}

	/**
	 * gateway 驗證：identity 命中但訂單不是 SLP 付款 → 不得誤配
	 *
	 * @test
	 * @group security
	 */
	public function test_identity命中但付款方式非SLP時回null(): void {
		// Given: 一筆非 SLP 訂單卻帶有 SLP 的 identity meta
		$trade_order_id = 'TRADE_WRONG_GATEWAY_IDENTITY';
		$order          = $this->make_order( 'paynow', $trade_order_id );

		// When: 以 identity 查單
		$resolved = OrderResolver::resolve( $trade_order_id, (string) $order->get_id() );

		// Then: 拒絕誤配
		$this->assertNull( $resolved );
	}

	/**
	 * FM-4：攻擊者把受害者的 tradeOrderId 寫進自己的訂單 meta，
	 * 一致性複驗必須讓通知落到 referenceOrderId 指向的真正訂單
	 *
	 * @test
	 * @group security
	 */
	public function test_identity命中但referenceOrderId指向另一訂單時以referenceOrderId為準(): void {
		// Given: 攻擊者訂單被汙染了受害者的 tradeOrderId
		$trade_order_id = 'TRADE_POISONED_001';
		$attacker_order = $this->make_order( RedirectGateway::ID, $trade_order_id );
		$victim_order   = $this->make_order();

		// When: 受害者付款的 webhook 進來（referenceOrderId 指向受害者訂單）
		$resolved = OrderResolver::resolve( $trade_order_id, (string) $victim_order->get_id() );

		// Then: 回傳受害者訂單，攻擊者訂單不得被認列
		$this->assertInstanceOf( \WC_Order::class, $resolved );
		$this->assertSame( $victim_order->get_id(), $resolved->get_id() );
		$this->assertNotSame( $attacker_order->get_id(), $resolved->get_id() );
	}
}
