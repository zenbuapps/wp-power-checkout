<?php
/**
 * 嘉里大榮欄位正規化工具（純函式）
 *
 * - 長度以字元（code point）計：規格書寫「字元」，是否實為 byte 待嘉里大榮確認（skill onboarding §6.1）
 * - 電話只去掉空白 / 連字號 / 括號，不猜國碼
 * - 郵遞區號只收 3 碼：數字 ≥ 3 碼取前 3；否則以「縣市 + 鄉鎮市區」查 TwAdminDivision（台 / 臺不分、區名取最長相符）
 *
 * @see zenbu apps/api-gateway/src/commerce/shipping/kerrytj/kerrytj-payload.ts
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers;

use J7\PowerCheckout\Domains\Checkout\Shared\Helpers\TwAdminDivision;

/** 欄位工具 */
final class KerrytjFields {

	/**
	 * 截斷到指定字元數
	 *
	 * @param string $value 值
	 * @param int    $max   上限
	 * @return string
	 */
	public static function cut( string $value, int $max ): string {
		return \mb_substr( \trim( $value ), 0, $max, 'UTF-8' );
	}

	/**
	 * 字元數
	 *
	 * @param string $value 值
	 * @return int
	 */
	public static function length( string $value ): int {
		return \mb_strlen( $value, 'UTF-8' );
	}

	/**
	 * 電話正規化（去空白 / 連字號 / 括號）
	 *
	 * @param string $phone 電話
	 * @return string
	 */
	public static function phone( string $phone ): string {
		return (string) \preg_replace( '/[\s\-()]/u', '', $phone );
	}

	/**
	 * 郵遞區號字串 → 前 3 碼（不足 3 碼數字回空字串）
	 *
	 * @param string $postcode 郵遞區號
	 * @return string
	 */
	public static function zip3( string $postcode ): string {
		$digits = (string) \preg_replace( '/\D/', '', $postcode );
		return \strlen( $digits ) >= 3 ? \substr( $digits, 0, 3 ) : '';
	}

	/**
	 * 地址文字 → 3 碼郵遞區號（比對開頭縣市與鄉鎮市區；查不到回空字串）
	 *
	 * @param string $address 地址（可含前置郵遞區號）
	 * @return string
	 */
	public static function zip3_from_address( string $address ): string {
		$text = \str_replace( '台', '臺', (string) \preg_replace( '/^\s*\d{3,6}\s*/', '', \trim( $address ) ) );
		foreach ( TwAdminDivision::DISTRICTS as $city => $districts ) {
			if (!\str_starts_with( $text, $city )) {
				continue;
			}
			$rest  = \substr( $text, \strlen( $city ) );
			$found = '';
			$zip   = '';
			foreach ( $districts as $district => $code ) {
				if (\str_starts_with( $rest, $district ) && \strlen( $district ) > \strlen( $found )) {
					$found = $district;
					$zip   = $code;
				}
			}
			return $zip;
		}
		return '';
	}
}
