<?php

declare( strict_types=1 );

namespace Tests\Integration\Payment\Doubles;

use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayChecksum;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/**
 * 黑貓PAY（tcat_pay）測試共用 helper
 *
 * - HTTP：pre_http_request 攔截。`/Token` 回 token fixture；`/api/Collect` 依 body 的 cmd 分派到佇列
 *   （佇列取完後重複最後一筆）；未設定的請求一律回 WP_Error（測試不連外）。
 * - 回應元素：array（HTTP 200 + JSON）／ `['__http' => int, 'body' => array|string]`／\WP_Error／
 *   callable(array $body): mixed（回傳上述任一形態）。
 * - golden 回應取自 tests/Integration/Payment/Fixtures/tcat_pay/*.json（`__TRADE_NO__` 由呼叫端替換）。
 * - 憑證全為測試假值，與真實 sandbox 無關。
 *
 * 使用端須在 tear_down 呼叫 reset_tcat_pay_http()。
 */
trait TcatPayTestTrait {

	/** @var string 測試 hash_base（COCS 導轉 chk 金鑰，假值） */
	protected static string $tcat_hash_base = 'HB_TEST_FAKE_0001';

	/** @var string 測試 API 密碼（假值；用於斷言不外洩） */
	protected static string $tcat_api_password = 'pw_SENTINEL_never_log';

	/** @var string 測試 cust_id（假值） */
	protected static string $tcat_cust_id = 'TESTCUST01';

	/** @var array<int, mixed> /Token 回應佇列 */
	protected array $tcat_token_queue = [];

	/** @var array<string, array<int, mixed>> cmd => 回應佇列 */
	protected array $tcat_cmd_queues = [];

	/** @var array<int, array{url: string, body: string, headers: array<string, mixed>, cmd: string}> 已攔截的請求 */
	protected array $tcat_requests = [];

	/** @var bool 攔截器是否已掛上 */
	private bool $tcat_http_hooked = false;

	/**
	 * 啟用黑貓PAY（test 模式 + 假憑證 + 三種子方式）
	 *
	 * @param array<string, mixed> $extra 額外設定
	 * @return void
	 */
	final protected function setup_tcat_pay( array $extra = [] ): void {
		\update_option( 'woocommerce_currency', 'TWD' );
		ProviderUtils::update_option(
			TcatPayGateway::ID,
			\array_merge(
				[
					'enabled'         => 'yes',
					'mode'            => 'test',
					'cust_id'         => self::$tcat_cust_id,
					'api_password'    => self::$tcat_api_password,
					'hash_base'       => self::$tcat_hash_base,
					'acquirer_type'   => 'payuni',
					'enabled_methods' => [ 'credit', 'cvs_ibon', 'atm' ],
				],
				$extra
			)
		);
	}

	/**
	 * 讀 golden fixture（`__TRADE_NO__` 替換為指定單號）
	 *
	 * @param string               $name      檔名（不含 .json）
	 * @param string               $trade_no  替換 __TRADE_NO__ 的值
	 * @param array<string, mixed> $overrides 覆寫鍵
	 * @return array<string, mixed>
	 */
	final protected function tcat_fixture( string $name, string $trade_no = '', array $overrides = [] ): array {
		$raw  = (string) \file_get_contents( __DIR__ . "/../Fixtures/tcat_pay/{$name}.json" );
		$raw  = \str_replace( '__TRADE_NO__', $trade_no, $raw );
		$data = \json_decode( $raw, true );
		return \array_merge( \is_array( $data ) ? $data : [], $overrides );
	}

	/**
	 * 設定 /Token 回應佇列（未設定時預設回 token.json）
	 *
	 * @param mixed ...$responses 回應
	 * @return void
	 */
	final protected function fake_tcat_token( mixed ...$responses ): void {
		$this->tcat_token_queue = $responses;
		$this->hook_tcat_http();
	}

	/**
	 * 設定某 cmd 的回應佇列
	 *
	 * @param string $cmd          cmd
	 * @param mixed  ...$responses 回應
	 * @return void
	 */
	final protected function fake_tcat_cmd( string $cmd, mixed ...$responses ): void {
		$this->tcat_cmd_queues[ $cmd ] = $responses;
		$this->hook_tcat_http();
	}

	/** 只掛攔截器（全部 /api/Collect 擋下並記錄），用於斷言「不打 API」 @return void */
	final protected function block_tcat_http(): void {
		$this->hook_tcat_http();
	}

