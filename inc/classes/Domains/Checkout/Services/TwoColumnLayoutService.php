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

/** 新版兩欄式結帳頁 */
final class TwoColumnLayoutService {

	/** @var string 設定開關鍵 */
	public const SETTING_KEY = 'checkout_two_column';

	/** @var string 腳本 / 樣式 handle */
	public const HANDLE = 'pc-classic-two-column';

	/** @var string 等待版面套用期間的 body class（CSS 有 fallback 動畫，JS 失敗也會自動顯示） */
	public const BODY_CLASS = 'pc-tc-pending';

	/**
	 * 註冊 hooks（無條件掛上，開關於 callback 內判斷）
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 20 );
		\add_filter( 'body_class', [ __CLASS__, 'add_body_class' ] );
		\add_action( 'woocommerce_review_order_before_cart_contents', [ __CLASS__, 'start_thumbnail' ] );
		\add_action( 'woocommerce_review_order_after_cart_contents', [ __CLASS__, 'stop_thumbnail' ] );
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

		return !( \function_exists( 'is_wc_endpoint_url' ) && \is_wc_endpoint_url() );
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
			if ( !CheckoutEnhancementSettingsDTO::is_on( self::SETTING_KEY ) || !CartInspector::is_classic_checkout() ) {
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
