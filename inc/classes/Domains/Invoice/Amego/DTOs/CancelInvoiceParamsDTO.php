<?php

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\WpUtils\Classes\DTO;


final class CancelInvoiceParamsDTO extends DTO {

	/** @var array<int,\WC_Order> $orders 訂單 array  */
	private array $orders = [];

	/**
	 * 取得公開的屬性 array
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		$invoice_number_array = [];
		foreach ($this->orders as $order) {
			if (!$order instanceof \WC_Order) {
				continue;
			}
			$params         = new MetaKeys( $order );
			$invoice_number = $params->get_issued_data('invoice_number');
			if (!$invoice_number) {
				continue;
			}
			$invoice_number_array[] = [
				'CancelInvoiceNumber' => $invoice_number,
			];
		}

		return $invoice_number_array;
	}
}
