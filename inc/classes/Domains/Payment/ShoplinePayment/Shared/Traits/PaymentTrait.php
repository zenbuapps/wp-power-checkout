<?php

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Webhook\Payment;

trait PaymentTrait {
	/** @var Payment *訂單付款資訊 */
	public Payment $payment;
}
