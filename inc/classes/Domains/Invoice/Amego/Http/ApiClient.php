<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Http;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\IssueInvoiceResponseDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EApi;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers\Requester;
use J7\PowerCheckout\Domains\Invoice\Shared\DTOs\InvoiceParams;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoIntegration;

/**
 * 光貿電子方票
 *
 * @see https://invoice.amego.tw/api_doc/
 *  */
final class ApiClient {

	/** @var Requester 請求器 */
	private Requester $requester;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單 */
		private readonly \WC_Order $order
	) {
		$this->requester = new Requester( $this->order );
	}

	/** 開立發票 */
	public function issue(): IssueInvoiceResponseDTO|null {
		$response_dto = $this->requester->post( EApi::ISSUE );

		$meta_keys = new MetaKeys( $this->order);
		if ($response_dto) {
			$meta_keys->update_issued_data( $response_dto->to_array());
		}
		$meta_keys->update_service_id(AmegoIntegration::ID );

		return $response_dto;
	}


	/** 作廢發票 */
	public function cancel(): IssueInvoiceResponseDTO|null {
		$response_dto = $this->requester->post( EApi::CANCEL );

		if ($response_dto) {
			( new MetaKeys( $this->order) )->update_issued_data( $response_dto->to_array());
		}
		return $response_dto;
	}
}
