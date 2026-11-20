<?php
/**
 * 「N 碼本體 + MOD 7 檢查碼」託運單號格式
 *
 * 新竹物流貨號 = 9 碼本體 + 檢查碼（前 9 碼當整數除以 7 的餘數，0~6），共 10 碼（Codabar `D{10碼}D`）。
 * 嘉里大榮低溫單號同為 9+1 碼 mod 7（P7 共用）。
 *
 * @see .claude/skills/hct-logistics/references/label-spec.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\IWaybillNumberFormat;

/** MOD 7 檢查碼格式 */
final class Mod7CheckDigitFormat implements IWaybillNumberFormat {

	/**
	 * Constructor
	 *
	 * @param int $body_digits 本體位數（新竹 = 9）
	 * @throws \InvalidArgumentException 位數不合法
	 */
	public function __construct( private readonly int $body_digits = 9 ) {
		if ($body_digits < 1 || $body_digits > 17) {
			throw new \InvalidArgumentException( 'Mod7CheckDigitFormat：本體位數須為 1~17' );
		}
	}

	/**
	 * 流水號 → 補零本體 + 檢查碼
	 *
	 * @param int $serial 流水號本體
	 * @return string
	 */
	public function format( int $serial ): string {
		return \str_pad( (string) $serial, $this->body_digits, '0', STR_PAD_LEFT ) . (string) ( $serial % 7 );
	}

	/** @return int 本體最大值（9 碼 = 999999999） */
	public function max_serial(): int {
		return (int) \str_repeat( '9', $this->body_digits );
	}
}
