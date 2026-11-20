<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjBlnKind;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Exceptions\KerrytjLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjBln;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjFields;

/**
 * 嘉里大榮 PickupV2（常溫取件 / 退貨）單筆 17 欄
 *
 * - 方向依官方欄位中文名：Shipper* = 取件人（顧客）、Consignee* = 收貨人（商家，取寄件人設定）
 *   （第三方 matrix-platform 實作相反，不照抄；skill api-reference §6）
 * - 只收常溫號段（規格書只有常溫取件 API）；BLN 另由常溫號段配發
 * - PickupNo（≤ 30）= 訂單編號 + 「-」+ 取件單號末 5 碼：測試機實測重複 PickupNo 會被擋（zenbu），
 *   同一張取件單重試時單號不變 → 取件編號也不變
 * - 長度：取件人姓名 20、電話 20、取件地址 120、收貨人姓名 6、收貨地址 100、備註 100
 *
 * @see .claude/skills/kerrytj-logistics/references/api-reference.md §6
 */
final class PickupParams {

	/** @var int 取件編號上限 */
	private const PICKUP_NO_MAX = 30;

	/**
	 * Constructor
	 *
	 * @param array<string, string> $fields 17 欄
	 */
	private function __construct( private readonly array $fields ) {
	}

	/**
	 * 由訂單組取件欄位
	 *
	 * @param \WC_Order                   $order    訂單
	 * @param KerrytjLogisticsSettingsDTO $settings 設定
	 * @param string                      $bln      取件單號（常溫 11 碼）
	 * @param string                      $remark   備註（如原託運單號）
	 * @return self
	 * @throws KerrytjLogisticsException VALIDATION
	 */
	public static function from_order( \WC_Order $order, KerrytjLogisticsSettingsDTO $settings, string $bln, string $remark = '' ): self {
		if (KerrytjBlnKind::AMBIENT !== KerrytjBln::kind_of( $bln )) {
			throw KerrytjLogisticsException::validation( '嘉里大榮取件只支援常溫號段（11 碼）' );
		}
		$sender = ShipmentDeliveryParams::sender( $settings );

		$customer = Recipient::from_order( $order );
		if ('' === $customer->name) {
			throw KerrytjLogisticsException::validation( '嘉里大榮取件缺少取件人姓名' );
		}
		if ('' === $customer->zip3) {
			throw KerrytjLogisticsException::validation( '無法判斷取件地址的 3 碼郵遞區號，請確認郵遞區號或縣市與鄉鎮市區' );
		}

		$suffix = '-' . \substr( $bln, -5 );
		$head   = KerrytjFields::cut( (string) $order->get_order_number(), self::PICKUP_NO_MAX - \strlen( $suffix ) );

		return new self(
			[
				'BLN'           => $bln,
				'PickupNo'      => $head . $suffix,
				'CustomerNo'    => $settings->customer_no,
				'Consignee'     => KerrytjFields::cut( $sender['name'], 6 ),
				'ConsigneeTel1' => ShipmentDeliveryParams::require_within( '退貨收件電話', $sender['phone'], 20 ),
				'ConsigneeTel2' => '',
				'ConsigneePost' => $sender['zip3'],
				'ConsigneeAdd'  => ShipmentDeliveryParams::require_within( '退貨收件地址', $sender['address'], 100 ),
				'Shipper'       => KerrytjFields::cut( $customer->name, 20 ),
				'ShipperTel1'   => ShipmentDeliveryParams::require_within( '取件人電話', $customer->phone, 20 ),
				'ShipperTel2'   => '',
				'ShipperPost'   => $customer->zip3,
				'ShipperAdd'    => ShipmentDeliveryParams::require_within( '取件地址', $customer->address, 120 ),
				'Volume'        => '',
				'Weight'        => '',
				'Remark'        => KerrytjFields::cut( $remark, 100 ),
				'Piece'         => '1',
			]
		);
	}

	/** @return array<string, string> 17 欄 */
	public function to_api_row(): array {
		return $this->fields;
	}
}
