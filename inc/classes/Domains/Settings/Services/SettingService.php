<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\Services;

use J7\PowerCheckout\Plugin;

/**
 * WooCommerce 設定分頁服務
 */
class SettingService {

	/** @var array{0:string, 1:string} 設定分頁 [value, label] */
	private static array $tab;


	/** Register hooks */
	public static function register_hooks(): void {
		self::$tab = [ 'power_checkout_wc_settings', \__( 'Power Checkout 設定', 'power_checkout' ) ];

		\add_action( 'woocommerce_settings_tabs_array', [ __CLASS__, 'add_settings_tab' ], 30);
		\add_action( 'admin_head', [ __CLASS__, 'hide_default_element' ]);
		\add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_scripts' ] );
	}

	/**
	 * 新增一個設定分頁到 WooCommerce 的設定分頁陣列。
	 *
	 * @param array $settings_tabs WooCommerce 設定分頁及標籤的陣列，不包含訂閱分頁。
	 * @return array $settings_tabs WooCommerce 設定分頁及標籤的陣列，包含訂閱分頁。
	 */
	public static function add_settings_tab( array $settings_tabs ): array {
		[$tab_key, $tab_label]     = self::$tab;
		$settings_tabs[ $tab_key ] = $tab_label;
		return $settings_tabs;
	}

	/** @return void 隱藏 WooCommerce 設定頁面中的預設元素 */
	public static function hide_default_element(): void {
		// 只在指定的 WC Settings tab 載入
		if (!self::is_current_tab()) { // phpcs:ignore
			return;
		}
		?>
		<style>
			#mainform{
				#message, .notice, .submit {
					display: none;
				}
			}
		</style>
		<?php
	}

	/** @return bool 是否為目前的設定分頁 */
	private static function is_current_tab(): bool {
		[$tab_key ] = self::$tab;
        return 'wc-settings' === @$_GET['page'] && $tab_key === @$_GET['tab']; // phpcs:ignore
	}

	/**
	 * 載入腳本
	 *
	 * @param string $hook  當前頁面的 hook 名稱
	 *
	 * @return void
	 */
	public static function enqueue_scripts( $hook ): void { // phpcs:ignore
        if(!self::is_current_tab()) { // phpcs:ignore
			return;
		}
		\wp_enqueue_script(
			'power-checkout-wc-setting-tab',
			Plugin::$url . '/js/dist/index.js',
			[ 'jquery' ],
			Plugin::$version,
			[
				'strategy'  => 'async',
				'in_footer' => true,
			]
			);

		Plugin::instance()->add_module_handle( 'power-checkout-wc-setting-tab');

		\wp_enqueue_style(
		'power-checkout-wc-setting-tab',
		Plugin::$url . '/js/dist/index.css',
		[],
		Plugin::$version,
		);
	}
}
