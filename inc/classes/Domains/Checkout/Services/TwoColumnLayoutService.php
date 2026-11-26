<?php
/**
 * 新版兩欄式結帳頁（checkout_two_column，預設 yes）
 *
 * 版面參考 zenbu-site-2.0 結帳頁（Shopify 風格）：左欄 = 帳單 / 收件 / 付款方式，右欄 = 訂單摘要 + 下單按鈕（桌機 sticky）。
 *
 * - 僅支援傳統（shortcode）結帳；區塊結帳（Checkout Block）原生即為兩欄，本開關不改動區塊結帳
 * - 不覆寫任何 WooCommerce 模板、不改表單欄位 name、不移除任何 hook：版面由前端 classic-two-column.js
 *   在 DOM ready 後「搬移既有節點」完成；update_order_review 的 fragment 以 selector replaceWith，原地替換仍有效
 * - 前端腳本找不到預期結構（#customer_details / #order_review / #payment 不在同一個 form.checkout 內、
 *   或佈景主題已自行改寫結構）時直接放棄，維持舊版；CSS 全部掛在 .pc-tc-layout 之下，放棄時不會套用任何樣式
 * - 訂單摘要商品縮圖：只在 review-order.php 的 before/after_cart_contents 之間掛 woocommerce_cart_item_name，
 *   不影響購物車頁、迷你購物車
 * - 開關關閉時：不 enqueue 任何 CSS / JS、不掛縮圖、不加 body class → 完全等同舊版
 * - never-throw：任何 \Throwable 記 log，回傳原值
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout\Services;

use J7\PowerCheckout\Domains\Checkout\DTOs\CheckoutEnhancementSettingsDTO;
use J7\PowerCheckout\Domains\Checkout\Shared\Helpers\CartInspector;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 新版兩欄式結帳頁 */
final class TwoColumnLayoutService {

	/** @var string 設定開關鍵 */
	public const SETTING_KEY = 'checkout_two_column';

	/** @var string 腳本 / 樣式 handle */
	public const HANDLE = 'pc-classic-two-column';

	/** @var string 等待版面套用期間的 body class（CSS 有 fallback 動畫，JS 失敗也會自動顯示） */
	public const BODY_CLASS = 'pc-tc-pending';

	/** @var string woomp 結帳頁模式 option（onepage / twopage 時 woomp 會自行重排結帳頁） */
	public const WOOMP_LAYOUT_OPTION = 'wc_woomp_setting_mode';

	/** @var array<int, string> woomp 會重排結帳頁的模式 */
	private const WOOMP_LAYOUT_MODES = [ 'onepage', 'twopage' ];

	/** @var string 「既有站預設 no」已判定過的旗標 option */
	public const DEFAULT_RESOLVED_OPTION = 'power_checkout_two_column_default_resolved';

	/** @var array<int, string> 用來判定既有站的 provider id（任一個的 woocommerce_{id}_settings 存在即為既有站） */
	private const EXISTING_SITE_PROVIDER_IDS = [
		'shopline_payment_redirect',
		'shopline_payment_embedded',
		'ecpay_aio',
		'ecpay_ecpg',
		'newebpay_mpg',
		'payuni_upp',
		'payuni_uni_embed',
		'paynow',
		'tcat_pay',
		'linepay',
		'amego',
		'ecpay',
		'ezpay',
		'paynow_invoice',
		'ecpay_logistics',
		'payuni_logistics',
		'paynow_logistics',
		'tcat_logistics',
		'hct_logistics',
		'sf_express',
		'kerrytj_logistics',
	];

