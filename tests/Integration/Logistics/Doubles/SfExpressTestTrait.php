<?php

declare( strict_types=1 );

namespace Tests\Integration\Logistics\Doubles;

use J7\PowerCheckout\Domains\Logistics\SfExpress\DTOs\SfExpressSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\SfExpressProvider;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfAccessTokenStore;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/**
 * 順豐（sf_express）測試共用 helper
 *
 * - 攔截 `*.sf.global`：
 *   - `GET /openapi/api/token` → 佇列 `token`（預設回 token_ok）
 *   - `POST /openapi/api/dispatch` → 依 header msgType 分派佇列；請求 body 以**測試端獨立**的 openssl 實作解密記錄（不經被測 SfCrypto）
 * - 攔截 `*.sf-express.com`（面單 PDF 下載）→ 佇列 `download`
 * - 回應元素：
 *   - string：fixture 名（Fixtures/sf_express/{name}.json）；dispatch 時視為「解密後的業務 JSON」，自動加密包成 apiResultCode=0 外殼
 *   - array{envelope: string}：fixture 名，原樣當作外殼回傳（平台層錯誤）
 *   - array{status: int, body: string}：原文
 *   - \WP_Error / callable(array $req): mixed
 *   佇列取完重複最後一筆；未設定一律 WP_Error（禁止外部請求）
 * - 憑證全為假值（非官方公開 sandbox 憑證、非真實帳號）；fixture 回應形狀取自 skill 文件與 2026-11-21 公開 sandbox 唯讀實打。
 *
 * 使用端須在 tear_down 呼叫 reset_sf_express()。
 */
trait SfExpressTestTrait {

	/** @var string 假 appKey */
	protected static string $sf_app_key = 'pcfakeappkey00000000000000000001';

	/** @var string 假 appSecret */
	protected static string $sf_app_secret = 'pc-fake-app-secret-0001';

	/** @var string 假 AES 金鑰（43 字元；= base64(32 個 'P') 去尾 =） */
	protected static string $sf_aes_key = 'UFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFA';

	/** @var string 假客戶編碼 */
	protected static string $sf_customer_code = 'PCFAKECUST01';

	/** @var string 假月結卡號 */
	protected static string $sf_month_card = '0000000001';

	/** @var string 假推送簽章金鑰 */
	protected static string $sf_push_key = 'pc-fake-push-key';

	/** @var string 測試 token */
	protected static string $sf_token = 'auth_pc-fake-token-0001';

	/** @var string golden 順豐運單號 */
	protected static string $sf_waybill = 'SF1334452877497';

	/** @var array<string, array<int, mixed>> 佇列（msgType / token / download） */
	protected array $sf_queues = [];

	/** @var array<int, array{type: string, url: string, method: string, headers: array<string, mixed>, raw_body: string, body: array<int|string, mixed>|null, timeout: mixed}> 已攔截請求 */
	protected array $sf_requests = [];

	/** @var bool 攔截器是否已掛上 */
	private bool $sf_http_hooked = false;

	/**
	 * 啟用順豐（test 模式 + 假憑證 + 完整寄件人）
	 *
	 * @param array<string, mixed> $extra 額外設定
	 * @return void
	 */
	final protected function setup_sf_express( array $extra = [] ): void {
		\update_option( 'woocommerce_currency', 'TWD' );
		\delete_option( ProviderUtils::get_option_name( SfExpressProvider::ID ) );
		ProviderUtils::update_option(
			SfExpressProvider::ID,
			\array_merge(
				[
					'enabled'           => 'yes',
					'mode'              => 'test',
					'app_key'           => self::$sf_app_key,
					'app_secret'        => self::$sf_app_secret,
					'encoding_aes_key'  => self::$sf_aes_key,
					'customer_code'     => self::$sf_customer_code,
					'month_card'        => self::$sf_month_card,
					'product_code'      => 'INT0005',
					'push_sign_key'     => self::$sf_push_key,
					'sender_name'       => '王大明',
					'sender_mobile'     => '0912-000-111',
					'sender_postcode'   => '100',
					'sender_address'    => '臺北市中正區重慶南路一段1號',
					'sender_cargo_type' => '2',
				],
				$extra
			)
		);
		SfExpressSettingsDTO::reset();
		$this->flush_sf_token();
		ProviderUtils::$container[ SfExpressProvider::ID ] = SfExpressProvider::instance();
	}

	/** @return void 清 token 快取（兩種模式） */
	final protected function flush_sf_token(): void {
		foreach ( [ 'test', 'prod' ] as $mode ) {
			\delete_transient( SfAccessTokenStore::cache_key( $mode, self::$sf_app_key ) );
		}
	}

	/**
	 * 讀 fixture 原文並替換佔位
	 *
	 * @param string                $name         檔名（省略副檔名預設 .json）
	 * @param array<string, string> $replacements 佔位 => 值
	 * @return string
	 */
	final protected function sf_fixture( string $name, array $replacements = [] ): string {
		$file = \str_contains( $name, '.' ) ? $name : "{$name}.json";
		$raw  = (string) \file_get_contents( __DIR__ . "/../Fixtures/sf_express/{$file}" );
		return \trim(
			\strtr(
				$raw,
				\array_merge(
					[
						'__WAYBILL__' => self::$sf_waybill,
						'__TOKEN__'   => self::$sf_token,
					],
					$replacements
				)
			)
		);
	}

