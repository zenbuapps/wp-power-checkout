<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\CancelInvoiceParamsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\IssueInvoiceParamsDTO;
use J7\WpUtils\Classes\DTO;

/**
 * API endpoint
 */
enum EApi: string {
	case ISSUE  = '/json/f0401';
	case CANCEL = '/json/f0501';

	/** @return string 標籤 */
	public function label(): string {
		return match ($this) {
			self::ISSUE => '開立發票',
			self::CANCEL => '作廢發票'
		};
	}

	/** 準備請求參數 */
	public function prepare_request_param( \WC_Order $order ): DTO {
		return match ($this) {
			self::ISSUE => IssueInvoiceParamsDTO::create( $order),
			self::CANCEL => new CancelInvoiceParamsDTO(
			[
				'orders' => [ $order ],
			]
				)
		};
	}
}
