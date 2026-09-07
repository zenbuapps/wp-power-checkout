<?php
/**
 * SLP sessionId 寫入整合測試（issue #18 缺陷 D）
 *
 * 測試目標：
 *   J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway::before_process_payment
 *
 * 規格依據：
 *   - specs/open-issue/issue-18-plan.md §流程 4 §測試策略
 *
 * 背景：
 *   MetaKeys::update_identity() 過去全專案零呼叫，導致 _pc_identity 從未寫入，
 *   QuerySessionDTO / ApiClient::get_session() 形同死碼。
 *   本次於 create_session() 後立即補寫（且寫在 EXPIRED 判斷之前，
 *   讓被判逾期而取消的訂單也留下 sessionId 供客服追查）。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit --filter SlpSessionIdentityTest tests/Integration/Payment/"
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Session\QuerySessionDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * SLP sessionId 寫入測試類別
 *
 * @group integration
 * @group payment
 * @group shopline
 */
final class SlpSessionIdentityTest extends TestCase {

	/** @var array<int, array{0: string, 1: callable, 2: int}> 已掛的 filter / action，tear_down 時移除 */
	private array $registered_hooks = [];

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
				'signKey' => 'test_sign_key_for_session_identity',
			]
		);
	}

	/** 每次測試後移除 hook 並復原環境 */
	public function tear_down(): void {
		\putenv( 'API_MODE' );

		if ( null !== $this->block_http_callback ) {
			\remove_filter( 'pre_http_request', $this->block_http_callback, 99 );
			$this->block_http_callback = null;
		}

		foreach ( $this->registered_hooks as [ $tag, $callback, $priority ] ) {
			\remove_filter( $tag, $callback, $priority );
		}
		$this->registered_hooks = [];

		parent::tear_down();
	}

	// ========== Helper ==========

	/**
	 * 建立一筆 SLP pending 訂單
	 *
	 * CreateSessionDTO 會組出 customer / billing / client 等子 DTO，
	 * 缺 billing email / 姓名 / 地址會被子 DTO 的 validate 擋下，故此處補齊。
	 *
	 * @return \WC_Order
	 */
	private function make_slp_order(): \WC_Order {
		$order = \wc_create_order();
		$order->set_payment_method( RedirectGateway::ID );
		$order->set_currency( 'TWD' );
		$order->set_total( '100.00' );
		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
		$order->set_billing_email( 'slp-test@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->set_billing_country( 'TW' );
		$order->set_billing_state( 'TPE' );
		$order->set_billing_city( '台北市' );
		$order->set_billing_address_1( '信義路五段7號' );
		$order->set_billing_postcode( '110' );
		$order->set_customer_ip_address( '163.61.60.30' );
		$order->save();
		$order->update_status( 'pending' );
		$order->save();
		return $order;
	}

	/**
	 * 掛 pre_http_request 回傳 create_session 的假回應
	 *
	 * @param string $session_id sessionId
	 * @param string $status     ResponseStatus
	 * @param string $reference  referenceId
	 * @return void
	 */
	private function mock_create_session( string $session_id, string $status, string $reference ): void {
		$body = [
			'sessionId'   => $session_id,
			'referenceId' => $reference,
			'status'      => $status,
			'sessionUrl'  => 'https://checkout-sandbox.shoplinepayments.com/session/' . $session_id,
			'createTime'  => \time() * 1000,
			'amount'      => [
				'value'    => 10000,
				'currency' => 'TWD',
			],
		];

		$callback = static function ( $preempt, $args, $url ) use ( $body ) { // phpcs:ignore
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
		$this->registered_hooks[] = [ 'pre_http_request', $callback, 10 ];
	}

	/**
	 * 呼叫 protected 的 before_process_payment
	 *
	 * @param RedirectGateway $gateway 付款閘道
	 * @param \WC_Order       $order   訂單
	 * @return string sessionUrl
	 * @throws \Throwable 內部例外原樣拋出
	 */
	private function call_before_process_payment( RedirectGateway $gateway, \WC_Order $order ): string {
		$method = new \ReflectionMethod( RedirectGateway::class, 'before_process_payment' );
		$method->setAccessible( true );
		return (string) $method->invoke( $gateway, $order );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_建立session成功時寫入sessionId(): void {
		// Given: 一筆 pending 訂單，SLP 回傳一組 sessionId
		$order      = $this->make_slp_order();
		$session_id = 'SESSION_IDENTITY_001';
		$this->mock_create_session( $session_id, 'CREATED', (string) $order->get_id() );

		// When: 進入付款流程
		$session_url = $this->call_before_process_payment( new RedirectGateway(), $order );

		// Then: 回傳跳轉網址，且 _pc_identity = sessionId
		$this->assertStringContainsString( $session_id, $session_url );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame( $session_id, ( new MetaKeys( $fresh ) )->get_identity() );
	}

	/**
	 * sessionId 必須寫在 EXPIRED 判斷之前，
	 * 讓被判逾期而取消的訂單也留下 sessionId 供客服追查
	 *
	 * @test
	 * @group happy
	 */
	public function test_session回EXPIRED時仍寫入sessionId且訂單轉為cancelled(): void {
		// Given: 一筆 pending 訂單，SLP 回傳 EXPIRED
		$order      = $this->make_slp_order();
		$session_id = 'SESSION_IDENTITY_EXPIRED';
		$this->mock_create_session( $session_id, 'EXPIRED', (string) $order->get_id() );

		// EXPIRED 分支最後會 wp_safe_redirect + exit，在測試中無法執行到 exit；
		// 於狀態轉換 hook 中止流程，讓它停在「已寫 meta、已轉 cancelled」之後。
		// ⚠️ 必須拋 \Error 而非 \Exception：WC_Abstract_Order::status_transition()
		//    會 catch ( Exception $e ) 並只寫 log，\Exception 攔不住流程。
		$stopper = static function (): void {
			throw new \Error( 'EXPIRED_BRANCH_REACHED' );
		};
		\add_action( 'woocommerce_order_status_cancelled', $stopper, 10, 0 );
		$this->registered_hooks[] = [ 'woocommerce_order_status_cancelled', $stopper, 10 ];

		// When: 進入付款流程
		$reached = false;
		try {
			$this->call_before_process_payment( new RedirectGateway(), $order );
		} catch ( \Throwable $e ) {
			$reached = 'EXPIRED_BRANCH_REACHED' === $e->getMessage();
		}

		// Then: 走到 EXPIRED 分支，訂單轉為 cancelled，且 sessionId 已被寫入
		$this->assertTrue( $reached, '應走到 EXPIRED 分支的狀態轉換' );
		$this->assert_order_status( $order, 'cancelled' );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$this->assertSame(
			$session_id,
			( new MetaKeys( $fresh ) )->get_identity(),
			'sessionId 必須寫在 EXPIRED 判斷之前'
		);
	}

	/**
	 * 寫入後 QuerySessionDTO 才有資料可用（過去 update_identity 零呼叫，此路徑形同死碼）
	 *
	 * @test
	 * @group happy
	 */
	public function test_寫入sessionId後QuerySessionDTO可正常建立(): void {
		// Given: 一筆已寫入 sessionId 的訂單
		$order      = $this->make_slp_order();
		$session_id = 'SESSION_IDENTITY_QUERY';
		$this->mock_create_session( $session_id, 'CREATED', (string) $order->get_id() );
		$this->call_before_process_payment( new RedirectGateway(), $order );

		// When: 建立查詢 session 的請求參數
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$dto = QuerySessionDTO::create( $fresh );

		// Then: sessionId 正確帶入
		$this->assertSame( $session_id, $dto->sessionId );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * R11：升級前的既有訂單沒有 _pc_identity，
	 * QuerySessionDTO 的既有向後相容行為（丟明確例外）不變
	 *
	 * @test
	 * @group edge
	 */
	public function test_舊訂單無sessionId時QuerySessionDTO拋出明確例外(): void {
		// Given: 一筆沒有 _pc_identity 的訂單
		$order = $this->make_slp_order();

		// When & Then: 拋出含 Session ID not found 的例外
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Session ID not found' );
		QuerySessionDTO::create( $order );
	}
}
