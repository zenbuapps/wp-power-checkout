<?php

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits;

trait NextActionTrait {
	/** @var mixed 指示下一步動作，特店可忽略，傳送給 SDK 即可 (NextAction) 選填 */
	public mixed $nextAction;
}
