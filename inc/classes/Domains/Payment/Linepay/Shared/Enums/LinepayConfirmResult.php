<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\Shared\Enums;

/**
 * LINE Pay confirm / 明細認列的結果（confirmUrl 與補單共用，決定導回頁）
 */
enum LinepayConfirmResult: string {

	/** 已認列付款（或先前已付款） */
	case PAID = 'paid';

	/** 尚未確認已付款（明細查無 CAPTURE），保持待付款 */
	case NOT_PAID = 'not_paid';

	/** 付款請求已逾期（1180），顧客需重新付款 */
	case EXPIRED = 'expired';

	/** 授權被拒（confirm 失敗：卡片 / 餘額等），顧客需重新付款 */
	case FAILED = 'failed';

	/** 金額 / orderId 不符，需人工確認 */
	case MISMATCH = 'mismatch';

	/**
	 * 是否應引導顧客重新付款（order-pay）
	 *
	 * @return bool
	 */
	public function needs_repay(): bool {
		return \in_array( $this, [ self::EXPIRED, self::FAILED ], true );
	}
}
