<?php

declare( strict_types=1 );

namespace Tests\Integration\Logistics\Doubles;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs\KerrytjLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\KerrytjLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\WaybillNumberAllocator;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/**
 * 嘉里大榮（kerrytj_logistics）測試共用 helper
 *
 * - HTTP：pre_http_request 攔截 api.kerrytj.com（EDIEntrance / EDIEntranceTest）與 kerrytracingedi.kerrytj.com，
 *   以端點名稱（`ShipmentDelivery` / `UpdateShipmentDelivery` / `PickupV2` / `Report/GetLabelPdf` / `Report/GetDetailPdf` /
 *   `BLNListTracing` / `ColdBLNListTracing`）分派回應佇列。回應元素：
 *     string（fixture 名稱，讀 Fixtures/kerrytj_logistics/{name}.json，`__BLN__` 換成送出的第一筆 BLN）／
 *     'pdf'（回 `%PDF-1.4` 假 PDF，application/pdf）／array{status:int, body:string, type?:string}（原文）／
 *     \WP_Error／callable(array $req): mixed。佇列取完重複最後一筆；未設定的端點一律 WP_Error（禁止外部請求）。
 * - golden 來源：401 body 與 `GetLabelPdf` ModelState 取自 2026-11-21 無憑證實打 EDIEntranceTest；寫入類 Result 01/02/03、
 *   AreaData、貨況範例取自 skill `kerrytj-logistics` references/api-reference.md §4.3 / tracking.md §2–§3（官方規格書範例）；
 *   「託運單號重複」「取件編號重複 ,託運單號重複 ,」取自 zenbu kerrytj-api-client.ts 測試機實測註解；
 *   `update_fail` 的錯誤文字為合成值（規格書未提供完整錯誤訊息表）。
 * - 帳密 / 客編為假值；號段取規格書範例（常溫 40350718600 起、低溫 667000000 起）。
 *
 * 使用端須在 tear_down 呼叫 reset_kerrytj_logistics()。
 */
trait KerrytjLogisticsTestTrait {

	/** @var string 測試帳號（假值） */
	protected static string $kerrytj_username = 'pc-fake-user';

	/** @var string 測試密碼（假值） */
	protected static string $kerrytj_password = 'pc-fake-pass';

	/** @var string 測試客編（假值，8 碼） */
	protected static string $kerrytj_customer_no = '12345678';

	/** @var string 常溫號段第一號（規格書範例） */
	protected static string $kerrytj_ambient_first = '40350718600';

	/** @var string 低溫號段第一號（規格書範例 667000000 → 6670000002） */
	protected static string $kerrytj_cold_first = '6670000002';

	/** @var array<string, array<int, mixed>> 端點 => 回應佇列 */
	protected array $kerrytj_queues = [];

	/** @var array<int, array{endpoint: string, url: string, body: string, json: mixed, headers: array<string, mixed>, timeout: mixed}> 已攔截請求 */
	protected array $kerrytj_requests = [];

	/** @var bool 攔截器是否已掛上 */
	private bool $kerrytj_http_hooked = false;

	/**
	 * 啟用嘉里大榮（test 模式 + 假帳密 + 寄件人 + 常溫 / 低溫號段）
	 *
	 * @param array<string, mixed> $extra 額外設定
	 * @return void
	 */
	final protected function setup_kerrytj_logistics( array $extra = [] ): void {
		\update_option( 'woocommerce_currency', 'TWD' );
		\delete_option( ProviderUtils::get_option_name( KerrytjLogisticsProvider::ID ) );
		ProviderUtils::update_option(
			KerrytjLogisticsProvider::ID,
			\array_merge(
				[
					'enabled'               => 'yes',
					'mode'                  => 'test',
					'username'              => self::$kerrytj_username,
					'password'              => self::$kerrytj_password,
					'customer_no'           => self::$kerrytj_customer_no,
					'sender_name'           => '某某商行',
					'sender_phone'          => '02-2345-6789',
					'sender_postcode'       => '10491',
					'sender_address'        => '台北市中山區某路1號',
					'ambient_waybill_start' => '40350718600',
					'ambient_waybill_end'   => '40350718699',
					'cold_waybill_start'    => '667000000',
					'cold_waybill_end'      => '667000099',
				],
				$extra
			)
		);
		KerrytjLogisticsSettingsDTO::reset();
		KerrytjLogisticsProvider::sync_waybill_ranges();
		ProviderUtils::$container[ KerrytjLogisticsProvider::ID ] = KerrytjLogisticsProvider::instance();
	}

