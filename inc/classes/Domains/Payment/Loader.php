<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Refund\CreateRefundDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RegisterGateways;

/** Loader 載入付款方式 */
final class Loader {
	/** Register hooks */
	public static function register_hooks(): void {
		ShoplinePayment\Services\RegisterGateways::register_hooks();
		// EcpayAIO\Core\Init::register_hooks();

		\add_action( 'woocommerce_refund_created', [ __CLASS__, 'default_refund_reason' ], 10, 2 );
	}

	/** 修改預設的退款原因 */
	public static function default_refund_reason( int $refund_id, array $args ): void { // phpcs:ignore
		/** @var \WC_Order_Refund $refund */
		$refund = \wc_get_order($refund_id);
		$reason = $refund->get_reason();

		if ($reason) {
			return;
		}

		$new_reason = CreateRefundDTO::get_default_reason( (float) $refund->get_amount());

		$refund->set_reason($new_reason);
		$refund->save();
	}
}
