<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Helpers;

use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RequestHeader;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ErrorCode;


/**
 * Requester 請求器
 * 用來發請求 & 格式化回應
 * 預先填好 Header
 *
 * @see https://docs.shoplinepayments.com/guide/session/
 *  */
final class Requester {

	private const API_VERSION = '/api/v1';

	private const TIMEOUT = 60;

	/** @var array<int, string> 寫入 log 前須遮罩的欄位（小寫比對；實際送出的 header / body 不受影響） */
	private const SECRET_KEYS = [ 'apikey', 'signkey', 'clientkey' ];

	/** @var int 遮罩後保留的尾碼數 */
	private const MASK_KEEP_TAIL = 4;

	/** @var RedirectSettingsDTO 設定 */
	public RedirectSettingsDTO $settings;

	/**
	 * @var string 最近一次回應的原始 body
	 * 供需要「原樣轉傳」的欄位使用（例：內嵌式 nextAction，官方明示不可解析），
	 * 避免 json_decode(assoc) 再 encode 時把空物件 {} 變成 []
	 */
	public string $last_response_body = '';

	/** Constructor */
	public function __construct(
		private readonly AbstractPaymentGateway $gateway,
		private readonly \WC_Order $order
	) {
		$this->settings = RedirectSettingsDTO::instance();
		if (!$this->gateway->order) {
			$this->gateway->order = $this->order;
		}
	}

	/**
	 * 發送請求
	 *
	 *  @param string               $endpoint 端點
	 *  @param array<string, mixed> $request_body 請求參數
	 *  @param int|null             $timeout 逾時秒數，null 時使用預設值 self::TIMEOUT。
	 *                                       前台同步路徑（例如 order-received 導回查詢）必須傳入較短的
	 *                                       逾時，避免客戶盯著白畫面 60 秒。
	 *  @return array<string, mixed> Response Body
	 *  @throws \Exception 發生錯誤時拋出
	 */
	public function post( string $endpoint, array $request_body = [], ?int $timeout = null ): array {
		$api_url = $this->get_endpoint( $endpoint );

		$request_header = RequestHeader::create( $this->order )->to_array();

		$json_body = \wp_json_encode( $request_body );
		if (!\is_string($json_body)) {
			throw new \Exception('Failed to encode request body');
		}
		$response = \wp_remote_post(
			$api_url,
			[
				'body'     => $json_body,
				'headers'  => $request_header,
				'blocking' => true,
				'timeout'  => $timeout ?? self::TIMEOUT,
			]
			);

		if ( \is_wp_error( $response ) ) {
			throw new \Exception( $response->get_error_message() );
		}

		$this->last_response_body = \wp_remote_retrieve_body( $response );

		/** @var array<string, mixed>|array{code: int, msg: string} $response_body */
		$response_body = \json_decode( $this->last_response_body, true );
		// LOG 記錄
		$this->gateway->logger(
				"{$this->gateway->title} {$endpoint} 請求參數 #{$this->order->get_id()}",
				'info',
				self::mask_secrets(
					[
						'api_url'        => $api_url,
						'request_header' => $request_header,
						'request_body'   => $request_body,
					]
				),
				);

		if ( isset( $response_body['code'] ) ) {
			$error = ErrorCode::tryFrom( (string) $response_body['code'] );
			$this->gateway->logger(
				"❌ {$this->gateway->title} {$endpoint} 請求失敗 #{$this->order->get_id()}",
				'error',
				$error ? [
					'code' => $error->value,
					'msg'  => $error->label(),
				] : self::mask_secrets( $response_body )
				);

			throw new \Exception( (string) ( $error ? $error->label() : $response_body['msg'] ), (int) ( $error ? $error->value : $response_body['code'] ) );
		}

		$this->gateway->logger(
				"✅ {$this->gateway->title} {$endpoint} 發送請求成功 #{$this->order->get_id()}",
				'info',
				self::mask_secrets( $response_body )
				);

		return $response_body;
	}

	/**
	 * 遮罩 log 內容中的密鑰（apiKey / signKey / clientKey，鍵名不分大小寫、遞迴），只留後 4 碼
	 *
	 * WC log 可被有 WooCommerce 狀態頁權限的人讀取，商店密鑰不可完整寫入（P8 fix #2）。
	 *
	 * @param array<mixed> $data 要寫進 log 的資料
	 * @return array<mixed> 遮罩後的副本
	 */
	public static function mask_secrets( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( \is_array( $value ) ) {
				$data[ $key ] = self::mask_secrets( $value );
				continue;
			}

			if ( \is_string( $key ) && \in_array( \strtolower( $key ), self::SECRET_KEYS, true ) && \is_scalar( $value ) ) {
				$data[ $key ] = self::mask_value( (string) $value );
			}
		}

		return $data;
	}

	/**
	 * 單一密鑰遮罩：長度 ≤ 8 全遮（避免短值被反推），其餘保留後 4 碼
	 *
	 * @param string $value 密鑰
	 * @return string
	 */
	private static function mask_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		if ( \strlen( $value ) <= self::MASK_KEEP_TAIL * 2 ) {
			return '****';
		}

		return '****' . \substr( $value, -self::MASK_KEEP_TAIL );
	}

	/** 取得 API 端點 @param string $endpoint 端點 /trade/payment/create @return string 端點 */
	public function get_endpoint( string $endpoint ): string {
		return $this->settings->apiUrl . self::API_VERSION . $endpoint;
	}
}
