<?php
/**
 * 託運單號格式策略（WaybillNumberAllocator 注入用）
 *
 * 配號器只管「流水號本體」的原子遞增；號碼長什麼樣（補零位數、檢查碼）由各物流商策略決定：
 *  - 新竹物流 / 嘉里大榮低溫：9 碼本體 + MOD 7 檢查碼（Mod7CheckDigitFormat）
 *  - 嘉里大榮常溫：11 碼流水號（PlainSerialFormat）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Interfaces;

interface IWaybillNumberFormat {

	/**
	 * 流水號本體 → 完整託運單號
	 *
	 * @param int $serial 流水號本體（1 ~ max_serial()）
	 * @return string 完整託運單號
	 */
	public function format( int $serial ): string;

	/**
	 * 本格式可表示的最大流水號（號段迄號上限）
	 *
	 * @return int
	 */
	public function max_serial(): int;
}
