<?php
/**
 * 物流 provider meta LogisticsProviderMetaDTO（不可變；對齊 zenbu ShippingGateway::getProviderMeta()）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\DTOs;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\DeliveryMethod;

/** 物流 provider meta */
final class LogisticsProviderMetaDTO {

	/**
	 * Constructor
	 *
	 * @param string                     $id                         provider id
	 * @param array<int, DeliveryMethod> $supported_delivery_methods 支援的配送方式
	 * @param ShipmentCapabilitiesDTO    $capabilities               能力宣告
	 */
	public function __construct(
		public readonly string $id,
		public readonly array $supported_delivery_methods,
		public readonly ShipmentCapabilitiesDTO $capabilities,
	) {
	}

	/**
	 * 轉陣列（給 REST / 前端）
	 *
	 * @return array{id: string, supported_delivery_methods: array<int, string>, capabilities: array{can_create_shipment: bool, can_query_shipment: bool, has_cvs_map: bool, can_print: bool, can_return: bool, can_cancel: bool}}
	 */
	public function to_array(): array {
		return [
			'id'                         => $this->id,
			'supported_delivery_methods' => \array_values(
				\array_map( static fn( DeliveryMethod $method ): string => $method->value, $this->supported_delivery_methods )
			),
			'capabilities'               => $this->capabilities->to_array(),
		];
	}
}
