<?php

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits;

trait ActionTypeTrait {
	/** @var string 'SDK' 指示下一步動作 (16) 參考，對應 nextAction 欄位處理方式 選填 */
	public string $actionType;
}
