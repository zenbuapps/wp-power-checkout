<?php
/**
 * 物流統一狀態 ShippingState（ASM-1）
 *
 * 11 態，值與順序對齊 zenbu `apps/api-gateway/src/commerce/shipping/interfaces/shipping-gateway.types.ts`。
 * 既有 LogisticsStatus 為綠界原始碼 enum，不改；新物流 query_shipment() 以額外 `unified_state` 鍵回傳本 enum 值。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Enums;

/** 物流統一狀態 */
enum ShippingState: string {
	/** 已建立貨運單，等候出貨 */
	case PENDING = 'pending';
	/** 物流業者收單確認 */
	case ACCEPTED = 'accepted';
	/** 運送中 */
	case IN_TRANSIT = 'in_transit';
	/** 已抵達門市 */
	case ARRIVED = 'arrived';
	/** 可取貨 */
	case READY_FOR_PICKUP = 'ready_for_pickup';
	/** 已取貨（超商取貨完成） */
	case PICKED_UP = 'picked_up';
	/** 已送達（宅配） */
	case DELIVERED = 'delivered';
	/** 退回賣家 */
	case RETURNED = 'returned';
	/** 配送失敗 */
	case FAILED = 'failed';
	/** 逾期未取 */
	case EXPIRED_UNCLAIMED = 'expired_unclaimed';
	/** 已取消 */
	case CANCELLED = 'cancelled';

	/**
	 * 是否為終態（之後不會再變化）
	 *
	 * @return bool
	 */
	public function is_terminal(): bool {
		return match ( $this ) {
			self::PICKED_UP, self::DELIVERED, self::RETURNED, self::FAILED, self::EXPIRED_UNCLAIMED, self::CANCELLED => true,
			default => false,
		};
	}
}
