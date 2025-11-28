<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Http;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\IssueInvoiceResponseDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EApi;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers\Requester;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;

/**
 * 光貿電子方票
 * TODO 可以抽離為共用
 *
 * @see https://invoice.amego.tw/api_doc/
 *  */
final class ApiClient {

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單 */
		private readonly \WC_Order $order,
		/** @var Requester 請求器 */
		private readonly Requester $requester
	) {
	}

	/** 開立發票 */
	public function issue( string $provider_id ): IssueInvoiceResponseDTO|null {
		$response_dto = $this->requester->post( EApi::ISSUE );

		$meta_keys = new MetaKeys( $this->order);
		if ($response_dto) {
			$meta_keys->update_issued_data( $response_dto->to_array());
		}
		$meta_keys->update_provider_id( $provider_id );

		return $response_dto;
	}


	/** 作廢發票 */
	public function cancel(): IssueInvoiceResponseDTO|null {
		$response_dto = $this->requester->post( EApi::CANCEL );

		if ($response_dto) {
			$meta_keys = new MetaKeys( $this->order);
			$meta_keys->clear_data();
			$meta_keys->update_cancelled_data( $response_dto->to_array());
		}
		return $response_dto;
	}
}
