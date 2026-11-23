<?php

declare( strict_types=1 );

namespace Tests\Integration\Payment\Doubles;

use J7\PowerCheckout\Domains\Payment\Linepay\Services\LinepayGateway;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers\LinepayMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/**
 * LINE Pay 直連（linepay）測試共用 helper
 *
 * - HTTP：pre_http_request 攔截 *api-pay.line.me。依路徑分派到 5 種端點佇列
 *   （request / check / confirm / details / refund），佇列取完後重複最後一筆；
 *   未設定的端點一律回 WP_Error（測試不連外）。
 * - 回應元素：string（原樣當 body，用來保留 19 位整數 transactionId）／array（JSON 編碼）／
 *   \WP_Error／callable(array $req): mixed（回傳上述任一形態）。
 * - golden 回應：tests/Integration/Payment/Fixtures/linepay/*.json，佔位
 *   `__TX__`（transactionId，JSON 數字）、`__ORDER_ID__`、`__AMOUNT__`。
 * - 憑證全為測試假值（channel secret 取自 skill authentication.md §3 的自算交叉驗證向量，非真實憑證）。
 *
 * 使用端須在 tear_down 呼叫 reset_linepay_http()。
 */
trait LinepayDirectTestTrait {

	/** @var string 19 位 transactionId golden（R4） */
	protected static string $linepay_tx = '2026111900000000001';

	/** @var string 測試 Channel ID（假值） */
	protected static string $linepay_channel_id = '1234567890';

	/** @var string 測試 Channel Secret（skill authentication.md §3 交叉驗證向量的假 secret） */
	protected static string $linepay_channel_secret = 'a917ab6a2367b536f8e5a6e2977e06f4';

	/** @var array<string, array<int, mixed>> 端點 => 回應佇列 */
	protected array $linepay_queues = [];

	/** @var array<int, array{kind: string, method: string, url: string, path: string, query: string, body: string, headers: array<string, mixed>}> 已攔截請求 */
	protected array $linepay_requests = [];

	/** @var bool 攔截器是否已掛上 */
	private bool $linepay_http_hooked = false;

	/**
	 * 啟用 LINE Pay（test 模式 + 假憑證）
	 *
	 * @param array<string, mixed> $extra 額外設定
	 * @return void
	 */
	final protected function setup_linepay( array $extra = [] ): void {
		\update_option( 'woocommerce_currency', 'TWD' );
		ProviderUtils::update_option(
			LinepayGateway::ID,
			\array_merge(
				[
					'enabled'        => 'yes',
					'mode'           => 'test',
					'channel_id'     => self::$linepay_channel_id,
					'channel_secret' => self::$linepay_channel_secret,
				],
				$extra
			)
		);
	}

	/**
	 * 讀 golden fixture 原文（保留 19 位整數不經 PHP 解碼）
	 *
	 * @param string $name     檔名（不含 .json）
	 * @param string $order_id LINE Pay orderId
	 * @param int    $amount   金額
	 * @param string $tx       transactionId
	 * @return string
	 */
	final protected function linepay_fixture( string $name, string $order_id = 'PCL1-1-TEST01', int $amount = 1000, string $tx = '' ): string {
		$raw = (string) \file_get_contents( __DIR__ . "/../Fixtures/linepay/{$name}.json" );
		return \str_replace(
			[ '__TX__', '__ORDER_ID__', '__AMOUNT__' ],
			[ '' === $tx ? self::$linepay_tx : $tx, $order_id, (string) $amount ],
			\trim( $raw )
		);
	}

	/**
	 * 設定某端點的回應佇列
	 *
	 * @param string $kind         request|check|confirm|details|refund
	 * @param mixed  ...$responses 回應
	 * @return void
	 */
	final protected function fake_linepay( string $kind, mixed ...$responses ): void {
		$this->linepay_queues[ $kind ] = $responses;
		$this->hook_linepay_http();
	}

	/** 只掛攔截器（全部請求擋下並記錄），用於斷言「不打 API」 @return void */
	final protected function block_linepay_http(): void {
		$this->hook_linepay_http();
	}

	/** @return void 掛上攔截器（冪等） */
	private function hook_linepay_http(): void {
		if ( $this->linepay_http_hooked ) {
			return;
		}
		\add_filter( 'pre_http_request', [ $this, 'intercept_linepay_http' ], 10, 3 );
		$this->linepay_http_hooked = true;
	}

