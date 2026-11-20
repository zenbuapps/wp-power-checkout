<?php

declare( strict_types=1 );

namespace Tests\Integration\Logistics\Doubles;

use J7\PowerCheckout\Domains\Logistics\Tcat\DTOs\TcatLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\TcatLogisticsProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/**
 * 黑貓宅急便直串（tcat_logistics）測試共用 helper
 *
 * - HTTP：pre_http_request 攔截 *.suda.com.tw / *.suda.net.tw。依路徑最後一段（服務名稱，如 PrintOBT）
 *   分派到回應佇列；佇列取完後重複最後一筆；未設定的服務一律回 WP_Error（測試不連外）。
 * - 回應元素：string（fixture 名稱，讀 Fixtures/tcat_logistics/{name}.json）／array{status:int, body:string, content_type?:string}
 *   ／\WP_Error／callable(array $req): mixed（回傳上述任一形態）。
 * - golden 回應：tests/Integration/Logistics/Fixtures/tcat_logistics/*.json；錯誤訊息格式
 *   「執行失敗 -> E009-契客資料不正確。」取自 2026-11-21 對測試站以假憑證實打的真實回應。
 * - 憑證全為測試假值（非真實契客代號 / 授權碼）。
 *
 * 使用端須在 tear_down 呼叫 reset_tcat_logistics()。
 */
trait TcatLogisticsTestTrait {

	/** @var string 測試契客代號（假值，10 碼） */
	protected static string $tcat_customer_id = '1234567890';

	/** @var string 測試授權碼（假值） */
	protected static string $tcat_customer_token = 'test-customer-token-not-real';

	/** @var string golden 託運單號 */
	protected static string $tcat_obt = '930005203251';

	/** @var string golden FileNo */
	protected static string $tcat_file_no = 'FN20261121103000ABC';

	/** @var array<string, array<int, mixed>> 服務名稱 => 回應佇列 */
	protected array $tcat_queues = [];

	/** @var array<int, array{service: string, url: string, body: string, decoded: array<string, mixed>, headers: array<string, mixed>, timeout: mixed}> 已攔截請求 */
	protected array $tcat_requests = [];

	/** @var bool 攔截器是否已掛上 */
	private bool $tcat_http_hooked = false;

	/**
	 * 啟用黑貓宅急便（test 模式 + 假憑證 + 寄件人）
	 *
	 * @param array<string, mixed> $extra 額外設定
	 * @return void
	 */
	final protected function setup_tcat_logistics( array $extra = [] ): void {
		\update_option( 'woocommerce_currency', 'TWD' );
		\delete_option( ProviderUtils::get_option_name( TcatLogisticsProvider::ID ) );
		ProviderUtils::update_option(
			TcatLogisticsProvider::ID,
			\array_merge(
				[
					'enabled'         => 'yes',
					'mode'            => 'test',
					'customer_id'     => self::$tcat_customer_id,
					'customer_token'  => self::$tcat_customer_token,
					'sender_name'     => '黑貓測試商店',
					'sender_tel'      => '02-87121298',
					'sender_mobile'   => '0987654321',
					'sender_address'  => '台北市重陽路200號',
					'sender_zip_code' => '',
				],
				$extra
			)
		);
		TcatLogisticsSettingsDTO::reset();
		ProviderUtils::$container[ TcatLogisticsProvider::ID ] = TcatLogisticsProvider::instance();
	}

	/**
	 * 讀 fixture 原文並替換佔位
	 *
	 * @param string                $name         檔名（不含 .json）
	 * @param array<string, string> $replacements 佔位 => 值
	 * @return string
	 */
	final protected function tcat_fixture( string $name, array $replacements = [] ): string {
		$raw = (string) \file_get_contents( __DIR__ . "/../Fixtures/tcat_logistics/{$name}.json" );
		return \trim( \strtr( $raw, $replacements ) );
	}

	/** @return string golden PDF bytes */
	final protected function tcat_pdf(): string {
		return (string) \file_get_contents( __DIR__ . '/../Fixtures/tcat_logistics/sample.pdf' );
	}

	/**
	 * 設定某服務的回應佇列
	 *
	 * @param string $service      服務名稱（ParsingAddress / PrintOBT / DownloadOBT / OBTStatus / ReserveAdd）
	 * @param mixed  ...$responses 回應
	 * @return void
	 */
	final protected function fake_tcat( string $service, mixed ...$responses ): void {
		$this->tcat_queues[ $service ] = $responses;
		$this->hook_tcat_http();
	}

	/** 只掛攔截器（全部請求擋下並記錄），用於斷言「不打 API」 @return void */
	final protected function block_tcat_http(): void {
		$this->hook_tcat_http();
	}

