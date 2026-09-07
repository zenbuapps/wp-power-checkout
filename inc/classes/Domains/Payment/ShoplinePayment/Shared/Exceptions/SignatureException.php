<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Exceptions;

/**
 * 驗簽不符例外
 *
 * 讓 Webhook 能區分兩種失敗：
 *  - 驗簽不符（本例外）→ 極可能是商家 signKey 設定錯誤，同一份 payload 之後仍可能成功，
 *    回 401 保留 SLP 重送機會。
 *  - 其餘業務失敗 → 重送不會讓結果改變，回 200 止血。
 *
 * @see specs/open-issue/issue-18-plan.md §決策 4
 */
final class SignatureException extends \Exception {
}
