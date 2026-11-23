<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\DTOs;

/**
 * 金流能力宣告（不可變）
 *
 * 定義採「方法層級」：只有 gateway 類別自己覆寫對應方法才可宣告 true
 *   - refund / partial_refund ⇔ 覆寫 process_refund()
 *   - capture ⇔ 覆寫 capture()；void ⇔ 覆寫 void_auth()；query ⇔ 覆寫 query_trade()
 * 僅以 admin order action（woocommerce_order_actions）提供的能力不算，於 gateway docblock 另列。
 */
final class PaymentCapabilitiesDTO {

	/**
	 * Constructor（6 個能力皆必填）
	 *
	 * @param bool $refund         可 API 退款
	 * @param bool $partial_refund 可 API 部分退款
	 * @param bool $capture        可請款
	 * @param bool $void           可取消授權
	 * @param bool $query          可查詢交易 / 補單
	 * @param bool $recurring      可定期定額
	 */
	public function __construct(
		public readonly bool $refund,
		public readonly bool $partial_refund,
		public readonly bool $capture,
		public readonly bool $void,
		public readonly bool $query,
		public readonly bool $recurring,
	) {
	}

	/**
	 * 轉陣列（鍵順序固定）
	 *
	 * @return array{refund: bool, partial_refund: bool, capture: bool, void: bool, query: bool, recurring: bool}
	 */
	public function to_array(): array {
		return [
			'refund'         => $this->refund,
			'partial_refund' => $this->partial_refund,
			'capture'        => $this->capture,
			'void'           => $this->void,
			'query'          => $this->query,
			'recurring'      => $this->recurring,
		];
	}
}
