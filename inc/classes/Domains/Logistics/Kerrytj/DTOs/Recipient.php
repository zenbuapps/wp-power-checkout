<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjFields;

/**
 * 訂單收件人（顧客）資料：宅配地址優先，否則帳單
 *
 * 建單時為收貨人（Consignee*）、退貨取件時為取件人（Shipper*）。只做正規化，長度與必填由呼叫端依端點規則檢查。
 */
final class Recipient {

	/**
	 * Constructor
	 *
	 * @param string $name    姓名（台灣慣例：姓 + 名）
	 * @param string $phone   電話（已去空白 / 連字號 / 括號）
	 * @param string $address 地址（縣市 + 區 + 地址）
	 * @param string $zip3    3 碼郵遞區號（推導不出為空字串）
	 */
	private function __construct(
		public readonly string $name,
		public readonly string $phone,
		public readonly string $address,
		public readonly string $zip3,
	) {
	}

	/**
	 * 由訂單取收件人
	 *
	 * @param \WC_Order $order 訂單
	 * @return self
	 */
	public static function from_order( \WC_Order $order ): self {
		$name = \trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() );
		if ('' === $name) {
			$name = \trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		}

		$phone = (string) $order->get_shipping_phone();
		if ('' === \trim( $phone )) {
			$phone = (string) $order->get_billing_phone();
		}

		$use_shipping = '' !== \trim( $order->get_shipping_address_1() );
		$parts        = $use_shipping
		? [ $order->get_shipping_state(), $order->get_shipping_city(), $order->get_shipping_address_1(), $order->get_shipping_address_2() ]
		: [ $order->get_billing_state(), $order->get_billing_city(), $order->get_billing_address_1(), $order->get_billing_address_2() ];
		$states       = \WC()->countries->get_states( 'TW' );
		if (\is_array( $states ) && isset( $states[ $parts[0] ] )) {
			$parts[0] = (string) $states[ $parts[0] ];
		}
		$address  = \trim( (string) \preg_replace( '/\s+/u', ' ', \implode( '', $parts ) ) );
		$postcode = $use_shipping ? $order->get_shipping_postcode() : $order->get_billing_postcode();
		$zip3     = KerrytjFields::zip3( (string) $postcode );
		if ('' === $zip3) {
			$zip3 = KerrytjFields::zip3_from_address( $address );
		}

		return new self( $name, KerrytjFields::phone( $phone ), $address, $zip3 );
	}
}