	/**
	 * 註冊 hooks（無條件掛上，開關於 callback 內判斷）
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\add_action( 'init', [ __CLASS__, 'resolve_default_for_existing_site' ], 5 );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 20 );
		\add_filter( 'body_class', [ __CLASS__, 'add_body_class' ] );
		\add_action( 'woocommerce_review_order_before_cart_contents', [ __CLASS__, 'start_thumbnail' ] );
		\add_action( 'woocommerce_review_order_after_cart_contents', [ __CLASS__, 'stop_thumbnail' ] );
	}

	/**
	 * 升級後第一次載入：既有站維持舊版版面（只做一次）
	 *
	 * 判定「既有站」：已存過結帳強化設定，或站上已有任何 power-checkout 金流 / 物流 / 發票的 WC 設定 option。
	 * 既有站且未明確存過 checkout_two_column → 寫入 no（其他鍵保持原樣）；新安裝不寫入，沿用預設 yes。
	 * 明確存過 yes / no 的值一律不動。完成後寫入旗標 option，之後每個請求只多讀一個 autoload option。
	 *
	 * @return void
	 */
	public static function resolve_default_for_existing_site(): void {
		try {
			if ( 'yes' === \get_option( self::DEFAULT_RESOLVED_OPTION ) ) {
				return;
			}

			$option_name = CheckoutEnhancementSettingsDTO::get_option_name();
			$stored      = \get_option( $option_name, null );
			$has_key     = \is_array( $stored ) && \array_key_exists( CheckoutEnhancementSettingsDTO::TWO_COLUMN_KEY, $stored );

			if ( !$has_key && ( \is_array( $stored ) || self::has_existing_provider_settings() ) ) {
				$settings = \is_array( $stored ) ? $stored : [];
				$settings[ CheckoutEnhancementSettingsDTO::TWO_COLUMN_KEY ] = 'no';
				\update_option( $option_name, $settings );
			}

			\update_option( self::DEFAULT_RESOLVED_OPTION, 'yes', true );
		} catch ( \Throwable $e ) {
			Plugin::logger( "TwoColumnLayoutService::resolve_default_for_existing_site 失敗：{$e->getMessage()}", 'error' );
		}
	}

