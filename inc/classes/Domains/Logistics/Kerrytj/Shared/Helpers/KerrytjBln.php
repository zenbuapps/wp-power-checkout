<?php
/**
 * 嘉里大榮託運單號（BLN）格式規則（純函式）
 *
 * 嘉里大榮不回傳單號：核發號段，由我方依序配號帶入 `BLN`（規格書「託運單號由客戶自行管理」）。
 *
 * | 種類 | 長度 | 規則 | 格式策略（P5 共用） |
 * |---|---|---|---|
 * | 常溫 | 11 碼 | 流水號，無檢查碼 | PlainSerialFormat(11) |
 * | 低溫 | 10 碼 | 9 碼流水號 + (前 9 碼 mod 7) | Mod7CheckDigitFormat(9) |
 *
 * 號段起訖：常溫須 11 碼；低溫收 9 碼流水號或檢查碼正確的 10 碼單號（業務給的可能是印在託運單上的完整單號）。
 *
 * @see .claude/skills/kerrytj-logistics/SKILL.md 陷阱「溫層決定單號格式」
 * @see zenbu apps/api-gateway/src/commerce/shipping/kerrytj/kerrytj-bln.ts
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjBlnKind;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\Mod7CheckDigitFormat;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\PlainSerialFormat;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\IWaybillNumberFormat;

/** BLN 工具 */
final class KerrytjBln {

	/**
	 * 號碼格式策略（WaybillNumberAllocator 注入用）
	 *
	 * @param KerrytjBlnKind $kind 種類
	 * @return IWaybillNumberFormat
	 */
	public static function format( KerrytjBlnKind $kind ): IWaybillNumberFormat {
		return KerrytjBlnKind::AMBIENT === $kind ? new PlainSerialFormat( 11 ) : new Mod7CheckDigitFormat( 9 );
	}

	/**
	 * 依長度與檢查碼判斷單號種類
	 *
	 * @param string $bln 單號
	 * @return KerrytjBlnKind|null 非合法單號回 null
	 */
	public static function kind_of( string $bln ): ?KerrytjBlnKind {
		if (1 === \preg_match( '/^\d{11}$/', $bln )) {
			return KerrytjBlnKind::AMBIENT;
		}
		if (1 === \preg_match( '/^\d{10}$/', $bln ) && self::format( KerrytjBlnKind::COLD )->format( (int) \substr( $bln, 0, 9 ) ) === $bln) {
			return KerrytjBlnKind::COLD;
		}
		return null;
	}

	/**
	 * 號段起 / 迄 → 流水號本體
	 *
	 * @param KerrytjBlnKind $kind 種類
	 * @param string         $raw  後台輸入
	 * @return int|null 格式不符回 null
	 */
	public static function parse_range_bound( KerrytjBlnKind $kind, string $raw ): ?int {
		$raw = \trim( $raw );
		if (KerrytjBlnKind::AMBIENT === $kind) {
			return 1 === \preg_match( '/^\d{11}$/', $raw ) ? (int) $raw : null;
		}
		if (1 === \preg_match( '/^\d{9}$/', $raw )) {
			return (int) $raw;
		}
		if (KerrytjBlnKind::COLD === self::kind_of( $raw )) {
			return (int) \substr( $raw, 0, 9 );
		}
		return null;
	}

	/**
	 * 單號是否屬於指定種類且落在流水號區間
	 *
	 * @param string         $bln   單號
	 * @param KerrytjBlnKind $kind  種類
	 * @param int            $start 起（流水號）
	 * @param int            $end   迄（流水號）
	 * @return bool
	 */
	public static function in_range( string $bln, KerrytjBlnKind $kind, int $start, int $end ): bool {
		if (self::kind_of( $bln ) !== $kind) {
			return false;
		}
		$serial = KerrytjBlnKind::AMBIENT === $kind ? (int) $bln : (int) \substr( $bln, 0, 9 );
		return $serial >= $start && $serial <= $end;
	}
}