	/**
	 * 測試端獨立實作的加密（random16 + N 長度 + 明文 + appKey，PKCS7 block 32，AES-256-CBC，IV = key 前 16 bytes）
	 *
	 * @param string $plain   明文
	 * @param string $app_key appKey
	 * @param string $aes_key encodingAesKey
	 * @return string base64
	 */
	final protected function sf_test_encrypt( string $plain, string $app_key = '', string $aes_key = '' ): string {
		$app_key = '' !== $app_key ? $app_key : self::$sf_app_key;
		$key     = (string) \base64_decode( ( '' !== $aes_key ? $aes_key : self::$sf_aes_key ) . '=' );
		$data    = 'ABCDEFGHIJKLMNOP' . \pack( 'N', \strlen( $plain ) ) . $plain . $app_key;
		$pad     = 32 - ( \strlen( $data ) % 32 );
		$data   .= \str_repeat( \chr( $pad ), $pad );
		return \base64_encode( (string) \openssl_encrypt( $data, 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, \substr( $key, 0, 16 ) ) );
	}

	/**
	 * 測試端獨立實作的解密（不驗 appKey，只回明文）
	 *
	 * @param string $b64 base64 密文
	 * @return string
	 */
	final protected function sf_test_decrypt( string $b64 ): string {
		$key   = (string) \base64_decode( self::$sf_aes_key . '=' );
		$plain = (string) \openssl_decrypt( (string) \base64_decode( $b64 ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, \substr( $key, 0, 16 ) );
		if ( '' === $plain ) {
			return '';
		}
		$pad   = \ord( \substr( $plain, -1 ) );
		$plain = \substr( $plain, 0, \strlen( $plain ) - ( $pad >= 1 && $pad <= 32 ? $pad : 0 ) );
		$len   = (int) ( \unpack( 'N', \substr( $plain, 16, 4 ) )[1] ?? 0 );
		return \substr( $plain, 20, $len );
	}

	/**
	 * 推送 sign（測試端獨立實作：Base64(MD5(key + body + key))）
	 *
	 * @param string $body 原始 body
	 * @param string $key  金鑰（空 = 測試金鑰）
	 * @return string
	 */
	final protected function sf_push_sign( string $body, string $key = '' ): string {
		$key = '' !== $key ? $key : self::$sf_push_key;
		return \base64_encode( \md5( $key . $body . $key, true ) );
	}

	/**
	 * 設定某佇列的回應
	 *
	 * @param string $type         msgType / token / download
	 * @param mixed  ...$responses 回應
	 * @return void
	 */
	final protected function fake_sf( string $type, mixed ...$responses ): void {
		$this->sf_queues[ $type ] = $responses;
		if ( 'token' !== $type && ! isset( $this->sf_queues['token'] ) ) {
			$this->sf_queues['token'] = [ 'token_ok' ];
		}
		$this->hook_sf_http();
	}

	/** 只掛攔截器（全部請求擋下並記錄），用於斷言「不打 API」 @return void */
	final protected function block_sf_http(): void {
		$this->hook_sf_http();
	}

	/**
	 * 取得某類型的已攔截請求
	 *
	 * @param string $type msgType / token / download
	 * @return array<int, array{type: string, url: string, method: string, headers: array<string, mixed>, raw_body: string, body: array<int|string, mixed>|null, timeout: mixed}>
	 */
	final protected function sf_requests( string $type ): array {
		return \array_values( \array_filter( $this->sf_requests, static fn( array $r ): bool => $r['type'] === $type ) );
	}

	/**
	 * 取得某 msgType 第 N 次請求的明文 body
	 *
	 * @param string $msg_type msgType
	 * @param int    $index    第幾次
	 * @return array<int|string, mixed>
	 */
	final protected function sf_request_body( string $msg_type, int $index = 0 ): array {
		return $this->sf_requests( $msg_type )[ $index ]['body'] ?? [];
	}

	/** @return void 掛上攔截器（冪等） */
	private function hook_sf_http(): void {
		if ( $this->sf_http_hooked ) {
			return;
		}
		\add_filter( 'pre_http_request', [ $this, 'intercept_sf_http' ], 10, 3 );
		$this->sf_http_hooked = true;
	}

	/** @return void 移除攔截器、清空紀錄、清設定 / token 快取 */
	final protected function reset_sf_express(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_sf_http' ], 10 );
		$this->sf_http_hooked = false;
		$this->sf_queues      = [];
		$this->sf_requests    = [];
		$this->flush_sf_token();
		\delete_option( ProviderUtils::get_option_name( SfExpressProvider::ID ) );
		SfExpressSettingsDTO::reset();
	}

	/**
	 * pre_http_request 攔截器
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt     既有 preempt
	 * @param array<string, mixed>                 $parsed_args 請求參數
	 * @param string                               $url         URL
	 * @return false|array<string, mixed>|\WP_Error
	 */
	final public function intercept_sf_http( $preempt, array $parsed_args, string $url ) {
		$host = (string) \wp_parse_url( $url, PHP_URL_HOST );
		if ( ! \str_ends_with( $host, 'sf.global' ) && ! \str_ends_with( $host, 'sf-express.com' ) ) {
			return $preempt;
		}

		$headers  = \is_array( $parsed_args['headers'] ?? null ) ? $parsed_args['headers'] : [];
		$raw_body = \is_string( $parsed_args['body'] ?? null ) ? $parsed_args['body'] : '';
		$path     = (string) \wp_parse_url( $url, PHP_URL_PATH );
		$type     = 'download';
		$body     = null;
		if ( \str_ends_with( $host, 'sf.global' ) ) {
			if ( \str_ends_with( $path, '/token' ) ) {
				$type = 'token';
			} else {
				$type    = (string) ( $headers['msgType'] ?? '' );
				$decoded = \json_decode( $this->sf_test_decrypt( $raw_body ), true );
				$body    = \is_array( $decoded ) ? $decoded : null;
			}
		}

		$request             = [
			'type'     => $type,
			'url'      => $url,
			'method'   => (string) ( $parsed_args['method'] ?? 'GET' ),
			'headers'  => $headers,
			'raw_body' => $raw_body,
			'body'     => $body,
			'timeout'  => $parsed_args['timeout'] ?? null,
		];
		$this->sf_requests[] = $request;

		if ( ! isset( $this->sf_queues[ $type ] ) ) {
			return new \WP_Error( 'http_blocked_in_test', "測試中禁止外部請求：{$url}" );
		}

		$queue    = &$this->sf_queues[ $type ];
		$response = \count( $queue ) > 1 ? \array_shift( $queue ) : \reset( $queue );
		if ( \is_callable( $response ) ) {
			$response = $response( $request );
		}
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		$status = 200;
		if ( \is_array( $response ) && isset( $response['envelope'] ) ) {
			$text = $this->sf_fixture( (string) $response['envelope'] );
		} elseif ( \is_array( $response ) ) {
			$status = (int) ( $response['status'] ?? 200 );
			$text   = (string) ( $response['body'] ?? '' );
		} elseif ( 'token' === $type || 'download' === $type ) {
			$text = $this->sf_fixture( (string) $response );
		} else {
			$business = $this->sf_fixture(
				(string) $response,
				[ '__ORDER_NO__' => (string) ( \is_array( $body ) ? ( $body['customerOrderNo'] ?? '' ) : '' ) ]
			);
			$text     = (string) \wp_json_encode(
				[
					'apiResultCode' => 0,
					'apiErrorMsg'   => null,
					'apiResultData' => $this->sf_test_encrypt( $business ),
					'apiTimestamp'  => 1795198063063,
				]
			);
		}

		return [
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => $text,
			'response' => [
				'code'    => $status,
				'message' => 200 === $status ? 'OK' : 'Error',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * 建立順豐宅配測試訂單（台中市大雅區、收件手機）
	 *
	 * @param array<string, mixed> $args 覆寫（phone / payment_method / total / weight / city / postcode / state）
	 * @return \WC_Order
	 */
	final protected function make_sf_order( array $args = [] ): \WC_Order {
		$order   = \wc_create_order();
		$product = new \WC_Product_Simple();
		$product->set_name( '順豐測試商品' );
		$product->set_regular_price( (string) ( $args['total'] ?? 1000 ) );
		if ( isset( $args['weight'] ) ) {
			$product->set_weight( (string) $args['weight'] );
		}
		$product->save();
		$item = new \WC_Order_Item_Product();
		$item->set_product( $product );
		$item->set_name( '順豐測試商品' );
		$item->set_quantity( (int) ( $args['qty'] ?? 1 ) );
		$item->set_subtotal( (string) ( $args['total'] ?? 1000 ) );
		$item->set_total( (string) ( $args['total'] ?? 1000 ) );
		$order->add_item( $item );

		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
		$order->set_billing_phone( (string) ( $args['phone'] ?? '0912345678' ) );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_shipping_first_name( '小明' );
		$order->set_shipping_last_name( '王' );
		$order->set_shipping_country( 'TW' );
		$order->set_shipping_state( (string) ( $args['state'] ?? '' ) );
		$order->set_shipping_city( (string) ( $args['city'] ?? '台中市大雅區' ) );
		$order->set_shipping_postcode( (string) ( $args['postcode'] ?? '428' ) );
		$order->set_shipping_address_1( (string) ( $args['address'] ?? '中清路三段513號' ) );
		$order->set_payment_method( (string) ( $args['payment_method'] ?? 'bacs' ) );
		$order->set_shipping_total( (string) ( $args['shipping_total'] ?? 0 ) );
		$order->set_total( (string) ( $args['total'] ?? 1000 ) );
		$order->set_status( 'processing' );
		$order->save();
		return $order;
	}
}
