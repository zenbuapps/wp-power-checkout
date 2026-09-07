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

	/** @var RedirectSettingsDTO 設定 */
	public RedirectSettingsDTO $settings;

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

		/** @var array<string, mixed>|array{code: int, msg: string} $response_body */
		$response_body = \json_decode( \wp_remote_retrieve_body( $response ), true );
		// LOG 記錄
		$this->gateway->logger(
				"{$this->gateway->title} {$endpoint} 請求參數 #{$this->order->get_id()}",
				'info',
				[
					'api_url'        => $api_url,
					'request_header' => $request_header,
					'request_body'   => $request_body,
				],
				);

		if ( isset( $response_body['code'] ) ) {
			$error = ErrorCode::tryFrom( (string) $response_body['code'] );
			$this->gateway->logger(
				"❌ {$this->gateway->title} {$endpoint} 請求失敗 #{$this->order->get_id()}",
				'error',
				$error ? [
					'code' => $error->value,
					'msg'  => $error->label(),
				] : $response_body
				);

			throw new \Exception( (string) ( $error ? $error->label() : $response_body['msg'] ), (int) ( $error ? $error->value : $response_body['code'] ) );
		}

		$this->gateway->logger(
				"✅ {$this->gateway->title} {$endpoint} 發送請求成功 #{$this->order->get_id()}",
				'info',
				$response_body
				);

		return $response_body;
	}

	/** 取得 API 端點 @param string $endpoint 端點 /trade/payment/create @return string 端點 */
	public function get_endpoint( string $endpoint ): string {
		return $this->settings->apiUrl . self::API_VERSION . $endpoint;
	}
}
