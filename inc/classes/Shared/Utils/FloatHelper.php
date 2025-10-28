<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Utils;

/**
 * FloatHelper 輔助函數
 */
final class FloatHelper {
	/**
	 * 取得 float 的小數點精度位數
	 * 若超過指定精度，則四捨五入
	 *
	 * @param float $number 要檢查的數字
	 * @param int   $precision 指定精度
	 * @return int 實際小數位數（可能已被四捨五入）
	 */
	public static function get_decimal_precision( float &$number, int $precision ): int {
		$decimal_part     = \explode('.', (string) $number)[1] ?? '';
		$actual_precision = \strlen(\rtrim($decimal_part, '0'));

		if ($actual_precision > $precision) {
			$number = \round($number, $precision);
			return $precision;
		}

		return $actual_precision;
	}

	/**
	 * 四捨五入到指定精度
	 *
	 * @param float $number 傳入數字
	 * @param int   $precision 精度
	 * @return float
	 */
	public static function round_to_precision( float $number, int $precision ): float {
		return round($number, $precision);
	}

	/**
	 * 檢查是否符合指定精度
	 *
	 * @param float $number 傳入數字
	 * @param int   $precision 精度
	 *
	 * @return bool
	 */
	public static function is_within_precision( float $number, int $precision ): bool {
		$decimal_part     = explode('.', (string) $number)[1] ?? '';
		$actual_precision = strlen(rtrim($decimal_part, '0'));
		return $actual_precision <= $precision;
	}
}
