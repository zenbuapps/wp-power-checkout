<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment;

/** Loader 載入付款方式 */
final class Loader {
	/** Register hooks */
	public static function register_hooks(): void {
		ShoplinePayment\Services\RedirectGatewayService::register_hooks();
		EcpayAIO\Core\Init::register_hooks();
	}
}
