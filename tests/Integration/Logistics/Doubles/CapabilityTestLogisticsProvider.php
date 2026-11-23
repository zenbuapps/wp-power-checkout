<?php

declare( strict_types=1 );

namespace Tests\Integration\Logistics\Doubles;

use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\LogisticsProviderMetaDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\ShipmentCapabilitiesDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\DeliveryMethod;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Shared\Abstracts\BaseService;

/**
 * 測試替身：只支援建單 / 查詢的物流 provider
 */
final class CapabilityTestLogisticsProvider extends BaseService implements ILogisticsProvider {

	/** @var string provider id */
	public const ID = 'pc_test_capability_logistics';

	/** @var array<int, string> 被呼叫的方法紀錄 */
	public static array $calls = [];

	/** @var string $id Id */
	public string $id = self::ID;

	/**
	 * 設定
	 *
	 * @param bool $with_default 是否帶預設值
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		return [ 'enabled' => 'yes' ];
	}

	/**
	 * Meta：只支援建單 / 查詢
	 *
	 * @return LogisticsProviderMetaDTO
	 */
	public function get_provider_meta(): LogisticsProviderMetaDTO {
		return new LogisticsProviderMetaDTO(
			id: self::ID,
			supported_delivery_methods: [ DeliveryMethod::HOME ],
			capabilities: new ShipmentCapabilitiesDTO(
				can_create_shipment: true,
				can_query_shipment: true,
				has_cvs_map: false,
				can_print: false,
				can_return: false,
				can_cancel: false,
			),
		);
	}

	/**
	 * 選店
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 * @return array<string, mixed>
	 */
	public function get_store_selection( \WC_Order $order, array $ctx = [] ): array {
		self::$calls[] = 'get_store_selection';
		return [];
	}

	/**
	 * 解析選店
	 *
	 * @param array<string, mixed> $raw 原始資料
	 * @return array<string, mixed>
	 */
	public function parse_store_selection( array $raw ): array {
		self::$calls[] = 'parse_store_selection';
		return [];
	}

	/**
	 * 建單
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 */
	public function create_shipment( \WC_Order $order ): array {
		self::$calls[] = 'create_shipment';
		return [ 'logistics_id' => 'DOUBLE-1' ];
	}

	/**
	 * 查詢
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 */
	public function query_shipment( \WC_Order $order ): array {
		self::$calls[] = 'query_shipment';
		return [ 'logistics_id' => 'DOUBLE-1' ];
	}

	/**
	 * 列印
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	public function print_document( \WC_Order $order ): string {
		self::$calls[] = 'print_document';
		return '<html></html>';
	}

	/**
	 * 取消
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 */
	public function cancel_shipment( \WC_Order $order ): array {
		self::$calls[] = 'cancel_shipment';
		return [];
	}

	/**
	 * 退貨
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $ctx   上下文
	 * @return array<string, mixed>
	 */
	public function create_return( \WC_Order $order, array $ctx = [] ): array {
		self::$calls[] = 'create_return';
		return [];
	}

	/**
	 * 貨態 callback
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( 'OK', 200 );
	}

	/**
	 * 支援的子類型
	 *
	 * @return array<int, string>
	 */
	public function get_supported_methods(): array {
		return [ 'HOME' ];
	}
}
