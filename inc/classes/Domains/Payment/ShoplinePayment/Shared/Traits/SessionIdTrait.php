<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits;

trait SessionIdTrait {
	/** @var string *SLP 結帳交易訂單編號 (32)*/
	public string $sessionId;
}
