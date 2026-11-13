<?php
/**
 * 結帳體驗強化 domain loader
 *
 * 原則：hook 一律無條件註冊，功能開關於 callback 內以 CheckoutEnhancementSettingsDTO::is_on() 在執行期判斷，
 * 讓後台切換設定後下一個請求即生效（不受 hook 註冊時機影響）。
 * 第 1 階段僅為骨架，後續階段逐一接上各 service。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout;

/** 結帳體驗強化 ProviderRegister */
final class ProviderRegister {

	/**
	 * 註冊 hooks
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		// 後續階段於此接上各結帳強化 service 的 register_hooks()
	}
}