	/**
	 * 讀 fixture 原文並替換佔位
	 *
	 * @param string                $name         檔名（不含 .json）
	 * @param array<string, string> $replacements 佔位 => 值
	 * @return string
	 */
	final protected function kerrytj_fixture( string $name, array $replacements = [] ): string {
		$raw = (string) \file_get_contents( __DIR__ . "/../Fixtures/kerrytj_logistics/{$name}.json" );
		return \trim( \strtr( $raw, $replacements ) );
	}

	/**
	 * 設定某端點的回應佇列
	 *
	 * @param string $endpoint     端點（ShipmentDelivery / UpdateShipmentDelivery / PickupV2 / Report/GetLabelPdf / Report/GetDetailPdf / BLNListTracing / ColdBLNListTracing）
	 * @param mixed  ...$responses 回應
	 * @return void
	 */
	final protected function fake_kerrytj( string $endpoint, mixed ...$responses ): void {
		$this->kerrytj_queues[ $endpoint ] = $responses;
		$this->hook_kerrytj_http();
	}

	/** 只掛攔截器（全部請求擋下並記錄），用於斷言「不打 API」 @return void */
	final protected function block_kerrytj_http(): void {
		$this->hook_kerrytj_http();
	}

	/**
	 * 取得某端點的已攔截請求
	 *
	 * @param string $endpoint 端點
	 * @return array<int, array{endpoint: string, url: string, body: string, json: mixed, headers: array<string, mixed>, timeout: mixed}>
	 */
	final protected function kerrytj_requests( string $endpoint ): array {
		return \array_values( \array_filter( $this->kerrytj_requests, static fn( array $r ): bool => $r['endpoint'] === $endpoint ) );
	}

	/**
	 * 取得某端點第 N 次請求的 JSON body（已解碼）
	 *
	 * @param string $endpoint 端點
	 * @param int    $index    第幾次
	 * @return mixed
	 */
	final protected function kerrytj_request_json( string $endpoint, int $index = 0 ): mixed {
		return $this->kerrytj_requests( $endpoint )[ $index ]['json'] ?? null;
	}

	/** @return void 掛上攔截器（冪等） */
	private function hook_kerrytj_http(): void {
		if ( $this->kerrytj_http_hooked ) {
			return;
		}
		\add_filter( 'pre_http_request', [ $this, 'intercept_kerrytj_http' ], 10, 3 );
		$this->kerrytj_http_hooked = true;
	}

	/** @return void 移除攔截器、清空紀錄、清設定 / 號段 */
	final protected function reset_kerrytj_logistics(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_kerrytj_http' ], 10 );
		$this->kerrytj_http_hooked = false;
		$this->kerrytj_queues      = [];
		$this->kerrytj_requests    = [];
		\delete_option( ProviderUtils::get_option_name( KerrytjLogisticsProvider::ID ) );
		foreach ( [ 'test', 'prod' ] as $mode ) {
			foreach ( [ 'ambient', 'cold' ] as $kind ) {
				$key = KerrytjLogisticsProvider::ID . "_{$mode}_{$kind}";
				\delete_option( WaybillNumberAllocator::RANGE_OPTION_PREFIX . $key );
				\delete_option( WaybillNumberAllocator::CURRENT_OPTION_PREFIX . $key );
			}
		}
		KerrytjLogisticsSettingsDTO::reset();
	}

