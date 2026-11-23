<?php
/**
 * 純流水號補零格式（無檢查碼）
 *
 * 嘉里大榮常溫單號 = 11 碼流水號（P7 共用）。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\IWaybillNumberFormat;

/** 純流水號格式 */
final class PlainSerialFormat implements IWaybillNumberFormat {

	/**
	 * Constructor
	 *
	 * @param int $digits 位數
	 * @throws \InvalidArgumentException 位數不合法
	 */
	public function __construct( private readonly int $digits ) {
		if ($digits < 1 || $digits > 18) {
			throw new \InvalidArgumentException( 'PlainSerialFormat：位數須為 1~18' );
		}
	}

	/**
	 * 流水號 → 左補零
	 *
	 * @param int $serial 流水號
	 * @return string
	 */
	public function format( int $serial ): string {
		return \str_pad( (string) $serial, $this->digits, '0', STR_PAD_LEFT );
	}

	/** @return int 最大值 */
	public function max_serial(): int {
		return (int) \str_repeat( '9', $this->digits );
	}
}
