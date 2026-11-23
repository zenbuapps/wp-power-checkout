<?php

declare( strict_types=1 );

namespace Tests\Integration\Logistics\Doubles;

use J7\PowerCheckout\Domains\Logistics\Hct\DTOs\HctLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Hct\Services\HctLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\WaybillNumberAllocator;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/**
 * 新竹物流（hct_logistics）測試共用 helper
 *
 * - SOAP：pre_http_request 攔截 hctrt.hct.com.tw，以 SOAPAction（`"http://tempuri.org/{method}"`）分派回應佇列；
 *   回應元素：string（fixture 名稱，讀 Fixtures/hct_logistics/{name}.json，自動包成 `<{method}Result>` SOAP envelope）／
 *   array{status:int, body:string}（原文）／\WP_Error／callable(array $req): mixed。佇列取完重複最後一筆；未設定一律 WP_Error。
 * - 貨況查詢：攔截 hctapiweb.hct.com.tw（searchGoods_Main_Xml.ashx），回應以測試端獨立的 openssl 實作加密
 *   （不經被測的 HctTrackCrypto），參數用官方 Ver3.1 文件範例值（IV TCAVZYKU / v = MD5("test")），非任何帳號憑證。
 * - 錯誤訊息 golden：「公司名稱或密碼錯誤」「「測試環境」請勿使用正式公司名稱或密碼」「查無資料」取自 2026-11-21 唯讀實打 QueryEDELNO_Json。
 * - 帳密為假值（非官方測試帳號、非真實帳號）。
 *
 * 使用端須在 tear_down 呼叫 reset_hct_logistics()。
 */
trait HctLogisticsTestTrait {

	/** @var string 測試公司名稱（假值） */
	protected static string $hct_company = 'pc-fake-company';

	/** @var string 測試密碼（假值） */
	protected static string $hct_password = 'pc-fake-password';

	/** @var string golden 新竹貨號（9 碼本體 100173483 + MOD7 檢查碼 4） */
	protected static string $hct_edelno = '1001734834';

	/** @var string 官方文件範例 IV（Ver3.1 §5 test vector） */
	protected static string $hct_track_iv = 'TCAVZYKU';

	/** @var string 官方文件範例 v（= MD5("test")） */
	protected static string $hct_track_v = '098F6BCD4621D373CADE4E832627B4F6';

	/** @var array<string, array<int, mixed>> method => 回應佇列（貨況查詢的 key 為 searchGoods） */
	protected array $hct_queues = [];

	/** @var array<int, array{method: string, url: string, body: string, params: array<string, string>, headers: array<string, mixed>, timeout: mixed}> 已攔截請求 */
	protected array $hct_requests = [];

	/** @var bool 攔截器是否已掛上 */
	private bool $hct_http_hooked = false;

	/**
	 * 啟用新竹物流（test 模式 + 假帳密）
	 *
	 * @param array<string, mixed> $extra 額外設定
	 * @return void
	 */
	final protected function setup_hct_logistics( array $extra = [] ): void {
		\update_option( 'woocommerce_currency', 'TWD' );
		\delete_option( ProviderUtils::get_option_name( HctLogisticsProvider::ID ) );
		ProviderUtils::update_option(
			HctLogisticsProvider::ID,
			\array_merge(
				[
					'enabled'  => 'yes',
					'mode'     => 'test',
					'company'  => self::$hct_company,
					'password' => self::$hct_password,
				],
				$extra
			)
		);
		HctLogisticsSettingsDTO::reset();
		ProviderUtils::$container[ HctLogisticsProvider::ID ] = HctLogisticsProvider::instance();
	}

	/**
	 * 加上貨況查詢參數（官方文件範例值）
	 *
	 * @param int $offset_days 金鑰日期偏移
	 * @return void
	 */
	final protected function setup_hct_track( int $offset_days = 235 ): void {
		ProviderUtils::update_option(
			HctLogisticsProvider::ID,
			[
				'track_offset_days' => (string) $offset_days,
				'track_iv'          => self::$hct_track_iv,
				'track_v'           => self::$hct_track_v,
			]
		);
		HctLogisticsSettingsDTO::reset();
	}

	/**
	 * 讀 fixture 原文並替換佔位
	 *
	 * @param string                $name         檔名（含副檔名省略時預設 .json）
	 * @param array<string, string> $replacements 佔位 => 值
	 * @return string
	 */
	final protected function hct_fixture( string $name, array $replacements = [] ): string {
		$file = \str_contains( $name, '.' ) ? $name : "{$name}.json";
		$raw  = (string) \file_get_contents( __DIR__ . "/../Fixtures/hct_logistics/{$file}" );
		return \trim( \strtr( $raw, $replacements ) );
	}

