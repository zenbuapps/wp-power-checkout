<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Enums;

/**
 * 統一 webhook 外框（WebhookPipeline）單次處理結果
 *
 * 交給 gateway 的 callback_ack_response() 決定回應內容
 * （例：黑貓PAY 平台查詢逾時要回非 OK 讓平台重送）。
 */
enum WebhookOutcome: string {
	/** 已處理（handler 成功，冪等鍵已寫入） */
	case PROCESSED = 'PROCESSED';

	/** 重送：冪等鍵已存在，未再處理 */
	case DUPLICATE = 'DUPLICATE';

	/** 驗簽失敗（含驗簽過程拋例外） */
	case SIGNATURE_INVALID = 'SIGNATURE';

	/** 找不到對應訂單 */
	case NOT_FOUND = 'NOT_FOUND';

	/** Handler 拋例外（冪等鍵不寫入，允許重送重試） */
	case HANDLER_ERROR = 'UNKNOWN';
}
