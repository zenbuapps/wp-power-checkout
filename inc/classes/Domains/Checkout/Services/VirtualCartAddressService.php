<?php
/**
 * F4（欄位面）：購物車全為虛擬商品時隱藏帳單地址欄位
 *
 * - 傳統結帳：woocommerce_checkout_fields 直接移除 billing 的 6 個地址欄位（含 country）
 * - 區塊結帳：woocommerce_get_country_locale 在「明確的 TW key」把 state/city/postcode/address_1/address_2
 *   設為 hidden=true、required=false（D15：WC 預設 locale 沒有 TW；default key 會被 WC 覆寫，不依賴）
 *   country 不可用 locale 隱藏（會清空並擋單），改於區塊結帳頁輸出 CSS 隱藏
 *
 * 所有欄位 hook priority 1000：晚於 F2 縣市下拉等設定，確保隱藏最後套用（R4）。
 * locale 快取重設（R3 坑 2）：woocommerce_cart_loaded_from_session 與
 * woocommerce_store_api_checkout_update_order_from_request（priority 1，早於送單驗證）清空 WC()->countries->locale。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout\Services;

use J7\PowerCheckout\Domains\Checkout\DTOs\CheckoutEnhancementSettingsDTO;
use J7\PowerCheckout\Domains\Checkout\Shared\Helpers\CartInspector;
use J7\PowerCheckout\Plugin;

/** 虛擬商品購物車隱藏地址 */
final class VirtualCartAddressService {

	/** @var string 設定開關鍵 */
	private const SETTING_KEY = 'virtual_hide_address';

	/** @var int hook priority（晚於其他欄位調整，確保隱藏最後套用） */
	private const PRIORITY = 1000;

	/** @var int locale 快取重設 priority（早於送單驗證 / 其他監聽者） */
	private const LOCALE_RESET_PRIORITY = 1;

	/** @var string 區塊結帳 locale 套用的國家（D15：必須明確寫入 TW key） */
	private const BLOCK_COUNTRY = 'TW';

	/** @var array<int, string> 傳統結帳移除的帳單欄位（不含前綴） */
	private const CLASSIC_HIDDEN_FIELDS = [ 'country', 'state', 'city', 'postcode', 'address_1', 'address_2' ];

	/** @var array<int, string> 區塊結帳以 locale 隱藏的欄位（country 除外，D15） */
	private const BLOCK_HIDDEN_FIELDS = [ 'state', 'city', 'postcode', 'address_1', 'address_2' ];

	/** @var string 區塊結帳隱藏國家欄位的 CSS */
	private const BLOCK_COUNTRY_CSS = '.wc-block-components-address-form .wc-block-components-country-input{display:none !important}';

	/**
	 * 註冊 hooks（無條件掛上，開關於 callback 內判斷）
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\add_filter( 'woocommerce_checkout_fields', [ __CLASS__, 'filter_checkout_fields' ], self::PRIORITY );
		\add_filter( 'woocommerce_get_country_locale', [ __CLASS__, 'filter_country_locale' ], self::PRIORITY );
		\add_action( 'wp_head', [ __CLASS__, 'print_block_country_css' ], self::PRIORITY );
		// R3 坑 2：WC_Countries::$locale 會快取，若早於購物車載入就算過會是「非全虛擬」版本 → 依已載入的購物車重算
		\add_action( 'woocommerce_cart_loaded_from_session', [ __CLASS__, 'reset_country_locale' ], self::LOCALE_RESET_PRIORITY );
		\add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'reset_country_locale' ], self::LOCALE_RESET_PRIORITY );
	}

	/**
	 * 重設 WC()->countries 的 locale 快取，讓之後的欄位顯示 / 送單驗證依已載入的購物車重算
	 *
	 * 不判斷開關：重設只會讓 WC 下次重新計算（含本類別的 filter），開關關閉時結果與原本相同。
	 *
	 * @return void
	 */
	public static function reset_country_locale(): void {
		try {
			if ( !\function_exists( 'WC' ) ) {
				return;
			}

			\WC()->countries->locale = [];
		} catch ( \Throwable $e ) {
			Plugin::logger( "VirtualCartAddressService::reset_country_locale 失敗：{$e->getMessage()}", 'error' );
		}
	}

	/**
	 * 傳統結帳：全虛擬時移除帳單地址欄位
	 *
	 * @param mixed $fields 結帳欄位（billing / shipping / account / order）
	 * @return mixed 調整後的欄位（例外時回傳原值）
	 */
	public static function filter_checkout_fields( mixed $fields ): mixed {
		try {
			if ( !\is_array( $fields ) || !\is_array( $fields['billing'] ?? null ) || !self::should_hide() ) {
				return $fields;
			}

			foreach ( self::CLASSIC_HIDDEN_FIELDS as $field ) {
				unset( $fields['billing'][ "billing_{$field}" ] );
			}

			return $fields;
		} catch ( \Throwable $e ) {
			Plugin::logger( "VirtualCartAddressService::filter_checkout_fields 失敗：{$e->getMessage()}", 'error' );
			return $fields;
		}
	}

	/**
	 * 區塊結帳：全虛擬時於 TW locale 把地址欄位設為隱藏且非必填
	 *
	 * @param mixed $locale 各國 locale 設定
	 * @return mixed 調整後的 locale（例外時回傳原值）
	 */
	public static function filter_country_locale( mixed $locale ): mixed {
		try {
			if ( !\is_array( $locale ) || !self::should_hide() ) {
				return $locale;
			}

			$country = \is_array( $locale[ self::BLOCK_COUNTRY ] ?? null ) ? $locale[ self::BLOCK_COUNTRY ] : [];

			foreach ( self::BLOCK_HIDDEN_FIELDS as $field ) {
				$current           = \is_array( $country[ $field ] ?? null ) ? $country[ $field ] : [];
				$country[ $field ] = \array_merge(
					$current,
					[
						'hidden'   => true,
						'required' => false,
					]
				);
			}

			$locale[ self::BLOCK_COUNTRY ] = $country;
			return $locale;
		} catch ( \Throwable $e ) {
			Plugin::logger( "VirtualCartAddressService::filter_country_locale 失敗：{$e->getMessage()}", 'error' );
			return $locale;
		}
	}

	/**
	 * 區塊結帳頁 + 開關開 + 全虛擬時，輸出隱藏國家欄位的 CSS（D15）
	 *
	 * @return void
	 */
	public static function print_block_country_css(): void {
		try {
			if ( !CartInspector::is_block_checkout() || !self::should_hide() ) {
				return;
			}

			$css  = self::BLOCK_COUNTRY_CSS;
			$html = <<<HTML
<style id="pc-virtual-hide-country">{$css}</style>

HTML;
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 內容為類別常數，非使用者輸入
		} catch ( \Throwable $e ) {
			Plugin::logger( "VirtualCartAddressService::print_block_country_css 失敗：{$e->getMessage()}", 'error' );
		}
	}

	/**
	 * 是否應隱藏地址欄位（開關開 且 購物車全為虛擬商品）
	 *
	 * @return bool 是否隱藏
	 */
	private static function should_hide(): bool {
		return CheckoutEnhancementSettingsDTO::is_on( self::SETTING_KEY ) && CartInspector::is_all_virtual_cart();
	}
}