	/** @return string 標籤 PNG 的 hex（模擬 TransData image 欄位） */
	final protected function hct_label_hex(): string {
		return \strtoupper( \bin2hex( (string) \file_get_contents( __DIR__ . '/../Fixtures/hct_logistics/label.png' ) ) );
	}

	/**
	 * 包成 SOAP response envelope
	 *
	 * @param string $method SOAP 方法
	 * @param string $result `<{method}Result>` 內文（未跳脫）
	 * @return string
	 */
	final protected function hct_soap_envelope( string $method, string $result ): string {
		$escaped = \htmlspecialchars( $result, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		return '<?xml version="1.0" encoding="utf-8"?><soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">'
			. "<soap:Body><{$method}Response xmlns=\"http://tempuri.org/\"><{$method}Result>{$escaped}</{$method}Result></{$method}Response></soap:Body></soap:Envelope>";
	}

	/**
	 * 設定某 SOAP 方法（或 searchGoods）的回應佇列
	 *
	 * @param string $method       SOAP 方法（TransData_Json / QueryEDELNO_Json / TransDataCancel_Json / R_TransData_Json）或 searchGoods
	 * @param mixed  ...$responses 回應
	 * @return void
	 */
	final protected function fake_hct( string $method, mixed ...$responses ): void {
		$this->hct_queues[ $method ] = $responses;
		$this->hook_hct_http();
	}

	/** 只掛攔截器（全部請求擋下並記錄），用於斷言「不打 API」 @return void */
	final protected function block_hct_http(): void {
		$this->hook_hct_http();
	}

	/**
	 * 取得某方法的已攔截請求
	 *
	 * @param string $method SOAP 方法或 searchGoods
	 * @return array<int, array{method: string, url: string, body: string, params: array<string, string>, headers: array<string, mixed>, timeout: mixed}>
	 */
	final protected function hct_requests( string $method ): array {
		return \array_values( \array_filter( $this->hct_requests, static fn( array $r ): bool => $r['method'] === $method ) );
	}

	/**
	 * 取得某方法第 N 次請求的 json 參數（已解碼）
	 *
	 * @param string $method SOAP 方法
	 * @param int    $index  第幾次
	 * @return array<int|string, mixed>
	 */
	final protected function hct_request_json( string $method, int $index = 0 ): array {
		$json    = $this->hct_requests( $method )[ $index ]['params']['json'] ?? '';
		$decoded = \json_decode( $json, true );
		return \is_array( $decoded ) ? $decoded : [];
	}

	/** @return void 掛上攔截器（冪等） */
	private function hook_hct_http(): void {
		if ( $this->hct_http_hooked ) {
			return;
		}
		\add_filter( 'pre_http_request', [ $this, 'intercept_hct_http' ], 10, 3 );
		$this->hct_http_hooked = true;
	}

	/** @return void 移除攔截器、清空紀錄、清設定 / 號段 / 快取 */
	final protected function reset_hct_logistics(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_hct_http' ], 10 );
		$this->hct_http_hooked = false;
		$this->hct_queues      = [];
		$this->hct_requests    = [];
		\delete_option( ProviderUtils::get_option_name( HctLogisticsProvider::ID ) );
		\delete_option( WaybillNumberAllocator::RANGE_OPTION_PREFIX . HctLogisticsProvider::ID );
		\delete_option( WaybillNumberAllocator::CURRENT_OPTION_PREFIX . HctLogisticsProvider::ID );
		\delete_transient( HctLogisticsProvider::STATUS_CACHE_PREFIX . self::$hct_edelno );
		HctLogisticsSettingsDTO::reset();
	}

	/**
	 * pre_http_request 攔截器
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt     既有 preempt
	 * @param array<string, mixed>                 $parsed_args 請求參數
	 * @param string                               $url         URL
	 * @return false|array<string, mixed>|\WP_Error
	 */
	final public function intercept_hct_http( $preempt, array $parsed_args, string $url ) {
		$host = (string) \wp_parse_url( $url, PHP_URL_HOST );
		if ( ! \str_ends_with( $host, 'hct.com.tw' ) ) {
			return $preempt;
		}

		$headers = \is_array( $parsed_args['headers'] ?? null ) ? $parsed_args['headers'] : [];
		$body    = \is_string( $parsed_args['body'] ?? null ) ? $parsed_args['body'] : '';
		$method  = 'searchGoods';
		if ( \str_contains( $url, '.asmx' ) ) {
			$action = (string) ( $headers['SOAPAction'] ?? '' );
			$method = (string) \preg_replace( '#^"?http://tempuri\.org/([A-Za-z_]+)"?$#', '$1', $action );
		}

		$params = [];
		\preg_match_all( '#<(company|password|json)>(.*?)</\1>#s', $body, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$params[ $match[1] ] = \html_entity_decode( $match[2], ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		}

		$request              = [
			'method'  => $method,
			'url'     => $url,
			'body'    => $body,
			'params'  => $params,
			'headers' => $headers,
			'timeout' => $parsed_args['timeout'] ?? null,
		];
		$this->hct_requests[] = $request;

		if ( ! isset( $this->hct_queues[ $method ] ) ) {
			return new \WP_Error( 'http_blocked_in_test', "測試中禁止外部請求：{$url}" );
		}

		$queue    = &$this->hct_queues[ $method ];
		$response = \count( $queue ) > 1 ? \array_shift( $queue ) : \reset( $queue );
		if ( \is_callable( $response ) ) {
			$response = $response( $request );
		}
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		$status = 200;
		if ( \is_array( $response ) ) {
			$status   = (int) ( $response['status'] ?? 200 );
			$response = (string) ( $response['body'] ?? '' );
		} else {
			$sent     = \json_decode( $params['json'] ?? '', true );
			$first    = \is_array( $sent ) && \is_array( $sent[0] ?? null ) ? $sent[0] : [];
			$response = $this->hct_soap_envelope(
				$method,
				$this->hct_fixture(
					(string) $response,
					[
						'__EDELNO__' => (string) ( $first['edelno'] ?? '' ) !== '' ? (string) $first['edelno'] : self::$hct_edelno,
						'__EPINO__'  => (string) ( $first['epino'] ?? '' ),
						'__IMAGE__'  => $this->hct_label_hex(),
					]
				)
			);
		}

		return [
			'headers'  => [ 'content-type' => 'text/xml; charset=utf-8' ],
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
	 * 測試端獨立實作的貨況加密（DES-CBC 以 3DES-EDE 三重相同金鑰等價實作）
	 *
	 * @param string $plain       明文
	 * @param int    $offset_days 金鑰日期偏移
	 * @return string Base64
	 */
	final protected function hct_track_encrypt( string $plain, int $offset_days = 235 ): string {
		$key_date = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Taipei' ) ) )->modify( "{$offset_days} days" )->format( 'Ymd' );
		return \base64_encode( (string) \openssl_encrypt( $plain, 'des-ede3-cbc', \str_repeat( $key_date, 3 ), OPENSSL_RAW_DATA, self::$hct_track_iv ) );
	}

	/**
	 * 組 searchGoods 解密後的回傳 XML
	 *
	 * @param array<int, array{wrktime: string, status: string, status_code?: string, category?: string}> $events 貨況（空 = <rlist />）
	 * @param string                                                                                           $edelno 貨號
	 * @return string
	 */
	final protected function hct_track_xml( array $events, string $edelno = '' ): string {
		$edelno = '' !== $edelno ? $edelno : self::$hct_edelno;
		if ( [] === $events ) {
			return '<?xml version="1.0" encoding="UTF-8"?><rlist />';
		}
		$rows = '';
		foreach ( $events as $event ) {
			$code     = isset( $event['status_code'] ) ? " status_code=\"{$event['status_code']}\"" : '';
			$category = isset( $event['category'] ) ? " category=\"{$event['category']}\"" : '';
			$rows    .= "<order orderid=\"{$edelno}\" wrktime=\"{$event['wrktime']}\"{$category} status=\"{$event['status']}\"{$code} />";
		}
		return "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rlist><orders ordersid=\"{$edelno}\">{$rows}</orders></rlist>";
	}

	/**
	 * 建立新竹宅配測試訂單（台灣地址、收件手機）
	 *
	 * @param array<string, mixed> $args 覆寫（phone / payment_method / total / weight）
	 * @return \WC_Order
	 */
	final protected function make_hct_order( array $args = [] ): \WC_Order {
		$order   = \wc_create_order();
		$product = new \WC_Product_Simple();
		$product->set_name( '新竹測試商品' );
		$product->set_regular_price( (string) ( $args['total'] ?? 1000 ) );
		if ( isset( $args['weight'] ) ) {
			$product->set_weight( (string) $args['weight'] );
		}
		$product->save();
		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_name( '新竹測試商品' );
		$item->set_quantity( (int) ( $args['qty'] ?? 1 ) );
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
		$order->set_shipping_city( (string) ( $args['city'] ?? '台中市大雅區' ) );
		$order->set_shipping_postcode( '428' );
		$order->set_shipping_address_1( (string) ( $args['address'] ?? '中清路三段513號' ) );
		$order->set_payment_method( (string) ( $args['payment_method'] ?? 'bacs' ) );
		$order->set_total( (string) ( $args['total'] ?? 1000 ) );
		$order->set_status( 'processing' );
		$order->save();
		return $order;
	}
}