	/** @return void 移除攔截器、清空紀錄、清 AS 排程 */
	final protected function reset_linepay_http(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_linepay_http' ], 10 );
		$this->linepay_http_hooked = false;
		$this->linepay_queues      = [];
		$this->linepay_requests    = [];
		if ( \function_exists( 'as_unschedule_all_actions' ) ) {
			\as_unschedule_all_actions( 'pc_linepay_reconcile' );
		}
	}

	/**
	 * pre_http_request 攔截器
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt     既有 preempt
	 * @param array<string, mixed>                 $parsed_args 請求參數
	 * @param string                               $url         URL
	 * @return false|array<string, mixed>|\WP_Error
	 */
	final public function intercept_linepay_http( $preempt, array $parsed_args, string $url ) {
		if ( ! \str_contains( $url, 'api-pay.line.me' ) ) {
			return $preempt;
		}

		$method = \strtoupper( \is_string( $parsed_args['method'] ?? null ) ? $parsed_args['method'] : 'GET' );
		$parts  = \wp_parse_url( $url );
		$path   = \is_array( $parts ) ? (string) ( $parts['path'] ?? '' ) : '';
		$query  = \is_array( $parts ) ? (string) ( $parts['query'] ?? '' ) : '';
		$body   = \is_string( $parsed_args['body'] ?? null ) ? $parsed_args['body'] : '';
		$kind   = self::linepay_kind( $method, $path );

		$request                  = [
			'kind'    => $kind,
			'method'  => $method,
			'url'     => $url,
			'path'    => $path,
			'query'   => $query,
			'body'    => $body,
			'headers' => \is_array( $parsed_args['headers'] ?? null ) ? $parsed_args['headers'] : [],
		];
		$this->linepay_requests[] = $request;

		if ( ! isset( $this->linepay_queues[ $kind ] ) ) {
			return new \WP_Error( 'http_blocked_in_test', "測試中禁止外部請求：{$method} {$url}" );
		}

		$response = self::shift_linepay_queue( $this->linepay_queues[ $kind ] );
		if ( \is_callable( $response ) ) {
			$response = $response( $request );
		}
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		return [
			'headers'  => [ 'content-type' => 'application/json;charset=UTF-8' ],
			'body'     => \is_string( $response ) ? $response : (string) \wp_json_encode( $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 依 method + path 判斷端點
	 *
	 * @param string $method HTTP method
	 * @param string $path   path
	 * @return string
	 */
	private static function linepay_kind( string $method, string $path ): string {
		return match ( true ) {
			'POST' === $method && \str_ends_with( $path, '/payments/request' ) => 'request',
			'GET' === $method && 1 === \preg_match( '#/payments/requests/[^/]+/check$#', $path ) => 'check',
			'POST' === $method && \str_ends_with( $path, '/confirm' ) => 'confirm',
			'POST' === $method && \str_ends_with( $path, '/refund' ) => 'refund',
			'GET' === $method && \str_ends_with( $path, '/payments' ) => 'details',
			default => 'unknown',
		};
	}

	/**
	 * 取出佇列下一筆（只剩一筆時重複使用）
	 *
	 * @param array<int, mixed> $queue 佇列（傳址）
	 * @return mixed
	 */
	private static function shift_linepay_queue( array &$queue ): mixed {
		if ( \count( $queue ) > 1 ) {
			return \array_shift( $queue );
		}
		return $queue[0] ?? null;
	}

	/**
	 * 已攔截請求（可依端點過濾）
	 *
	 * @param string|null $kind 端點
	 * @return array<int, array{kind: string, method: string, url: string, path: string, query: string, body: string, headers: array<string, mixed>}>
	 */
	final protected function linepay_requests( ?string $kind = null ): array {
		return \array_values(
			\array_filter(
				$this->linepay_requests,
				static fn( array $req ): bool => null === $kind || $kind === $req['kind']
			)
		);
	}

	/**
	 * 第 N 個某端點請求的 body（decode）
	 *
	 * @param string $kind  端點
	 * @param int    $index 索引
	 * @return array<string, mixed>
	 */
	final protected function linepay_request_body( string $kind, int $index = 0 ): array {
		$requests = $this->linepay_requests( $kind );
		$this->assertArrayHasKey( $index, $requests, "未送出 {$kind} 請求" );
		$body = \json_decode( $requests[ $index ]['body'], true );
		return \is_array( $body ) ? $body : [];
	}

	/**
	 * 建立 LINE Pay 訂單
	 *
	 * @param float                                     $total          金額（未給 lines 時為單一商品金額）
	 * @param string                                    $status         狀態
	 * @param bool                                      $with_request   是否預寫付款請求 meta（tx / orderId / 金額）
	 * @param string                                    $payment_method gateway id
	 * @param array<int, array{0: string, 1: int, 2: float}> $lines     商品行 [名稱, 數量, 行小計]
	 * @param float                                     $shipping       運費
	 * @return \WC_Order
	 */
	final protected function make_linepay_order(
		float $total = 1000.0,
		string $status = 'pending',
		bool $with_request = false,
		string $payment_method = LinepayGateway::ID,
		array $lines = [],
		float $shipping = 0.0
	): \WC_Order {
		$order = \wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->set_currency( 'TWD' );
		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->set_billing_country( 'TW' );

		if ( [] === $lines ) {
			$lines = [ [ 'LINE Pay 測試商品', 1, $total - $shipping ] ];
		}
		foreach ( $lines as [ $name, $qty, $line_total ] ) {
			// 實體商品：payment_complete 後為 processing（無商品的訂單 WC 會直接 completed）
			$product = new \WC_Product_Simple();
			$product->set_name( $name );
			$product->set_regular_price( (string) ( $line_total / \max( 1, $qty ) ) );
			$product->save();
			$item = new \WC_Order_Item_Product();
			$item->set_product( $product );
			$item->set_name( $name );
			$item->set_quantity( $qty );
			$item->set_subtotal( (string) $line_total );
			$item->set_total( (string) $line_total );
			$order->add_item( $item );
		}
		if ( $shipping > 0 ) {
			$ship = new \WC_Order_Item_Shipping();
			$ship->set_method_title( '宅配' );
			$ship->set_total( (string) $shipping );
			$order->add_item( $ship );
		}

		$order->set_total( (string) $total );
		$order->save();
		$order->update_status( $status );
		$order->save();

		if ( $with_request ) {
			( new LinepayMetaKeys( $order ) )->update_request(
				$this->linepay_order_id_of( $order ),
				self::$linepay_tx,
				(int) \round( $total ),
				'https://sandbox-web-pay.line.me/web/payment/wait?transactionReserveId=TESTRESERVE001'
			);
		}

		return \wc_get_order( $order->get_id() );
	}

	/**
	 * 測試用固定 LINE Pay orderId
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	final protected function linepay_order_id_of( \WC_Order $order ): string {
		return 'PCL' . $order->get_id() . '-1-TEST01';
	}

	/**
	 * 以 REST 模擬 LINE Pay 導回 confirmUrl / cancelUrl（GET）
	 *
	 * @param string               $endpoint confirm|cancel
	 * @param \WC_Order            $order    訂單
	 * @param array<string, mixed> $override 覆寫 query
	 * @return \WP_REST_Response
	 */
	final protected function linepay_return( string $endpoint, \WC_Order $order, array $override = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'GET', "/power-checkout/linepay/{$endpoint}" );
		$request->set_query_params(
			\array_merge(
				[
					'order_id'      => (string) $order->get_id(),
					'key'           => $order->get_order_key(),
					'transactionId' => self::$linepay_tx,
					'orderId'       => $this->linepay_order_id_of( $order ),
				],
				$override
			)
		);
		$response = \rest_do_request( $request );
		return $response instanceof \WP_REST_Response ? $response : new \WP_REST_Response( null, 500 );
	}

	/**
	 * 302 Location
	 *
	 * @param \WP_REST_Response $response 回應
	 * @return string
	 */
	final protected function linepay_location( \WP_REST_Response $response ): string {
		$headers = $response->get_headers();
		return \is_string( $headers['Location'] ?? null ) ? $headers['Location'] : '';
	}

	/**
	 * 訂單備註內容
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<int, string>
	 */
	final protected function linepay_order_notes( \WC_Order $order ): array {
		return \array_map(
			static fn( $note ): string => (string) $note->content,
			\wc_get_order_notes( [ 'order_id' => $order->get_id() ] )
		);
	}
}
