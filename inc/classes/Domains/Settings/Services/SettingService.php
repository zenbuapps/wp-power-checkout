<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\Services;

use J7\PowerCheckout\Plugin;

/**
 * WooCommerce 設定分頁服務
 */
class SettingService {

	/** @var object{value:string, label:string} 設定分頁 value 及 label */
	private static object $tab;

	/** @var object{handle:string, src:string, deps:string[], ver:string, arg:array{strategy:string,in_footer:bool}} 腳本資訊 */
	private static object $script;

	/** Register hooks */
	public static function register_hooks(): void {
		self::$tab = (object) [
			'value' => 'power_checkout_wc_settings',
			'label' => __( 'Power Checkout 設定', 'power_checkout' ),
		];

		self::$script = (object) [
			'handle' => 'power-checkout-wc-setting-tab',
			'src'    => Plugin::$url . '/inc/classes/Domains/Settings/Views/dist/index.js',
			'deps'   => [ 'jquery' ],
			'ver'    => Plugin::$version,
			'arg'    => [
				'strategy'  => 'async',
				'in_footer' => true,
			],
		];

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
		$tab                          = self::$tab;
		$settings_tabs[ $tab->value ] = $tab->label;
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
        return 'wc-settings' === @$_GET['page'] && self::$tab->value === @$_GET['tab']; // phpcs:ignore
	}

	public static function enqueue_scripts( $hook ): void {
        if(!self::is_current_tab()) { // phpcs:ignore
			return;
		}
		\wp_enqueue_script(
			self::$script->handle,
			self::$script->src,
			self::$script->deps,
			self::$script->ver,
			self::$script->arg
		);
	}
}