	/**
	 * 取得某服務的已攔截請求
	 *
	 * @param string $service 服務名稱
	 * @return array<int, array{service: string, url: string, body: string, decoded: array<string, mixed>, headers: array<string, mixed>, timeout: mixed}>
	 */
	final protected function tcat_requests( string $service ): array {
		return \array_values( \array_filter( $this->tcat_requests, static fn( array $r ): bool => $r['service'] === $service ) );
	}

	/** @return void 掛上攔截器（冪等） */
	private function hook_tcat_http(): void {
		if ( $this->tcat_http_hooked ) {
			return;
		}
		\add_filter( 'pre_http_request', [ $this, 'intercept_tcat_http' ], 10, 3 );
		$this->tcat_http_hooked = true;
	}

	/** @return void 移除攔截器、清空紀錄、清設定與快取 */
	final protected function reset_tcat_logistics(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_tcat_http' ], 10 );
		$this->tcat_http_hooked = false;
		$this->tcat_queues      = [];
		$this->tcat_requests    = [];
		\delete_option( ProviderUtils::get_option_name( TcatLogisticsProvider::ID ) );
		\delete_transient( 'pc_tcat_logistics_status_' . self::$tcat_obt );
		TcatLogisticsSettingsDTO::reset();
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
		$host = (string) \wp_parse_url( $url, PHP_URL_HOST );
		if ( ! \str_ends_with( $host, 'suda.com.tw' ) && ! \str_ends_with( $host, 'suda.net.tw' ) ) {
			return $preempt;
		}

		$path    = (string) \wp_parse_url( $url, PHP_URL_PATH );
		$service = (string) \basename( $path );
		$body    = \is_string( $parsed_args['body'] ?? null ) ? $parsed_args['body'] : '';
		$decoded = \json_decode( $body, true );

		$request               = [
			'service' => $service,
			'url'     => $url,
			'body'    => $body,
			'decoded' => \is_array( $decoded ) ? $decoded : [],
			'headers' => \is_array( $parsed_args['headers'] ?? null ) ? $parsed_args['headers'] : [],
			'timeout' => $parsed_args['timeout'] ?? null,
		];
		$this->tcat_requests[] = $request;

		if ( ! isset( $this->tcat_queues[ $service ] ) ) {
			return new \WP_Error( 'http_blocked_in_test', "測試中禁止外部請求：{$url}" );
		}

		$queue    = &$this->tcat_queues[ $service ];
		$response = \count( $queue ) > 1 ? \array_shift( $queue ) : \reset( $queue );
		if ( \is_callable( $response ) ) {
			$response = $response( $request );
		}
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		$status       = 200;
		$content_type = 'application/json; charset=utf-8';
		if ( \is_array( $response ) ) {
			$status       = (int) ( $response['status'] ?? 200 );
			$content_type = (string) ( $response['content_type'] ?? $content_type );
			$response     = (string) ( $response['body'] ?? '' );
		} else {
			$response = $this->tcat_fixture( (string) $response, [ '__ORDER_ID__' => (string) ( $request['decoded']['Orders'][0]['OrderId'] ?? '' ) ] );
		}

		return [
			'headers'  => [ 'content-type' => $content_type ],
			'body'     => $response,
			'response' => [
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Error',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 建立黑貓宅配測試訂單（台灣地址、收件手機）
	 *
	 * @param array<string, mixed> $args 覆寫（phone / payment_method / total / item_name）
	 * @return \WC_Order
	 */
	final protected function make_tcat_order( array $args = [] ): \WC_Order {
		$order = \wc_create_order();
		$item  = new \WC_Order_Item_Product();
		$item->set_name( (string) ( $args['item_name'] ?? '澎湖XO干貝醬' ) );
		$item->set_quantity( 1 );
		$item->set_total( (string) ( $args['total'] ?? 1000 ) );
		$order->add_item( $item );

		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
		$order->set_billing_phone( (string) ( $args['phone'] ?? '0912345678' ) );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_shipping_first_name( '小明' );
		$order->set_shipping_last_name( '王' );
		$order->set_shipping_country( 'TW' );
		$order->set_shipping_state( '' );
		$order->set_shipping_city( '台北市中山區' );
		$order->set_shipping_postcode( '104' );
		$order->set_shipping_address_1( '南京東路三段261號2樓' );
		$order->set_payment_method( (string) ( $args['payment_method'] ?? 'bacs' ) );
		$order->set_total( (string) ( $args['total'] ?? 1000 ) );
		$order->set_status( 'processing' );
		$order->save();
		return $order;
	}
}