	/**
	 * 站上是否已有任何 power-checkout provider 的設定 option（= 升級前就在使用的既有站）
	 *
	 * @return bool
	 */
	private static function has_existing_provider_settings(): bool {
		foreach ( self::EXISTING_SITE_PROVIDER_IDS as $provider_id ) {
			if ( false !== \get_option( ProviderUtils::get_option_name( $provider_id ), false ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 是否為要套用新版版面的頁面：開關開啟 + 傳統結帳頁 + 非 order-pay / order-received 端點
	 *
	 * @return bool
	 */
	public static function should_apply(): bool {
		if ( !CheckoutEnhancementSettingsDTO::is_on( self::SETTING_KEY ) ) {
			return false;
		}

		if ( !CartInspector::is_classic_checkout() ) {
			return false;
		}

		if ( \function_exists( 'is_wc_endpoint_url' ) && \is_wc_endpoint_url() ) {
			return false;
		}

		$apply = !self::has_checkout_replacement_plugin();

		/**
		 * 是否套用新版兩欄式結帳頁（開關已開、傳統結帳頁時才會呼叫）
		 *
		 * 佈景主題 / 其他外掛自行改寫結帳版面時可回傳 false 停用。
		 *
		 * @param bool $apply 預設：未偵測到已知的結帳版面外掛（CheckoutWC / Fluid Checkout / woomp 一頁式・兩頁式結帳）時為 true
		 */
		return (bool) \apply_filters( 'power_checkout_two_column_should_apply', $apply );
	}

	/**
	 * 是否啟用了會整個改寫結帳版面的外掛（這時不介入，維持該外掛的版面）
	 *
	 * @return bool
	 */
	private static function has_checkout_replacement_plugin(): bool {
		// CheckoutWC
		if ( \defined( 'CFW_VERSION' ) || \class_exists( '\Objectiv\Plugins\Checkout\Main' ) ) {
			return true;
		}
		// Fluid Checkout
		if ( \class_exists( '\FluidCheckout' ) ) {
			return true;
		}
		// woomp「一頁式 / 兩頁式結帳」：woomp-checkout.js 會把 #payment 搬到摘要表格下、#place_order 搬進 #customer_details，
		// 與新版兩欄互搶同一批節點（staging 實測：下單按鈕被搬到摘要之前）→ 交給 woomp
		return \in_array( \get_option( self::WOOMP_LAYOUT_OPTION ), self::WOOMP_LAYOUT_MODES, true );
	}

	/**
	 * 載入 CSS / JS（僅結帳頁且開關開啟）
	 *
	 * @return void
	 */
	public static function enqueue_assets(): void {
		try {
			if ( !self::should_apply() ) {
				return;
			}

			\wp_enqueue_style(
				self::HANDLE,
				Plugin::$url . '/inc/assets/css/classic-two-column.css',
				[],
				Plugin::$version
			);

			\wp_enqueue_script(
				self::HANDLE,
				Plugin::$url . '/inc/assets/js/classic-two-column.js',
				[ 'jquery', 'wc-checkout' ],
				Plugin::$version,
				[ 'in_footer' => true ]
			);

			\wp_localize_script(
				self::HANDLE,
				'pcTwoColumn',
				[
					'i18n_payment' => \__( '付款方式', 'power_checkout' ),
				]
			);
		} catch ( \Throwable $e ) {
			Plugin::logger( "TwoColumnLayoutService::enqueue_assets 失敗：{$e->getMessage()}", 'error' );
		}
	}

	/**
	 * 加上等待版面套用的 body class（避免舊版 → 新版的閃動）
	 *
	 * @param mixed $classes body classes
	 * @return mixed
	 */
	public static function add_body_class( mixed $classes ): mixed {
		try {
			if ( !\is_array( $classes ) || !self::should_apply() ) {
				return $classes;
			}
			$classes[] = self::BODY_CLASS;
			return $classes;
		} catch ( \Throwable $e ) {
			Plugin::logger( "TwoColumnLayoutService::add_body_class 失敗：{$e->getMessage()}", 'error' );
			return $classes;
		}
	}

	/**
	 * Review-order 商品列開始：掛上縮圖 filter
	 *
	 * 在 update_order_review AJAX 中 get_post() 為空、is_checkout() 為 true（WOOCOMMERCE_CHECKOUT），
	 * CartInspector::is_classic_checkout() 判定為傳統結帳，縮圖會一併出現在 fragment。
	 *
	 * @return void
	 */
	public static function start_thumbnail(): void {
		try {
			if ( !self::should_apply() ) {
				return;
			}
			\add_filter( 'woocommerce_cart_item_name', [ __CLASS__, 'prepend_thumbnail' ], 999, 2 );
		} catch ( \Throwable $e ) {
			Plugin::logger( "TwoColumnLayoutService::start_thumbnail 失敗：{$e->getMessage()}", 'error' );
		}
	}

	/**
	 * Review-order 商品列結束：移除縮圖 filter
	 *
	 * @return void
	 */
	public static function stop_thumbnail(): void {
		\remove_filter( 'woocommerce_cart_item_name', [ __CLASS__, 'prepend_thumbnail' ], 999 );
	}

	/**
	 * 在商品名稱前加上縮圖 + 數量徽章（CSS 預設隱藏，只有 .pc-tc-layout 內才顯示）
	 *
	 * @param mixed $name      商品名稱 HTML
	 * @param mixed $cart_item 購物車項目
	 * @return mixed
	 */
	public static function prepend_thumbnail( mixed $name, mixed $cart_item = null ): mixed {
		try {
			if ( !\is_string( $name ) || !\is_array( $cart_item ) ) {
				return $name;
			}

			$product = $cart_item['data'] ?? null;
			if ( !$product instanceof \WC_Product ) {
				return $name;
			}

			$quantity = \is_numeric( $cart_item['quantity'] ?? null ) ? (float) $cart_item['quantity'] : 1.0;
			$image    = $product->get_image( 'woocommerce_gallery_thumbnail', [ 'class' => 'pc-tc-thumb-img' ] );

			return \sprintf(
				'<span class="pc-tc-thumb" aria-hidden="true">%1$s<span class="pc-tc-thumb-qty">%2$s</span></span>',
				\wp_kses_post( $image ),
				\esc_html( (string) \wc_stock_amount( $quantity ) )
			) . $name;
		} catch ( \Throwable $e ) {
			Plugin::logger( "TwoColumnLayoutService::prepend_thumbnail 失敗：{$e->getMessage()}", 'error' );
			return $name;
		}
	}
}
