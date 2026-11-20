<?php
/**
 * 物流配送方式 DeliveryMethod（provider meta 的 supported_delivery_methods 用）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Enums;

/** 物流配送方式 */
enum DeliveryMethod: string {
	/** 宅配 */
	case HOME = 'HOME';
	/** 超商取貨 */
	case CVS = 'CVS';
}
