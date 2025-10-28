<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

trait RefundMsgTrait {
	/** @var Components\ErrorMessage|null 退款失敗原因 選填 */
	public Components\ErrorMessage|null $refundMsg = null;
}
