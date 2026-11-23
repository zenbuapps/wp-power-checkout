<?php
/**
 * SLP 嵌入式兩段式 dispatch 測試（P1；經 P0 PaymentDispatchApiService REST 端點）
 *
 * POST power-checkout/v1/pay/{order_id}/dispatch
 *  - init（無 pay_session）：回 SDK 初始化資料（clientKey / env / amount=round(total*100) / currency），不打 API
 *  - confirm（有 pay_session）：打 /trade/payment/create（acquirerType=SDK、paySession 原樣、金額後端算），
 *    回 next_action（原樣 JSON 字串，R6 不解析），寫 _pc_payment_identity
 *  - 30 秒 transient 鎖防雙擊（409）；API 失敗 / 無 nextAction → 500 通用訊息 + order note、鎖釋放
 *  - pay_session 非字串 / 超過 10KB → 400
 *  - referenceOrderId 每次嘗試唯一（{order_id}-{attempt}-{6 hex}；P8 fix #1，見 SlpEmbeddedRetryTest）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter SlpEmbeddedDispatch --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Payment\Doubles\SlpEmbeddedTestTrait;
use Tests\Integration\TestCase;

/**
 * SLP 嵌入式 dispatch 測試
 *
 * @group integration
 * @group payment
 * @group shopline_payment_embedded
 */
final class SlpEmbeddedDispatchTest extends TestCase {
	use SlpEmbeddedTestTrait;

	/** @var string 測試用 paySession（SDK 產物，後端不解析） */
	private const PAY_SESSION = '{"sdkVersion":"1.0.0","payload":"opaque-pay-session-AbC123=="}';

	/** @var string 建單成功回應（nextAction 含空物件，用來驗證原樣轉傳不被轉成陣列） */
	private const CREATE_OK_JSON = '{"acquirerType":"SDK","referenceOrderId":"%d","tradeOrderId":"TRADE_EMB_CREATE_%d","status":"CREATED","subStatus":"","actionType":"SDK","nextAction":{"type":"3DS","url":"https:\/\/sandbox.example\/3ds","params":{},"list":[]},"order":{},"payment":{}}';

	/** 每個測試前：TWD、導轉式 test 模式（內建 sandbox 憑證）、嵌入式啟用並放進 ProviderUtils 容器 */
	protected function configure_dependencies(): void {
		$this->setup_slp_embedded();
		ProviderUtils::$container[ EmbeddedGateway::ID ] = new EmbeddedGateway();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_slp_embedded_http();
		parent::tear_down();
	}

	/**
	 * 送出 dispatch
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $extra 額外 body
	 * @return \WP_REST_Response
	 */
	private function dispatch( \WC_Order $order, array $extra = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', "/power-checkout/v1/pay/{$order->get_id()}/dispatch" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( \array_merge( [ 'order_key' => $order->get_order_key() ], $extra ) ) );
		$response = \rest_do_request( $request );
		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		return $response;
	}

	/**
	 * 建單成功假回應
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	private function fake_create_ok( \WC_Order $order ): void {
		$this->fake_slp_http(
			'/trade/payment/create',
			$this->slp_raw_json_response( \sprintf( self::CREATE_OK_JSON, $order->get_id(), $order->get_id() ) )
		);
	}

	/**
	 * 取得第一筆打到 /trade/payment/create 的 request body
	 *
	 * @return array<string, mixed>
	 */
	private function create_request_body(): array {
		foreach ( $this->slp_api_requests() as $req ) {
			if ( \str_contains( $req['url'], '/trade/payment/create' ) ) {
				$body = \json_decode( $req['body'], true );
				return \is_array( $body ) ? $body : [];
			}
		}
		$this->fail( '沒有打到 /trade/payment/create' );
	}

	/** @return array<int, string> 訂單 note 內容 */
	private function notes( \WC_Order $order ): array {
		return \array_map(
			static fn( $note ): string => (string) $note->content,
			\wc_get_order_notes( [ 'order_id' => $order->get_id() ] )
		);
	}

