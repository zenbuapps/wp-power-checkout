<?php

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\IssueInvoiceResponseDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\UniParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EApi;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;


/**
 * Requester 請求器
 * 用來發請求 & 格式化回應
 * 預先填好 Header
 *
 * @see https://invoice.amego.tw/api_doc/
 *  */
final class Requester {

	private const API_VERSION = '1.0.0';

	private const API_URL = 'https://invoice-api.amego.tw'; // 目前測試或正式都請用同一個 API 網址

	private const TIMEOUT = 60;

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $order
	) {}

	/**
	 * 發送請求
	 *
	 * @param EApi $api 要呼叫哪個 api
	 *
	 * @return IssueInvoiceResponseDTO|null 回應資料
	 */
	public function post( EApi $api ): ?IssueInvoiceResponseDTO {
		try {

			$request_body_dto = $api->prepare_request_param( $this->order );
			$uni_params       = UniParamsDTO::create( $request_body_dto );
			$api_url          = self::API_URL . $api->value;

			// LOG 記錄
			AmegoProvider::logger(
				"{$api->label()} {$api->value} 請求參數 #{$this->order->get_id()}",
				'info',
				[
					'api_url'      => $api_url,
					'request_body' => $request_body_dto->to_array(
					),
				],
			);

			$response = \wp_remote_post(
				$api_url,
				[
					'body'     => \http_build_query( $uni_params->to_array() ),
					'headers'  => [
						'Content-Type' => 'application/x-www-form-urlencoded',
					],
					'blocking' => true,
					'timeout'  => self::TIMEOUT,
				]
			);

			if ( \is_wp_error( $response ) ) {
				throw new \Exception( $response->get_error_message() );
			}

			/** @var array<string, mixed>|array{code: int, msg: string} $response_body */
			$response_body = \json_decode( \wp_remote_retrieve_body( $response ), true, 512, JSON_THROW_ON_ERROR );

			$response_dto = new IssueInvoiceResponseDTO( $response_body );
			if ( !$response_dto->is_success() ) {
				throw new \Exception( $response_dto->msg );
			}

			// LOG 記錄
			AmegoProvider::logger(
				"✅ {$api->label()} {$api->value} 成功 #{$this->order->get_id()}",
				'info',
				$response_body,
				0,
				$this->order
			);

			return $response_dto;
		} catch ( \Throwable $e ) {
			// LOG 記錄
			AmegoProvider::logger(
				"❌ {$api->label()} {$api->value} 失敗 #{$this->order->get_id()}： {$e->getMessage()}",
				'error',
				[],
				5,
				$this->order
			);
			throw new \Exception( "❌ {$api->label()} {$api->value} 失敗 #{$this->order->get_id()}： {$e->getMessage()}" );
		}
	}
}
