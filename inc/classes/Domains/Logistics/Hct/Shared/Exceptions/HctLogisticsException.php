<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\Shared\Exceptions;

use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 新竹物流失敗（帶正規化錯誤碼與新竹原始錯誤訊息）
 *
 * 新竹 EDI 沒有錯誤碼，業務失敗只有每筆 `success=N` + `ErrMsg` 中文訊息（如「公司名稱或密碼錯誤」「訂單編號重複」），
 * 故 raw_code 保存 ErrMsg 原文供後台判讀。訊息絕不含公司名稱 / 密碼。
 *
 * @see .claude/skills/hct-logistics/references/api-reference.md §9
 */
final class HctLogisticsException extends \RuntimeException {

	/**
	 * Constructor
	 *
	 * @param string      $message    訊息
	 * @param ErrorCode   $error_code 正規化錯誤碼
	 * @param string|null $raw_code   新竹 ErrMsg 原文（傳輸層 / 本地驗證為 null）
	 */
	public function __construct(
		string $message,
		public readonly ErrorCode $error_code = ErrorCode::PROVIDER,
		public readonly ?string $raw_code = null
	) {
		parent::__construct( $message );
	}

	/**
	 * 由新竹 ErrMsg 建立例外並分類
	 *
	 * @param string $operation 操作名稱（如 TransData）
	 * @param string $err_msg   新竹 ErrMsg
	 * @return self
	 */
	public static function from_err_msg( string $operation, string $err_msg ): self {
		$text = '' !== \trim( $err_msg ) ? \trim( $err_msg ) : '未知錯誤';
		return new self(
			\sprintf( '新竹物流 %1$s 失敗：%2$s', $operation, $text ),
			self::classify( $text ),
			$text
		);
	}

	/**
	 * ErrMsg → 正規化錯誤碼
	 *
	 * - 帳密錯 / 「測試環境」用了正式帳密 → AUTH
	 * - 重複（訂單編號 / 貨號 / 一個月內 / 三個月內）、已上傳過 → CONFLICT
	 * - 查無 → NOT_FOUND
	 * - 其餘欄位錯誤（地址 / 電話 / 件數 / 重量 / 檢查碼…）→ VALIDATION
	 *
	 * @param string $err_msg ErrMsg
	 * @return ErrorCode
	 */
	public static function classify( string $err_msg ): ErrorCode {
		return match ( true ) {
			\str_contains( $err_msg, '密碼' ) => ErrorCode::AUTH,
			\str_contains( $err_msg, '重複' ), \str_contains( $err_msg, '已經上傳' ) => ErrorCode::CONFLICT,
			\str_contains( $err_msg, '查無' ) => ErrorCode::NOT_FOUND,
			default => ErrorCode::VALIDATION,
		};
	}

	/**
	 * 本地驗證失敗
	 *
	 * @param string $message 訊息
	 * @return self
	 */
	public static function validation( string $message ): self {
		return new self( $message, ErrorCode::VALIDATION );
	}

	/**
	 * 狀態衝突（尚未建單 / 無標籤等）
	 *
	 * @param string $message 訊息
	 * @return self
	 */
	public static function conflict( string $message ): self {
		return new self( $message, ErrorCode::CONFLICT );
	}

	/**
	 * 不支援的操作
	 *
	 * @param string $operation 操作名稱
	 * @return self
	 */
	public static function unsupported( string $operation ): self {
		return new self( \sprintf( '新竹物流（宅配）不支援%s', $operation ), ErrorCode::UNSUPPORTED );
	}
}