	/**
	 * Happy：init 回 SDK 初始化資料、不打 API
	 *
	 * @group happy
	 */
	public function test_init回SDK初始化資料且不打API(): void {
		$this->block_slp_http();
		$order = $this->make_slp_embedded_order( 1234.5 );

		$response = $this->dispatch( $order );
		$data     = $response->get_data()['data'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'client-sdk', $data['mode'] );
		$this->assertSame( EmbeddedGateway::ID, $data['provider'] );
		$this->assertSame( 'init', $data['phase'] );
		$this->assertNotSame( '', $data['sdk']['clientKey'] );
		$this->assertNotSame( '', $data['sdk']['merchantId'] );
		$this->assertSame( 'sandbox', $data['sdk']['env'] );
		$this->assertSame( 123450, $data['sdk']['amount'] );
		$this->assertSame( 'TWD', $data['sdk']['currency'] );
		$this->assertSame( 'CreditCard', $data['sdk']['paymentMethod'] );
		$this->assertArrayNotHasKey( 'apiKey', $data['sdk'] );
		$this->assertSame( [], $this->slp_api_requests() );
	}

	/**
	 * Happy：confirm 打 trade/payment/create，body 正確；回 next_action 原樣 JSON 字串；寫 _pc_payment_identity
	 *
	 * @group happy
	 */
	public function test_confirm建立交易並回傳next_action(): void {
		$order = $this->make_slp_embedded_order( 1000.0 );
		$this->fake_create_ok( $order );

		$response = $this->dispatch( $order, [ 'pay_session' => self::PAY_SESSION ] );
		$data     = $response->get_data()['data'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'confirm', $data['phase'] );
		$this->assertIsString( $data['next_action'] );
		$this->assertSame(
			'{"type":"3DS","url":"https:\/\/sandbox.example\/3ds","params":{},"list":[]}',
			$data['next_action'],
			'nextAction 必須原樣轉傳（空物件不可變成陣列）'
		);

		$body = $this->create_request_body();
		$this->assertSame( 'SDK', $body['acquirerType'] );
		$this->assertSame( self::PAY_SESSION, $body['paySession'] );
		// P8 fix #1：每次嘗試唯一 reference（{order_id}-{attempt}-{6 hex}），SLP 拒絕重複 referenceOrderId（1001）
		$this->assertMatchesRegularExpression( '/^' . $order->get_id() . '-1-[0-9A-F]{6}$/', $body['referenceOrderId'] );
		$this->assertSame(
			[
				'value'    => 100000,
				'currency' => 'TWD',
			],
			$body['amount']
		);
		$this->assertSame( 'CreditCard', $body['confirm']['paymentMethod'] );
		$this->assertSame( 'Regular', $body['confirm']['paymentBehavior'] );
		$this->assertSame( ( new EmbeddedGateway() )->get_return_url( $order ), $body['returnUrl'] );
		foreach ( [ 'order', 'billing', 'customer', 'client', 'language' ] as $key ) {
			$this->assertArrayHasKey( $key, $body, "trade/payment/create 必填欄位 {$key}" );
		}

		$this->assertSame(
			"TRADE_EMB_CREATE_{$order->get_id()}",
			( new MetaKeys( \wc_get_order( $order->get_id() ) ) )->get_payment_identity()
		);
	}

	/**
	 * Security：前端送 amount 被忽略，金額一律後端以訂單計算
	 *
	 * @group security
	 */
	public function test_前端送amount被忽略(): void {
		$order = $this->make_slp_embedded_order( 1000.0 );
		$this->fake_create_ok( $order );

		$this->dispatch(
			$order,
			[
				'pay_session' => self::PAY_SESSION,
				'amount'      => 1,
			]
		);

		$this->assertSame( 100000, $this->create_request_body()['amount']['value'] );
	}

