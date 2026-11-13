<?php
/**
 * 結帳體驗強化 domain loader
 *
 * 原則：hook 一律無條件註冊，功能開關於 callback 內以 CheckoutEnhancementSettingsDTO::is_on() 在執行期判斷，
 * 讓後台切換設定後下一個請求即生效（不受 hook 註冊時機影響）。
 * 各 service 自行負責 register_hooks()，本類別只負責接上。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout;

use J7\PowerCheckout\Domains\Checkout\Services\NameOrderService;
use J7\PowerCheckout\Domains\Checkout\Services\PhoneValidationService;
use J7\PowerCheckout\Domains\Checkout\Services\TwAddressService;
use J7\PowerCheckout\Domains\Checkout\Services\VirtualCartAddressService;

/** 結帳體驗強化 ProviderRegister */
final class ProviderRegister {

	/**
	 * 註冊 hooks
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		NameOrderService::register_hooks();
		TwAddressService::register_hooks();
		VirtualCartAddressService::register_hooks();
		PhoneValidationService::register_hooks();
		// 後續階段於此接上其餘結帳強化 service 的 register_hooks()
	}
}
