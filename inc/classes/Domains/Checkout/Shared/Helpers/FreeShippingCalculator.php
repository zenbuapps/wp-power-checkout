<?php
/**
 * 「差多少免運」純計算（DF5）
 *
 * 與 WC_Shipping_Free_Shipping::is_available() 同算法：
 *   基準 = 顯示小計 −（該方式 ignore_discounts=no 時）（折扣總額 + 含稅顯示時的折扣稅額）；四捨五入至商店小數位
 * 依 requires：
 *   ''        → 已達（無條件免運）
 *   coupon    → 不列入候選（無法以加購達成）
 *   min_amount→ diff = min − 基準
 *   either    → 已套用免運券 ? 已達 : diff
 *   both      → 已套用免運券 ? diff : 不列入
 * ASM-2：需要金額的 requires 但 min_amount 為空 / 非數字 → 不列入候選。
 * 多門檻：任一已達 → 已達；否則取差額最小者。只看第一個 shipping package。
 *
 * never-throw：任何 \Throwable 一律回傳 null（由呼叫端決定是否記 log）。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout\Shared\Helpers;

use Automattic\WooCommerce\Utilities\NumberUtil;
use J7\PowerCheckout\Domains\Checkout\DTOs\FreeShippingStatusDTO;

/** 免運門檻差額計算 */
final class FreeShippingCalculator {

	/** @var string WC 內建免運費運送方式 id */
	private const FREE_SHIPPING_ID = 'free_shipping';

	/** @var array<int, string> 需要最低金額的 requires */
	private const AMOUNT_REQUIRES = [ 'min_amount', 'either', 'both' ];

	/**
	 * 計算購物車距離免運的差額
	 *
	 * @param \WC_Cart|null $cart 購物車；null 時讀 WC()->cart
	 * @return FreeShippingStatusDTO|null 無候選（空購物車 / 不需運送 / 無已啟用免運方式 / 皆不可計算）時為 null
	 */
	public static function calculate( ?\WC_Cart $cart = null ): ?FreeShippingStatusDTO {
		try {
			$cart ??= self::current_cart();
			if ( !$cart instanceof \WC_Cart || $cart->is_empty() || !$cart->needs_shipping() ) {
				return null;
			}

			$methods = self::free_shipping_methods( $cart );
			if ( [] === $methods ) {
				return null;
			}

			$has_coupon = self::has_free_shipping_coupon( $cart );
			$min_diff   = null;

			foreach ( $methods as $method ) {
				$diff = self::method_diff( $cart, $method, $has_coupon );
				if ( null === $diff ) {
					continue;
				}
				if ( $diff <= 0 ) {
					return FreeShippingStatusDTO::achieved();
				}
				$min_diff = null === $min_diff ? $diff : \min( $min_diff, $diff );
			}

			return null === $min_diff ? null : new FreeShippingStatusDTO( false, $min_diff );
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * 單一免運方式的差額
	 *
	 * @param \WC_Cart            $cart       購物車
	 * @param \WC_Shipping_Method $method     免運方式
	 * @param bool                $has_coupon 是否已套用有效的免運券
	 * @return float|null 差額（≤ 0 代表已達）；不列入候選時為 null
	 */
	private static function method_diff( \WC_Cart $cart, \WC_Shipping_Method $method, bool $has_coupon ): ?float {
		$requires = self::option_string( $method, 'requires' );

		if ( 'coupon' === $requires ) {
			return null;
		}
		if ( !\in_array( $requires, self::AMOUNT_REQUIRES, true ) ) {
			return 0.0; // 無條件免運
		}
		if ( 'either' === $requires && $has_coupon ) {
			return 0.0;
		}
		if ( 'both' === $requires && !$has_coupon ) {
			return null;
		}

		$min_amount = \trim( self::raw_min_amount( $method ) );
		if ( '' === $min_amount || !\is_numeric( $min_amount ) ) {
			return null; // ASM-2
		}

		$decimals = \wc_get_price_decimals();
		$base     = self::base_total( $cart, 'yes' !== self::option_string( $method, 'ignore_discounts' ), $decimals );

		return (float) NumberUtil::round( (float) $min_amount - $base, $decimals );
	}

	/**
	 * 計算基準金額（同 WC_Shipping_Free_Shipping::is_available）
	 *
	 * @param \WC_Cart $cart            購物車
	 * @param bool     $deduct_discount 是否扣除折扣（ignore_discounts 不為 yes）
	 * @param int      $decimals        商店價格小數位
	 * @return float 基準金額
	 */
	private static function base_total( \WC_Cart $cart, bool $deduct_discount, int $decimals ): float {
		$total = (float) $cart->get_displayed_subtotal();

		if ( $deduct_discount ) {
			$total -= (float) $cart->get_discount_total();
			if ( $cart->display_prices_including_tax() ) {
				$total -= (float) $cart->get_discount_tax();
			}
		}

		return (float) NumberUtil::round( $total, $decimals );
	}

	/**
	 * 取得第一個 shipping package 所屬運送區域中「已啟用」的 WC 內建免運方式
	 *
	 * @param \WC_Cart $cart 購物車
	 * @return array<int, \WC_Shipping_Method> 免運方式
	 */
	private static function free_shipping_methods( \WC_Cart $cart ): array {
		$packages = $cart->get_shipping_packages();
		$package  = \reset( $packages );
		if ( !\is_array( $package ) ) {
			return [];
		}

		$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );

		$methods = [];
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( $method instanceof \WC_Shipping_Method && self::FREE_SHIPPING_ID === $method->id ) {
				$methods[] = $method;
			}
		}
		return $methods;
	}

	/**
	 * 是否已套用有效且含免運的優惠券（同 WC_Shipping_Free_Shipping::is_available）
	 *
	 * @param \WC_Cart $cart 購物車
	 * @return bool 是否有免運券
	 */
	private static function has_free_shipping_coupon( \WC_Cart $cart ): bool {
		foreach ( $cart->get_coupons() as $coupon ) {
			if ( $coupon instanceof \WC_Coupon && $coupon->is_valid() && $coupon->get_free_shipping() ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 讀取運送方式的字串設定
	 *
	 * @param \WC_Shipping_Method $method 運送方式
	 * @param string              $key    設定鍵
	 * @return string 設定值（非純量時回傳空字串）
	 */
	private static function option_string( \WC_Shipping_Method $method, string $key ): string {
		$value = $method->get_option( $key, '' );
		return \is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * 讀取原始儲存的 min_amount
	 *
	 * WC_Shipping_Free_Shipping::init() 以 get_option('min_amount', 0) 讀值時會把空字串就地改寫成 0，
	 * 因此需直接讀 option 原值才能判斷 ASM-2（空字串 / 非數字不列入候選）。
	 *
	 * @param \WC_Shipping_Method $method 運送方式
	 * @return string 原始值（未設定或非純量時為空字串）
	 */
	private static function raw_min_amount( \WC_Shipping_Method $method ): string {
		$settings = \get_option( $method->get_instance_option_key(), [] );
		$value    = \is_array( $settings ) ? ( $settings['min_amount'] ?? '' ) : '';
		return \is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * 取得目前購物車
	 *
	 * @return \WC_Cart|null 購物車（未載入時為 null）
	 */
	private static function current_cart(): ?\WC_Cart {
		// WC()->cart 在後台 / REST 早期等情境可能尚未初始化（runtime 為 null）
		return \function_exists( 'WC' ) ? \WC()->cart : null;
	}
}
