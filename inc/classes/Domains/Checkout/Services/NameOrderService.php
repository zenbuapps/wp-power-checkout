<?php
/**
 * F1：姓名欄位排序（姓在前、名在後）
 *
 * 只調整台灣（TW）的 country locale；傳統結帳經 get_address_fields() 套用，
 * 區塊結帳經 CartCheckoutUtils::get_country_data() 把 priority 轉成 index 傳給前端。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout\Services;

use J7\PowerCheckout\Domains\Checkout\DTOs\CheckoutEnhancementSettingsDTO;
use J7\PowerCheckout\Plugin;

/** 姓名欄位排序 */
final class NameOrderService {

	/** @var string 設定開關鍵 */
	private const SETTING_KEY = 'last_name_first';

	/** @var string 套用的國家 */
	private const COUNTRY = 'TW';

	/**
	 * 註冊 hooks（無條件掛上，開關於 callback 內判斷）
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\add_filter( 'woocommerce_get_country_locale', [ __CLASS__, 'filter_country_locale' ], 20 );
	}

	/**
	 * 開關開啟時，把 TW 的 last_name 排到 first_name 之前
	 *
	 * @param mixed $locale 各國 locale 設定
	 * @return mixed 調整後的 locale（例外時回傳原值）
	 */
	public static function filter_country_locale( mixed $locale ): mixed {
		try {
			if ( !\is_array( $locale ) || !CheckoutEnhancementSettingsDTO::is_on( self::SETTING_KEY ) ) {
				return $locale;
			}

			$tw = \is_array( $locale[ self::COUNTRY ] ?? null ) ? $locale[ self::COUNTRY ] : [];

			$tw['last_name']  = self::merge_field(
				$tw['last_name'] ?? [],
				[
					'priority' => 10,
					'class'    => [ 'form-row-first' ],
				]
			);
			$tw['first_name'] = self::merge_field(
				$tw['first_name'] ?? [],
				[
					'priority' => 20,
					'class'    => [ 'form-row-last' ],
				]
			);

			$locale[ self::COUNTRY ] = $tw;
			return $locale;
		} catch ( \Throwable $e ) {
			Plugin::logger( "NameOrderService::filter_country_locale 失敗：{$e->getMessage()}", 'error' );
			return $locale;
		}
	}

	/**
	 * 合併欄位設定（原值非陣列時視為空）
	 *
	 * @param mixed                $field    原欄位設定
	 * @param array<string, mixed> $override 覆寫值
	 * @return array<mixed> 合併後欄位設定
	 */
	private static function merge_field( mixed $field, array $override ): array {
		return \array_merge( \is_array( $field ) ? $field : [], $override );
	}
}
