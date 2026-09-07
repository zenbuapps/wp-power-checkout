<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

/**
 * 導回同步查詢的結果
 *
 * ReturnSyncManager::sync() 的回傳值，供呼叫端判讀與測試斷言用。
 * 本 enum 只表達「發生了什麼」，不表達「是否為錯誤」——
 * 多數 SKIPPED_* 都是正常情境（客戶重訪、已認列過等）。
 *
 * @see specs/open-issue/issue-18-plan.md §流程 1
 */
enum ReturnSyncResult: string {

	/** 查詢字串沒有 tradeOrderId 或格式不合（正常情境，例如客戶自行重訪） */
	case SKIPPED_NO_TRADE_ID = 'skipped_no_trade_id';

	/** 訂單金鑰（order_key）缺席或不符，不打 API（資安閘門） */
	case SKIPPED_INVALID_KEY = 'skipped_invalid_key';

	/** 訂單已非 pending / failed，代表已認列過（冪等） */
	case SKIPPED_NOT_PENDING = 'skipped_not_pending';

	/** 30 秒內已查詢過，節流跳過（避免客戶連續重新整理造成 API 風暴） */
	case SKIPPED_THROTTLED = 'skipped_throttled';

	/** 查詢回傳的 referenceOrderId 不是本訂單（資安事件） */
	case MISMATCHED_ORDER = 'mismatched_order';

	/** 查詢 API 連線失敗或回業務錯誤碼，改由 Webhook 認列 */
	case API_FAILED = 'api_failed';

	/** 查詢成功但寫入 meta / 轉換狀態時發生例外，改由 Webhook 認列 */
	case SETTLE_FAILED = 'settle_failed';

	/** 查詢成功並已交由 StatusManager 處理 */
	case UPDATED = 'updated';

	/** @return string 取得結果的標籤 */
	public function label(): string {
		return match ( $this ) {
			self::SKIPPED_NO_TRADE_ID => '跳過：查詢字串無 tradeOrderId',
			self::SKIPPED_INVALID_KEY => '跳過：order_key 驗證未通過',
			self::SKIPPED_NOT_PENDING => '跳過：訂單已非等待付款狀態',
			self::SKIPPED_THROTTLED => '跳過：節流中',
			self::MISMATCHED_ORDER => '拒絕：查詢結果不屬於本訂單',
			self::API_FAILED => '失敗：查詢 API 未成功回應',
			self::SETTLE_FAILED => '失敗：查詢成功但認列時發生錯誤',
			self::UPDATED => '成功：已依查詢結果更新訂單',
		};
	}
}
