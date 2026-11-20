<?php
/**
 * 物流能力宣告 ShipmentCapabilitiesDTO（不可變；對齊 zenbu ShipmentCapabilities）
 *
 * LogisticsApiService 依此在呼叫 provider 前檢查，宣告 false 的操作回 HTTP 501 + UNSUPPORTED
 * （既有綠界 / PAYUNi / PayNow 不受檢查影響，維持原回應碼）。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\DTOs;

/** 物流能力宣告 */
final class ShipmentCapabilitiesDTO {

	/**
	 * Constructor（6 個能力皆必填）
	 *
	 * @param bool $can_create_shipment 可建立物流單（create_shipment）
	 * @param bool $can_query_shipment  可查詢物流單（query_shipment）
	 * @param bool $has_cvs_map         提供超商門市地圖（get_store_selection）
	 * @param bool $can_print           可列印託運單（print_document）
	 * @param bool $can_return          可建立逆物流（create_return）
	 * @param bool $can_cancel          可取消物流單（cancel_shipment）
	 */
	public function __construct(
		public readonly bool $can_create_shipment,
		public readonly bool $can_query_shipment,
		public readonly bool $has_cvs_map,
		public readonly bool $can_print,
		public readonly bool $can_return,
		public readonly bool $can_cancel,
	) {
	}

	/**
	 * 轉陣列（鍵順序固定）
	 *
	 * @return array{can_create_shipment: bool, can_query_shipment: bool, has_cvs_map: bool, can_print: bool, can_return: bool, can_cancel: bool}
	 */
	public function to_array(): array {
		return [
			'can_create_shipment' => $this->can_create_shipment,
			'can_query_shipment'  => $this->can_query_shipment,
			'has_cvs_map'         => $this->has_cvs_map,
			'can_print'           => $this->can_print,
			'can_return'          => $this->can_return,
			'can_cancel'          => $this->can_cancel,
		];
	}
}
