<?php
/**
 * F2：台灣縣市下拉 + 傳統結帳鄉鎮 / 郵遞區號連動
 *
 * - woocommerce_states：TW 提供 22 縣市（key = label = 中文全名），傳統與區塊結帳共用
 * - wp_enqueue_scripts：僅在傳統結帳頁載入 classic-tw-address.js 並 localize 鄉鎮郵遞區號對照；
 *   區塊結帳 city 維持文字輸入，不載入
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout\Services;

use J7\PowerCheckout\Domains\Checkout\DTOs\CheckoutEnhancementSettingsDTO;
use J7\PowerCheckout\Domains\Checkout\Shared\Helpers\CartInspector;
use J7\PowerCheckout\Domains\Checkout\Shared\Helpers\TwAdminDivision;
use J7\PowerCheckout\Plugin;

/** 台灣縣市鄉鎮 */
final class TwAddressService {

	/** @var string 設定開關鍵 */
	private const SETTING_KEY = 'tw_address';

	/** @var string 傳統結帳腳本 handle */
	public const SCRIPT_HANDLE = 'pc-classic-tw-address';

	/** @var string localize 物件名稱 */
	public const LOCALIZE_OBJECT = 'pc_tw_address_data';

	/**
	 * 註冊 hooks（無條件掛上，開關於 callback 內判斷）
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\add_filter( 'woocommerce_states', [ __CLASS__, 'filter_states' ] );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_classic_assets' ] );
	}

	/**
	 * 開關開啟時，TW 提供 22 縣市下拉選項
	 *
	 * @param mixed $states 各國 states
	 * @return mixed 調整後的 states（例外時回傳原值）
	 */
	public static function filter_states( mixed $states ): mixed {
		try {
			if ( !\is_array( $states ) || !CheckoutEnhancementSettingsDTO::is_on( self::SETTING_KEY ) ) {
				return $states;
			}

			$states['TW'] = TwAdminDivision::get_states();
			return $states;
		} catch ( \Throwable $e ) {
			Plugin::logger( "TwAddressService::filter_states 失敗：{$e->getMessage()}", 'error' );
			return $states;
		}
	}

	/**
	 * 傳統結帳頁載入鄉鎮 / 郵遞區號連動腳本
	 *
	 * @return void
	 */
	public static function enqueue_classic_assets(): void {
		try {
			if ( !CheckoutEnhancementSettingsDTO::is_on( self::SETTING_KEY ) || !CartInspector::is_classic_checkout() ) {
				return;
			}

			\wp_enqueue_script(
				self::SCRIPT_HANDLE,
				Plugin::$url . '/inc/assets/js/classic-tw-address.js',
				[ 'jquery', 'wc-checkout' ],
				Plugin::$version,
				[ 'in_footer' => true ]
			);

			\wp_localize_script(
				self::SCRIPT_HANDLE,
				self::LOCALIZE_OBJECT,
				[
					'counties'           => TwAdminDivision::get_all(),
					'i18n_select_county' => \__( '請先選擇縣市', 'power_checkout' ),
					'i18n_select_city'   => \__( '請選擇鄉鎮市區', 'power_checkout' ),
				]
			);
		} catch ( \Throwable $e ) {
			Plugin::logger( "TwAddressService::enqueue_classic_assets 失敗：{$e->getMessage()}", 'error' );
		}
	}
}
