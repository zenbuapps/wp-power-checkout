<?php
/**
 * 購物車 / 結帳頁判斷工具
 *
 * 只讀取、不改變購物車狀態；判斷不出來時一律回傳「偏安全」的結果（非全虛擬 / 非傳統結帳）。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout\Shared\Helpers;

/** 購物車判斷 */
final class CartInspector {

	/**
	 * 購物車是否「全為虛擬商品」
	 *
	 * 條件：購物車已載入、至少 1 個品項、每個品項的商品都存在且 is_virtual()。
	 * 空購物車、購物車未載入（null）、任一商品已刪除 → 視為非全虛擬（顯示地址，偏安全）。
	 *
	 * @param \WC_Cart|null $cart 購物車；null 時讀 WC()->cart
	 * @return bool 是否全為虛擬商品
	 */
	public static function is_all_virtual_cart( ?\WC_Cart $cart = null ): bool {
		$cart ??= self::current_cart();
		if ( !$cart instanceof \WC_Cart ) {
			return false;
		}

		$items = $cart->get_cart_contents();
		if ( [] === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			$product = \is_array( $item ) ? ( $item['data'] ?? null ) : null;
			if ( !$product instanceof \WC_Product || !$product->is_virtual() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 訂單是否「全為虛擬商品」
	 *
	 * 條件：至少 1 個商品品項、每個品項對應的商品都存在且 is_virtual()。
	 * 無商品品項（例如只有運費）、任一商品已刪除、含實體商品 → false（偏安全，不自動完成）。
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool 是否全為虛擬商品
	 */
	public static function is_all_virtual_order( \WC_Order $order ): bool {
		$items = $order->get_items( 'line_item' );
		if ( [] === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( !$item instanceof \WC_Order_Item_Product ) {
				return false;
			}

			$product = $item->get_product();
			if ( !$product instanceof \WC_Product || !$product->is_virtual() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * 目前請求是否為「傳統結帳頁」（非區塊結帳）
	 *
	 * 邏輯同 WC_EcpayLogisticsShipping::is_classic_checkout（本領域另寫一份，避免跨領域依賴 private 方法）。
	 *
	 * @return bool 是否為傳統結帳頁
	 */
	public static function is_classic_checkout(): bool {
		if ( !\function_exists( 'is_checkout' ) || !\is_checkout() ) {
			return false;
		}

		return !self::page_has_checkout_block();
	}

	/**
	 * 目前請求是否為「區塊結帳頁」
	 *
	 * @return bool 是否為區塊結帳頁
	 */
	public static function is_block_checkout(): bool {
		if ( !\function_exists( 'is_checkout' ) || !\is_checkout() ) {
			return false;
		}

		return self::page_has_checkout_block();
	}

	/**
	 * 目前頁面內容是否含 woocommerce/checkout 區塊
	 *
	 * @return bool 是否含結帳區塊
	 */
	private static function page_has_checkout_block(): bool {
		if ( !\function_exists( 'has_block' ) ) {
			return false;
		}

		$post = \get_post();
		return $post instanceof \WP_Post && \has_block( 'woocommerce/checkout', $post );
	}

	/**
	 * 取得目前的購物車（WC 未初始化購物車時回傳 null）
	 *
	 * @return \WC_Cart|null 購物車
	 */
	private static function current_cart(): ?\WC_Cart {
		// WC()->cart 在後台 / REST 早期等情境可能尚未初始化（runtime 為 null）
		return \function_exists( 'WC' ) ? \WC()->cart : null;
	}
}
