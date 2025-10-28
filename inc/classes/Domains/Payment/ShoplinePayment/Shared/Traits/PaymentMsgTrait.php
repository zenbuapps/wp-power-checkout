<?php

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\ErrorMessage;

trait PaymentMsgTrait {
	/** @var ErrorMessage|null 支付錯誤訊息 (ErrorMessage) 選填 */
	public ErrorMessage|null $paymentMsg = null;
}
