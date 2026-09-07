<?php
/**
 * SLP Webhook 回應碼整合測試（issue #18 缺陷 B）
 *
 * 測試目標：
 *   J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\WebHook::post_webhook_callback
 *
 * 規格依據：
 *   - specs/open-issue/issue-18-plan.md §決策 4 §流程 2 §測試策略
 *
 * 回應碼判準（決策 4）：「同一份 payload 之後有沒有可能成功？」
 *   - 驗簽不符 → 401（商家可能只是 signKey 填錯，保留 SLP 重送機會）
 *   - 其餘（timestamp 超時 / DTO 解析失敗 / 找不到訂單 / 業務例外 / 正常完成）→ 200
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter SlpWebhookResponseTest tests/Integration/Payment/"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\WebHook;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * SLP Webhook 回應碼測試類別
 *
 * @group integration
 * @group payment
 * @group shopline
 */
final class SlpWebhookResponseTest extends TestCase {

	/** @var string 測試用 signKey */
	private const SIGN_KEY = 'test_sign_key_for_webhook_response';

	/** @var string|null 原始環境，tear_down 時還原 */
	private ?string $original_env = null;

	/**
	 * 封鎖所有 outbound HTTP 的 filter callback
	 *
	 * Webhook 處理路徑不該發出任何外部請求。封鎖它同時擋掉 WP 於 shutdown 觸發的
	 * wp-cron loopback 請求——該請求會由共用同一測試資料庫的 wp-env 測試站以另一條
	 * DB 連線處理，與 WP_UnitTestCase 的外層交易搶鎖造成 InnoDB deadlock，
	 * 進而讓外層交易被 rollback、後續測試連鎖失敗。
	 *
	 * @var callable|null
	 */
	private $block_http_callback = null;

	/** 每次測試前啟用 SLP、封鎖 outbound HTTP 並記錄原始環境 */
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
				'signKey' => self::SIGN_KEY,
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

	// ========== Helper ==========

	/**
	 * 建立一筆 SLP pending 訂單（帶 identity 與正確 total / currency）
	 *
	 * @param string $trade_order_id tradeOrderId
	 * @param string $total          訂單金額（元）
	 * @return \WC_Order
	 */
	private function make_slp_order( string $trade_order_id, string $total = '100.00' ): \WC_Order {
		$order = \wc_create_order();
		$order->set_payment_method( RedirectGateway::ID );
		$order->set_currency( 'TWD' );
		$order->set_total( $total );
		$order->save();
		$order->update_status( 'pending' );
		$order->save();

		( new MetaKeys( $order ) )->update_payment_identity( $trade_order_id );

		return $order;
	}

	/**
	 * 組出 trade.succeeded 的 webhook body
	 *
	 * @param string $trade_order_id     tradeOrderId
	 * @param string $reference_order_id referenceOrderId
	 * @param int    $amount_cents       金額（cents）
	 * @return array<string, mixed>
	 */
	private function make_webhook_body( string $trade_order_id, string $reference_order_id, int $amount_cents = 10000 ): array {
		return [
			'id'      => 'EVT_WEBHOOK_RESPONSE',
			'type'    => 'trade.succeeded',
			'created' => \time(),
			'data'    => [
				'referenceOrderId' => $reference_order_id,
				'tradeOrderId'     => $trade_order_id,
				'status'           => 'SUCCEEDED',
				'order'            => [
					'merchantId'       => 'MERCHANT_TEST',
					'referenceOrderId' => $reference_order_id,
					'createTime'       => \time(),
					'amount'           => [
						'value'    => $amount_cents,
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
						'value'    => $amount_cents,
						'currency' => 'TWD',
					],
				],
			],
		];
	}

