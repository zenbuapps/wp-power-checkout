<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers;

use J7\PowerCheckout\Domains\Payment\TcatPay\DTOs\TcatPaySettingsDTO;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Enums\TcatPayMethod;

/**
 * 黑貓PAY 各子方式單筆金額上限（移植 zenbu kolet-amount-limits.ts）
 *
 * 上限由商家合約決定 → 可逐站設定（max_amount_credit / _cvs / _atm），未設定或不合法時沿用平台預設。
 * 結帳頁顯示（available_methods）與建單前置驗證（is_within）共用此處，兩邊數字不會不一致。
 */
final class TcatPayAmountLimits {

	/** @var int 可設定值上界（擋手滑多打零，非平台規格） */
	public const UPPER_BOUND = 10000000;

	/**
	 * 解析設定原始值：只認 1~UPPER_BOUND 的整數，其餘回 null
	 *
	 * @param mixed $raw 原始值
	 * @return int|null
	 */
	public static function parse( mixed $raw ): ?int {
		if ( \is_int( $raw ) ) {
			$value = $raw;
		} elseif ( \is_string( $raw ) && 1 === \preg_match( '/^\d+$/', \trim( $raw ) ) ) {
			$value = (int) \trim( $raw );
		} else {
			return null;
		}
		return ( $value >= 1 && $value <= self::UPPER_BOUND ) ? $value : null;
	}

	/**
	 * 子方式生效上限
	 *
	 * @param TcatPayMethod      $method   子方式
	 * @param TcatPaySettingsDTO $settings 設定
	 * @return int
	 */
	public static function max_for( TcatPayMethod $method, TcatPaySettingsDTO $settings ): int {
		return $settings->max_amounts[ $method->value ] ?? $method->default_max_amount();
	}

	/**
	 * 金額是否在上限內（金額須 > 0）
	 *
	 * @param TcatPayMethod      $method   子方式
	 * @param int                $amount   金額（元）
	 * @param TcatPaySettingsDTO $settings 設定
	 * @return bool
	 */
	public static function is_within( TcatPayMethod $method, int $amount, TcatPaySettingsDTO $settings ): bool {
		return $amount > 0 && $amount <= self::max_for( $method, $settings );
	}

	/**
	 * 啟用且金額內的子方式（依 enum 宣告順序）
	 *
	 * @param TcatPaySettingsDTO $settings 設定
	 * @param float              $total    訂單 / 購物車總額
	 * @return array<int, TcatPayMethod>
	 */
	public static function available_methods( TcatPaySettingsDTO $settings, float $total ): array {
		$amount = (int) \round( $total );
		$result = [];
		foreach ( TcatPayMethod::cases() as $method ) {
			if ( \in_array( $method->value, $settings->enabled_methods, true ) && self::is_within( $method, $amount, $settings ) ) {
				$result[] = $method;
			}
		}
		return $result;
	}
}