	/** @return void 掛上攔截器（冪等） */
	private function hook_tcat_http(): void {
		if ( $this->tcat_http_hooked ) {
			return;
		}
		\add_filter( 'pre_http_request', [ $this, 'intercept_tcat_http' ], 10, 3 );
		$this->tcat_http_hooked = true;
	}

	/** @return void 移除攔截器、清空紀錄、清 token 與節流快取 */
	final protected function reset_tcat_pay_http(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_tcat_http' ], 10 );
		$this->tcat_http_hooked = false;
		$this->tcat_token_queue = [];
		$this->tcat_cmd_queues  = [];
		$this->tcat_requests    = [];
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%pc\\_tcat\\_pay%' OR option_name LIKE '\\_transient\\_timeout\\_%pc\\_tcat\\_pay%'" ); // phpcs:ignore
	}

	/**
	 * pre_http_request 攔截器
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt     既有 preempt
	 * @param array<string, mixed>                 $parsed_args 請求參數
	 * @param string                               $url         URL
	 * @return false|array<string, mixed>|\WP_Error
	 */
	final public function intercept_tcat_http( $preempt, array $parsed_args, string $url ) {
		if ( ! \str_contains( $url, '4128888card.com.tw' ) ) {
			return $preempt;
		}

		$body    = \is_string( $parsed_args['body'] ?? null ) ? $parsed_args['body'] : '';
		$decoded = \json_decode( $body, true );
		$cmd     = \is_array( $decoded ) ? (string) ( $decoded['cmd'] ?? '' ) : '';

		$this->tcat_requests[] = [
			'url'     => $url,
			'body'    => $body,
			'headers' => \is_array( $parsed_args['headers'] ?? null ) ? $parsed_args['headers'] : [],
			'cmd'     => $cmd,
		];

		if ( \str_ends_with( $url, '/Token' ) ) {
			$response = [] === $this->tcat_token_queue
				? $this->tcat_fixture( 'token' )
				: self::shift_queue( $this->tcat_token_queue );
			return $this->to_http_response( $response, [] );
		}

		if ( \str_ends_with( $url, '/api/Collect' ) && isset( $this->tcat_cmd_queues[ $cmd ] ) ) {
			$response = self::shift_queue( $this->tcat_cmd_queues[ $cmd ] );
			return $this->to_http_response( $response, \is_array( $decoded ) ? $decoded : [] );
		}

		return new \WP_Error( 'http_blocked_in_test', "測試中禁止外部請求：{$url} cmd={$cmd}" );
	}

	/**
	 * 取出佇列下一筆（只剩一筆時重複使用）
	 *
	 * @param array<int, mixed> $queue 佇列（傳址）
	 * @return mixed
	 */
	private static function shift_queue( array &$queue ): mixed {
		if ( \count( $queue ) > 1 ) {
			return \array_shift( $queue );
		}
		return $queue[0] ?? null;
	}

