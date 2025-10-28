<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

trait AmountTrait {
	/** @var Components\Amount *金額 */
	public Components\Amount $amount;
}
