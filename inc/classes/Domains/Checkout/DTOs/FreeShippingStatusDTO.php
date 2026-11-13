<?php
/**
 * 「差多少免運」計算結果
 *
 * 由 FreeShippingCalculator 產出；achieved=true 時 diff 固定為 0。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout\DTOs;

/** 免運門檻計算結果 */
final class FreeShippingStatusDTO {

	/**
	 * Constructor
	 *
	 * @param bool  $achieved 是否已達免運（差額 ≤ 0，或 either 已套用免運券、requires 無條件）
	 * @param float $diff     差額（已依商店小數位四捨五入；已達時為 0）
	 */
	public function __construct(
		public readonly bool $achieved,
		public readonly float $diff,
	) {
	}

	/**
	 * 建立「已達免運」結果
	 *
	 * @return self 已達結果
	 */
	public static function achieved(): self {
		return new self( true, 0.0 );
	}
}
