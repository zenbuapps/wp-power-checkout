<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits;

trait SessionUrlTrait {
	/** @var string *結帳交易提供給顧客付款的 URL (256) */
	public string $sessionUrl;
}
