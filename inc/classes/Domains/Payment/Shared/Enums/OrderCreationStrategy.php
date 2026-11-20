<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Enums;

/**
 * 訂單建立策略（對齊 zenbu PaymentGateway 的 orderCreationStrategy）
 *
 * WooCommerce 在 process_payment 前已建立 pending 訂單，因此 PC 所有 gateway 皆為
 * PRE_CREATE_PENDING（ASM-9）；CALLBACK_CREATES_ORDER 保留只為與 zenbu 對齊。
 */
enum OrderCreationStrategy: string {
	/** 付款前先建立 pending 訂單 */
	case PRE_CREATE_PENDING = 'pre-create-pending';

	/** 由金流回呼建立訂單 */
	case CALLBACK_CREATES_ORDER = 'callback-creates-order';
}
