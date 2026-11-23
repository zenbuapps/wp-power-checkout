<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\DTOs;

use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderCreationStrategy;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\PaymentMode;

/**
 * 金流 provider meta（不可變；對齊 zenbu PaymentGateway::getProviderMeta()）
 *
 * 由 IPaymentProvider::get_provider_meta() 回傳，供 REST / Vue 判斷 gateway 支援哪些操作。
 */
final class ProviderMetaDTO {

	/**
	 * Constructor
	 *
	 * @param string                 $id                      gateway id
	 * @param PaymentMode            $payment_mode            付款模式
	 * @param OrderCreationStrategy  $order_creation_strategy 訂單建立策略
	 * @param PaymentCapabilitiesDTO $capabilities            能力宣告
	 */
	public function __construct(
		public readonly string $id,
		public readonly PaymentMode $payment_mode,
		public readonly OrderCreationStrategy $order_creation_strategy,
		public readonly PaymentCapabilitiesDTO $capabilities,
	) {
	}

	/**
	 * 轉陣列（給 REST / 前端）
	 *
	 * @return array{id: string, payment_mode: string, order_creation_strategy: string, capabilities: array{refund: bool, partial_refund: bool, capture: bool, void: bool, query: bool, recurring: bool}}
	 */
	public function to_array(): array {
		return [
			'id'                      => $this->id,
			'payment_mode'            => $this->payment_mode->value,
			'order_creation_strategy' => $this->order_creation_strategy->value,
			'capabilities'            => $this->capabilities->to_array(),
		];
	}
}