	/**
	 * Edge：30 秒內第二次 confirm → 409，且只打一次 API
	 *
	 * @group edge
	 */
	public function test_30秒內重複confirm回409(): void {
		$order = $this->make_slp_embedded_order();
		$this->fake_create_ok( $order );

		$first  = $this->dispatch( $order, [ 'pay_session' => self::PAY_SESSION ] );
		$second = $this->dispatch( $order, [ 'pay_session' => self::PAY_SESSION ] );

		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'CONFLICT', $second->get_data()['error_code'] );
		$this->assertCount( 1, $this->slp_api_requests() );
	}

	/**
	 * Error：API 回應無 nextAction → 500 通用訊息 + order note，不寫 identity
	 *
	 * @group error
	 */
	public function test_無nextAction回500且寫order_note(): void {
		$order = $this->make_slp_embedded_order();
		$this->fake_slp_http(
			'/trade/payment/create',
			$this->slp_json_response(
				[
					'acquirerType'     => 'SDK',
					'referenceOrderId' => (string) $order->get_id(),
					'tradeOrderId'     => 'TRADE_EMB_NO_ACTION',
					'status'           => 'FAILED',
					'paymentMsg'       => [
						'code' => '1004',
						'msg'  => '建單失敗（測試）',
					],
				]
			)
		);

		$response = $this->dispatch( $order, [ 'pay_session' => self::PAY_SESSION ] );

		$this->assertSame( 500, $response->get_status() );
		$this->assertSame( 'UNKNOWN', $response->get_data()['error_code'] );
		$this->assertStringNotContainsString( '建單失敗（測試）', (string) $response->get_data()['message'], '不得外洩金流訊息' );
		$this->assertSame( '', ( new MetaKeys( \wc_get_order( $order->get_id() ) ) )->get_payment_identity() );
		$this->assertNotEmpty(
			\array_filter( $this->notes( $order ), static fn( string $n ): bool => \str_contains( $n, 'nextAction' ) ),
			'應寫入無 nextAction 的 order note'
		);
	}

	/**
	 * Error：API 逾時 → 500，且鎖被釋放（可立即重試）
	 *
	 * @group error
	 */
	public function test_API逾時回500且鎖釋放(): void {
		$order = $this->make_slp_embedded_order();
		$this->fake_slp_http( '/trade/payment/create', new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$response = $this->dispatch( $order, [ 'pay_session' => self::PAY_SESSION ] );

		$this->assertSame( 500, $response->get_status() );
		$this->assertFalse( \get_transient( EmbeddedGateway::LOCK_PREFIX . $order->get_id() ) );

		// 鎖已釋放 → 立即重試會再打一次 API（而非 409）
		$retry = $this->dispatch( $order, [ 'pay_session' => self::PAY_SESSION ] );
		$this->assertSame( 500, $retry->get_status() );
		$this->assertCount( 2, $this->slp_api_requests() );
	}

	/**
	 * Security：pay_session 超過 10KB → 400，不打 API
	 *
	 * @group security
	 */
	public function test_pay_session超長回400(): void {
		$this->block_slp_http();
		$order = $this->make_slp_embedded_order();

		$response = $this->dispatch( $order, [ 'pay_session' => \str_repeat( 'A', 10241 ) ] );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'VALIDATION', $response->get_data()['error_code'] );
		$this->assertSame( [], $this->slp_api_requests() );
	}

	/**
	 * Security：pay_session 非字串 / 空字串 → 400，不打 API
	 *
	 * @group security
	 */
	public function test_pay_session非字串回400(): void {
		$this->block_slp_http();
		$order = $this->make_slp_embedded_order();

		$array_session = $this->dispatch( $order, [ 'pay_session' => [ 'nested' => 'x' ] ] );
		$empty_session = $this->dispatch( $order, [ 'pay_session' => '' ] );

		$this->assertSame( 400, $array_session->get_status() );
		$this->assertSame( 400, $empty_session->get_status() );
		$this->assertSame( [], $this->slp_api_requests() );
	}

	/**
	 * Security：過期分頁對已付款訂單再 dispatch → 409，不打 API
	 *
	 * @group security
	 */
	public function test_已付款訂單dispatch回409(): void {
		$this->block_slp_http();
		$order = $this->make_slp_embedded_order( 1000.0, 'processing' );

		$response = $this->dispatch( $order, [ 'pay_session' => self::PAY_SESSION ] );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( [], $this->slp_api_requests() );
	}
}
