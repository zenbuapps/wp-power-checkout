<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Exceptions;

/**
 * APN 暫時無法確認（平台查詢節流中 / 已收款觸發但平台查詢尚未確認），要求平台稍後重送
 *
 * 由 process_callback 拋出 → WebhookPipeline 記為 HANDLER_ERROR（冪等鍵不寫）→ ack 回非 OK。
 */
final class TcatPayRetryLaterException extends \RuntimeException {
}
