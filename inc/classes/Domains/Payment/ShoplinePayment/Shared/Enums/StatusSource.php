<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

/**
 * 訂單狀態變更的來源
 *
 * 付款成功後有兩條認列路徑（導回同步查詢 / Webhook 通知），
 * 於 order note 標題冠上來源，讓客服一眼看出是誰認列的。
 */
enum StatusSource: string {

	/** 客戶付款後導回 order-received 頁時的同步查詢 */
	case RETURN_SYNC = 'return_sync';

	/** SLP 非同步 Webhook 通知 */
	case WEBHOOK = 'webhook';

	/** @return string 取得來源的標籤 */
	public function label(): string {
		return match ( $this ) {
			self::RETURN_SYNC => '[導回同步]',
			self::WEBHOOK => '[Webhook]',
		};
	}
}