	/**
	 * 組出 WP_REST_Request
	 *
	 * @param array<string, mixed> $body_array 通知內容
	 * @param string|null          $sign       簽章，null 代表以 SIGN_KEY 計算正確簽章
	 * @param int|null             $timestamp  毫秒時間戳，null 代表現在
	 * @return \WP_REST_Request<array<string, mixed>>
	 */
	private function make_request( array $body_array, ?string $sign = null, ?int $timestamp = null ): \WP_REST_Request {
		$json      = (string) \wp_json_encode( $body_array );
		$timestamp = $timestamp ?? ( \time() * 1000 );
		$sign      = $sign ?? \hash_hmac( 'sha256', "{$timestamp}.{$json}", self::SIGN_KEY );

		$request = new \WP_REST_Request( 'POST', '/power-checkout/slp/webhook' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'timestamp', (string) $timestamp );
		$request->set_header( 'sign', $sign );
		$request->set_body( $json );

		return $request;
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_合法通知回200且訂單轉為processing(): void {
		// Given: 一筆 pending 的 SLP 訂單（env=local 免驗簽）
		Plugin::$env    = 'local';
		$trade_order_id = 'TRADE_WH_OK_001';
		$order          = $this->make_slp_order( $trade_order_id );

		// When: 收到 trade.succeeded 通知
		$request  = $this->make_request( $this->make_webhook_body( $trade_order_id, (string) $order->get_id() ) );
		$response = WebHook::instance()->post_webhook_callback( $request );

		// Then: 回 200，訂單轉為 processing
		$this->assertSame( 200, $response->get_status() );
		$this->assert_order_status( $order, 'processing' );
	}

	/**
	 * 缺陷 C：客戶沒導回時 identity 從未寫入，必須靠 referenceOrderId 備援認列
	 *
	 * @test
	 * @group happy
	 */
	public function test_訂單無identity時仍可用referenceOrderId認列(): void {
		// Given: 一筆沒有 identity 的 SLP 訂單
		Plugin::$env = 'local';
		$order       = \wc_create_order();
		$order->set_payment_method( RedirectGateway::ID );
		$order->set_currency( 'TWD' );
		$order->set_total( '100.00' );
		$order->save();
		$order->update_status( 'pending' );
		$order->save();

		// When: 收到 trade.succeeded 通知
		$trade_order_id = 'TRADE_WH_NO_IDENTITY';
		$request        = $this->make_request( $this->make_webhook_body( $trade_order_id, (string) $order->get_id() ) );
		$response       = WebHook::instance()->post_webhook_callback( $request );

		// Then: 回 200，訂單轉為 processing，且回填 identity
		$this->assertSame( 200, $response->get_status() );
		$this->assert_order_status( $order, 'processing' );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( $trade_order_id, ( new MetaKeys( $fresh ) )->get_payment_identity() );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * 缺陷 B：找不到訂單原本回 500，造成 SLP 每 60 分鐘無限重試
	 *
	 * @test
	 * @group error
	 */
	public function test_查無訂單時回200而非500(): void {
		// Given: 通知指向一個不存在的訂單
		Plugin::$env = 'local';

		// When: 收到通知
		$request  = $this->make_request( $this->make_webhook_body( 'TRADE_WH_NOT_FOUND', '999999999' ) );
		$response = WebHook::instance()->post_webhook_callback( $request );

		// Then: 回 200（重送不會讓訂單長出來）
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_未知EventType時回200(): void {
		// Given: 不在 EventType 枚舉中的事件
		Plugin::$env = 'local';
		$body        = [
			'id'      => 'EVT_UNKNOWN',
			'type'    => 'unknown.event.type',
			'created' => \time(),
			'data'    => [],
		];

		// When: 收到通知
		$response = WebHook::instance()->post_webhook_callback( $this->make_request( $body ) );

		// Then: 回 200（EventType::from 拋 ValueError 被 catch）
		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_data缺必填欄位時回200(): void {
		// Given: data 缺少 order / payment 等必填欄位
		Plugin::$env = 'local';
		$body        = [
			'id'      => 'EVT_INCOMPLETE',
			'type'    => 'trade.succeeded',
			'created' => \time(),
			'data'    => [
				'referenceOrderId' => 'REF_X',
				'tradeOrderId'     => 'TRADE_X',
				'status'           => 'SUCCEEDED',
			],
		];

		// When: 收到通知
		$response = WebHook::instance()->post_webhook_callback( $this->make_request( $body ) );

		// Then: 回 200（DTO 解析失敗，重送無意義）
		$this->assertSame( 200, $response->get_status() );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * timestamp 超時：同一份 payload 重送幾次都不會通過（時間只會更久），回 200 止血
	 *
	 * @test
	 * @group edge
	 */
	public function test_timestamp超時時回200且訂單維持pending(): void {
		// Given: 一筆 pending 訂單，通知的時間戳是 10 分鐘前
		Plugin::$env    = 'production';
		$trade_order_id = 'TRADE_WH_STALE';
		$order          = $this->make_slp_order( $trade_order_id );

		$stale_timestamp = ( \time() - 10 * 60 ) * 1000;
		$request         = $this->make_request(
			$this->make_webhook_body( $trade_order_id, (string) $order->get_id() ),
			null,
			$stale_timestamp
		);

		// When: 收到通知
		$response = WebHook::instance()->post_webhook_callback( $request );

		// Then: 回 200，訂單維持 pending
		$this->assertSame( 200, $response->get_status() );
		$this->assert_order_status( $order, 'pending' );
	}

	// ========== 安全性（Security） ==========

	/**
	 * 驗簽不符回 401：極可能是商家 signKey 設定錯誤，
	 * 回 200 會讓 SLP 永久放棄這筆通知，商家改好設定也救不回來
	 *
	 * @test
	 * @group security
	 */
	public function test_簽章不符時回401且不推進訂單狀態(): void {
		// Given: 一筆 pending 訂單，通知帶偽造簽章
		Plugin::$env    = 'production';
		$trade_order_id = 'TRADE_WH_BAD_SIGN';
		$order          = $this->make_slp_order( $trade_order_id );

		$request = $this->make_request(
			$this->make_webhook_body( $trade_order_id, (string) $order->get_id() ),
			'forged_signature'
		);

		// When: 收到通知
		$response = WebHook::instance()->post_webhook_callback( $request );

		// Then: 回 401，訂單維持 pending，且不寫入付款明細
		$this->assertSame( 401, $response->get_status() );
		$this->assert_order_status( $order, 'pending' );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertEmpty( $this->get_payment_detail( $fresh ), '驗簽不符不得寫入付款明細' );
	}

	/**
	 * 回應內容不得洩漏計算出來的正確簽章
	 *
	 * @test
	 * @group security
	 */
	public function test_401回應不洩漏簽章資訊(): void {
		// Given: 一筆 pending 訂單，通知帶偽造簽章
		Plugin::$env    = 'production';
		$trade_order_id = 'TRADE_WH_BAD_SIGN_2';
		$order          = $this->make_slp_order( $trade_order_id );

		$request = $this->make_request(
			$this->make_webhook_body( $trade_order_id, (string) $order->get_id() ),
			'forged_signature_2'
		);

		// When: 收到通知
		$response = WebHook::instance()->post_webhook_callback( $request );

		// Then: 回應內容不含 calculated / sign 等資訊
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 'Invalid signature', $data['message'] ?? '' );
	}

	/**
	 * 合法簽章但金額被竄改 → 200（不讓 SLP 重試），但守衛擋下狀態變更
	 *
	 * @test
	 * @group security
	 */
	public function test_簽章合法但金額被竄改時回200且訂單維持pending(): void {
		// Given: 訂單應收 100 元，通知金額被竄改為 1 元
		Plugin::$env    = 'production';
		$trade_order_id = 'TRADE_WH_TAMPER';
		$order          = $this->make_slp_order( $trade_order_id, '100.00' );

		$request = $this->make_request(
			$this->make_webhook_body( $trade_order_id, (string) $order->get_id(), 100 )
		);

		// When: 收到通知（簽章正確）
		$response = WebHook::instance()->post_webhook_callback( $request );

		// Then: 回 200，但訂單維持 pending（守衛在 StatusManager）
		$this->assertSame( 200, $response->get_status() );
		$this->assert_order_status( $order, 'pending' );
		$this->assert_order_note_contains( $order, '疑似竄改' );
	}
}