	/**
	 * pre_http_request 攔截器
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt     既有 preempt
	 * @param array<string, mixed>                 $parsed_args 請求參數
	 * @param string                               $url         URL
	 * @return false|array<string, mixed>|\WP_Error
	 */
	final public function intercept_kerrytj_http( $preempt, array $parsed_args, string $url ) {
		$host = \strtolower( (string) \wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! \str_ends_with( $host, 'kerrytj.com' ) ) {
			return $preempt;
		}

		$path     = (string) \wp_parse_url( $url, PHP_URL_PATH );
		$endpoint = (string) \preg_replace( '#^/(EDIEntranceTest|EDIEntrance|api/Tracing)/#', '', $path );
		$body     = \is_string( $parsed_args['body'] ?? null ) ? $parsed_args['body'] : '';
		$json     = \json_decode( $body, true );
		$request  = [
			'endpoint' => $endpoint,
			'url'      => $url,
			'body'     => $body,
			'json'     => $json,
			'headers'  => \is_array( $parsed_args['headers'] ?? null ) ? $parsed_args['headers'] : [],
			'timeout'  => $parsed_args['timeout'] ?? null,
		];

		$this->kerrytj_requests[] = $request;

		if ( ! isset( $this->kerrytj_queues[ $endpoint ] ) ) {
			return new \WP_Error( 'http_blocked_in_test', "測試中禁止外部請求：{$url}" );
		}

		$queue    = &$this->kerrytj_queues[ $endpoint ];
		$response = \count( $queue ) > 1 ? \array_shift( $queue ) : \reset( $queue );
		if ( \is_callable( $response ) ) {
			$response = $response( $request );
		}
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		$status = 200;
		$type   = 'application/json; charset=utf-8';
		if ( \is_array( $response ) ) {
			$status   = (int) ( $response['status'] ?? 200 );
			$type     = (string) ( $response['type'] ?? $type );
			$response = (string) ( $response['body'] ?? '' );
		} elseif ( 'pdf' === $response ) {
			$type     = 'application/pdf';
			$response = "%PDF-1.4\n% fake kerrytj pdf\n%%EOF";
		} else {
			$first = \is_array( $json ) && \is_array( $json[0] ?? null ) ? $json[0] : ( \is_array( $json ) ? $json : [] );
			$bln   = (string) ( $first['BLN'] ?? '' );
			if ( '' === $bln ) {
				$bln = self::$kerrytj_ambient_first;
			}
			$response = $this->kerrytj_fixture( (string) $response, [ '__BLN__' => $bln ] );
		}

		return [
			'headers'  => [ 'content-type' => $type ],
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
	 * 建立嘉里大榮宅配測試訂單（台灣地址、收件手機）
	 *
	 * @param array<string, mixed> $args 覆寫（name_first / name_last / phone / city / address / postcode / payment_method / total / order_meta）
	 * @return \WC_Order
	 */
	final protected function make_kerrytj_order( array $args = [] ): \WC_Order {
		$order   = \wc_create_order();
		$product = new \WC_Product_Simple();
		$product->set_name( '嘉里測試商品' );
		$product->set_regular_price( (string) ( $args['total'] ?? 1000 ) );
		$product->save();
		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_name( '嘉里測試商品' );
		$item->set_quantity( 1 );
		$item->set_total( (string) ( $args['total'] ?? 1000 ) );
		$order->add_item( $item );

		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
		$order->set_billing_phone( (string) ( $args['phone'] ?? '0912-345-678' ) );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_shipping_first_name( (string) ( $args['name_first'] ?? '小明' ) );
		$order->set_shipping_last_name( (string) ( $args['name_last'] ?? '王' ) );
		$order->set_shipping_country( 'TW' );
		$order->set_shipping_state( '' );
		$order->set_shipping_city( (string) ( $args['city'] ?? '台中市西屯區' ) );
		$order->set_shipping_postcode( (string) ( $args['postcode'] ?? '40755' ) );
		$order->set_shipping_address_1( (string) ( $args['address'] ?? '某路2號' ) );
		$order->set_payment_method( (string) ( $args['payment_method'] ?? 'bacs' ) );
		$order->set_total( (string) ( $args['total'] ?? 1000 ) );
		foreach ( ( $args['order_meta'] ?? [] ) as $key => $value ) {
			$order->update_meta_data( (string) $key, $value );
		}
		$order->set_status( 'processing' );
		$order->save();
		return $order;
	}
}
