<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\Shared\Enums;

use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * LINE Pay returnCode（HTTP 一律 200，成敗只看 body returnCode 字串）
 *
 * 0000 在「查詢付款請求狀態」API 代表「尚未認證」，其他 API 代表成功；
 * 01xx 只出現在狀態查詢。未列出的碼以 tryFrom() 取 null，正規化為 PROVIDER。
 *
 * @see .claude/skills/linepay/references/error-codes.md
 */
enum LinepayReturnCode: string {

	/** 成功（狀態查詢：顧客尚未完成認證） */
	case SUCCESS = '0000';

	/** 狀態查詢：顧客已認證，可 confirm */
	case AUTHENTICATED = '0110';

	/** 狀態查詢：顧客取消或認證逾時 */
	case CANCELLED = '0121';

	/** 狀態查詢：付款失敗 */
	case FAILED = '0122';

	/** 狀態查詢：付款完成 */
	case COMPLETED = '0123';

	/** 商店未註冊 / 憑證錯 / sandbox 與 production 混用 */
	case MERCHANT_NOT_FOUND = '1104';

	/** 缺 header 或簽章不符 */
	case HEADER_ERROR = '1106';

	/** 查無交易 */
	case TRANSACTION_NOT_FOUND = '1150';

	/** 超過可退款期限 */
	case REFUND_PERIOD_EXPIRED = '1163';

	/** 超出可退款金額 */
	case REFUND_AMOUNT_EXCEEDED = '1164';

	/** 已退款的交易 */
	case ALREADY_REFUNDED = '1165';

	/** 顧客尚未完成認證就 confirm（該筆會作廢） */
	case AUTH_NOT_COMPLETED = '1169';

	/** 重複 confirm / orderId 已存在 */
	case ALREADY_CONFIRMED = '1172';

	/** 超過付款期限（付款請求約 20 分鐘失效） */
	case PAYMENT_EXPIRED = '1180';

	/** API 呼叫重複 */
	case DUPLICATE_REQUEST = '1198';

	/** 參數錯誤（含 amount ≠ Σpackages） */
	case PARAMETER_ERROR = '2101';

	/** 內部錯誤 */
	case INTERNAL_ERROR = '9000';

	/**
	 * 是否代表「已付款，應查明細確認」（重複 confirm / 重複呼叫）
	 *
	 * @return bool
	 */
	public function means_already_confirmed(): bool {
		return \in_array( $this, [ self::ALREADY_CONFIRMED, self::DUPLICATE_REQUEST ], true );
	}

	/**
	 * 對應正規化錯誤碼
	 *
	 * @return ErrorCode
	 */
	public function to_error_code(): ErrorCode {
		return match ( $this ) {
			self::MERCHANT_NOT_FOUND, self::HEADER_ERROR => ErrorCode::AUTH,
			self::PARAMETER_ERROR, self::REFUND_AMOUNT_EXCEEDED, self::ALREADY_REFUNDED, self::PAYMENT_EXPIRED, self::AUTH_NOT_COMPLETED => ErrorCode::VALIDATION,
			self::ALREADY_CONFIRMED, self::DUPLICATE_REQUEST => ErrorCode::CONFLICT,
			self::TRANSACTION_NOT_FOUND => ErrorCode::NOT_FOUND,
			self::REFUND_PERIOD_EXPIRED => ErrorCode::UNSUPPORTED,
			default => ErrorCode::PROVIDER,
		};
	}

	/**
	 * 原始碼 → 正規化錯誤碼（未列出的碼 → PROVIDER）
	 *
	 * @param string $raw returnCode
	 * @return ErrorCode
	 */
	public static function error_code_for( string $raw ): ErrorCode {
		return self::tryFrom( $raw )?->to_error_code() ?? ErrorCode::PROVIDER;
	}
}
