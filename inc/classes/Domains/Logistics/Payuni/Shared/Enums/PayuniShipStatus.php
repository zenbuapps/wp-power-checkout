<?php
/**
 * PAYUNi 物流貨態 ShipStatus
 *
 * PAYUNi 貨態為數字碼（11/21/22/31/32/33/41/43/44/46/51/52/53/55/56/81/82/91/92/98），
 * 與綠界（3 位數 / 4 位數混雜）完全不同。_pc_logistics_status 儲存 PAYUNi 原始碼字串。
 *
 * 貨態碼眾多且持續擴充（官方仍在新增），硬編完整表易過時。本 enum 僅承載代表性碼值，
 * 系統真正需要的單一語意「是否取貨完成」集中於 {@see self::is_pickup_completed()}。
 *
 * @see .claude/skills/payuni-logistics-v3/references/notify-and-status.md §ship-status
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;

/** PAYUNi 物流貨態（代表性碼；原始碼一律以字串儲存於 order meta） */
enum PayuniShipStatus: string {
	/** 未處理（剛建立物流單，尚未取得出貨單編號） */
	case UNPROCESSED = '91';
	/** 配送中 */
	case SHIPPING = '31';
	/** 待取貨（包裹配達門市） */
	case READY = '32';
	/** 已取貨（消費者已取貨，成功的終點） */
	case PICKED_UP = '11';
	/** 已取消 */
	case CANCELLED = '41';

	/**
	 * 取貨完成貨態碼集合（消費者已取貨）
	 *
	 * PAYUNi 以單一碼 11=已取貨 表示取貨完成（不分超商 / 宅配）。
	 * 集中於此常數，未來新增碼值不需改動其他模組。
	 *
	 * @var array<int, string>
	 */
	private const PICKUP_COMPLETED_CODES = [
		'11', // 消費者已取貨（超商 / 宅配共用）
	];

	/**
	 * 判定給定貨態碼是否為「取貨完成」
	 *
	 * COD 訂單於取貨完成貨態時標記 _pc_logistics_collection_paid=yes。
	 *
	 * @param string $status_code PAYUNi 回傳的 ShipStatus 原始碼字串
	 * @return bool
	 */
	public static function is_pickup_completed( string $status_code ): bool {
		return \in_array( $status_code, self::PICKUP_COMPLETED_CODES, true );
	}

	/**
	 * PAYUNi 原始貨態碼 → 統一貨態 ShippingState
	 *
	 * 剛建單時 query 回 "-"（尚無貨態），視為 pending；未知碼保守回 pending（不誤判終態）。
	 *
	 * @param string $status_code PAYUNi ShipStatus 原始碼
	 * @param bool   $is_cvs      是否為超商取貨（32 待取貨只在超商出現；宅配 31 即為配送中）
	 * @return ShippingState
	 */
	public static function to_unified_state( string $status_code, bool $is_cvs = true ): ShippingState {
		return match ( \trim( $status_code ) ) {
			'91', '92', '98', '21' => ShippingState::PENDING,
			'22'                   => ShippingState::ACCEPTED,
			'31'                   => ShippingState::IN_TRANSIT,
			'32'                   => $is_cvs ? ShippingState::READY_FOR_PICKUP : ShippingState::ARRIVED,
			'11'                   => $is_cvs ? ShippingState::PICKED_UP : ShippingState::DELIVERED,
			'33', '43', '44', '46', '81' => ShippingState::FAILED,
			'41'                   => ShippingState::CANCELLED,
			'52'                   => ShippingState::EXPIRED_UNCLAIMED,
			'51', '53', '55', '56', '82' => ShippingState::RETURNED,
			default                => ShippingState::PENDING,
		};
	}
}