	/**
	 * 轉成 WP HTTP 回應
	 *
	 * @param mixed                $response 回應定義
	 * @param array<string, mixed> $body     請求 body（給 callable）
	 * @return array<string, mixed>|\WP_Error
	 */
	private function to_http_response( mixed $response, array $body ): array|\WP_Error {
		if ( \is_callable( $response ) ) {
			$response = $response( $body );
		}
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		$code    = 200;
		$payload = $response;
		if ( \is_array( $response ) && isset( $response['__http'] ) ) {
			$code    = (int) $response['__http'];
			$payload = $response['body'] ?? '';
		}

		return [
			'headers'  => [ 'content-type' => 'application/json; charset=utf-8' ],
			'body'     => \is_string( $payload ) ? $payload : (string) \wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'response' => [
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 已攔截的 /api/Collect 請求（可依 cmd 過濾）
	 *
	 * @param string|null $cmd cmd
	 * @return array<int, array{url: string, body: string, headers: array<string, mixed>, cmd: string}>
	 */
	final protected function tcat_collect_requests( ?string $cmd = null ): array {
		return \array_values(
			\array_filter(
				$this->tcat_requests,
				static fn( array $req ): bool => \str_ends_with( $req['url'], '/api/Collect' ) && ( null === $cmd || $cmd === $req['cmd'] )
			)
		);
	}

	/** @return array<int, array{url: string, body: string, headers: array<string, mixed>, cmd: string}> /Token 請求 */
	final protected function tcat_token_requests(): array {
		return \array_values(
			\array_filter(
				$this->tcat_requests,
				static fn( array $req ): bool => \str_ends_with( $req['url'], '/Token' )
			)
		);
	}

	/**
	 * 第 N 個 Collect 請求的 body（decode）
	 *
	 * @param string $cmd   cmd
	 * @param int    $index 索引
	 * @return array<string, mixed>
	 */
	final protected function tcat_request_body( string $cmd, int $index = 0 ): array {
		$requests = $this->tcat_collect_requests( $cmd );
		$this->assertArrayHasKey( $index, $requests, "未送出 {$cmd} 請求" );
		$body = \json_decode( $requests[ $index ]['body'], true );
		return \is_array( $body ) ? $body : [];
	}

	/**
	 * 建立黑貓PAY訂單（可預寫子方式、交易編號與送出金額）
	 *
	 * @param float       $total    金額
	 * @param string      $status   狀態
	 * @param string|null $method   子方式 credit|cvs_ibon|atm（null 不寫）
	 * @param string|null $trade_no 交易編號（寫入 trade_no + history；null 不寫）
	 * @param string      $payment_method gateway id
	 * @return \WC_Order
	 */
	final protected function make_tcat_order(
		float $total = 1000.0,
		string $status = 'pending',
		?string $method = null,
		?string $trade_no = null,
		string $payment_method = TcatPayGateway::ID
	): \WC_Order {
		$order = \wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->set_currency( 'TWD' );
		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->set_billing_country( 'TW' );
		$order->set_billing_postcode( '100' );
		$order->set_billing_state( '臺北市' );
		$order->set_billing_city( '中正區' );
		$order->set_billing_address_1( '測試路 1 號' );
		$order->set_total( (string) $total );
		$order->save();
		$order->update_status( $status );
		$order->save();

		$meta = new TcatPayMetaKeys( $order );
		if ( null !== $method ) {
			$meta->update_method( $method );
		}
		if ( null !== $trade_no ) {
			$meta->start_attempt( $trade_no, (int) \round( $total ) );
		}

		return \wc_get_order( $order->get_id() );
	}

	/**
	 * 產生 APN payload（以官方公式重算 checksum）
	 *
	 * @param string               $order_no 契客訂單編號
	 * @param string               $status   狀態碼
	 * @param bool                 $is_cvs   是否 CVS（payment_code=2）
	 * @param int                  $amount   金額
	 * @param array<string, mixed> $extra    額外欄位
	 * @return array<string, mixed>
	 */
	final protected function tcat_apn_payload( string $order_no, string $status, bool $is_cvs = false, int $amount = 1000, array $extra = [] ): array {
		$payload = \array_merge(
			$this->tcat_fixture( $is_cvs ? 'apn_cvs_d' : 'apn_cocs_b' ),
			[
				'api_id'   => self::$tcat_cust_id,
				'trans_id' => 'trans_' . \md5( $order_no ),
				'order_no' => $order_no,
				'amount'   => $amount,
				'status'   => $status,
				'nonce'    => '1030451234',
			],
			$is_cvs ? [ 'pay_amount' => (string) $amount ] : [ 'payment_detail' => [ 'auth_code' => '123456', 'auth_card_no' => '552199******1864', 'pay_amount' => $amount ] ],
			$extra
		);

		$payload['checksum'] = TcatPayChecksum::apn_checksum(
			(string) $payload['api_id'],
			(string) $payload['trans_id'],
			(string) $payload['amount'],
			(string) $payload['status'],
			(string) $payload['nonce']
		);

		return $payload;
	}

	/**
	 * 以 REST 打 APN 端點
	 *
	 * @param array<string, mixed>|string $payload payload（字串則原樣當 body）
	 * @return \WP_REST_Response
	 */
	final protected function post_tcat_apn( array|string $payload ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/tcat-pay/apn' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( \is_string( $payload ) ? $payload : (string) \wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) );
		$response = \rest_do_request( $request );
		return $response instanceof \WP_REST_Response ? $response : new \WP_REST_Response( null, 500 );
	}

	/**
	 * 訂單備註內容
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<int, string>
	 */
	final protected function tcat_order_notes( \WC_Order $order ): array {
		return \array_map(
			static fn( $note ): string => (string) $note->content,
			\wc_get_order_notes( [ 'order_id' => $order->get_id() ] )
		);
	}
}
