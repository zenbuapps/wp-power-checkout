<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Exceptions;

use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 嘉里大榮失敗（帶正規化錯誤碼與嘉里大榮原始錯誤文字）
 *
 * 嘉里大榮沒有錯誤碼：寫入類失敗只有 `Data[].Error` 中文（如「客戶編號輸入錯誤」「託運單號重複」），
 * Report 失敗為 ASP.NET ModelState、貨況失敗為 `Error`。raw_code 保存原文供後台判讀；訊息絕不含帳密。
 *
 * @see .claude/skills/kerrytj-logistics/references/api-reference.md §2
 */
final class KerrytjLogisticsException extends \RuntimeException {

	/**
	 * Constructor
	 *
	 * @param string      $message    訊息
	 * @param ErrorCode   $error_code 正規化錯誤碼
	 * @param string|null $raw_code   嘉里大榮原文（傳輸層 / 本地驗證為 null）
	 */
	public function __construct(
		string $message,
		public readonly ErrorCode $error_code = ErrorCode::PROVIDER,
		public readonly ?string $raw_code = null
	) {
		parent::__construct( $message );
	}

	/**
	 * 寫入類（ShipmentDelivery / UpdateShipmentDelivery / PickupV2）業務失敗
	 *
	 * 「重複」→ CONFLICT，其餘（客編錯 / 欄位錯 / 已交站所等）→ VALIDATION。
	 *
	 * @param string $operation 操作名稱
	 * @param string $bln       單號
	 * @param string $reason    嘉里大榮 Error 原文
	 * @return self
	 */
	public static function from_write_error( string $operation, string $bln, string $reason ): self {
		$reason = \trim( $reason, " \t\n\r\0\x0B," );
		return new self(
			\sprintf( '嘉里大榮%1$s失敗（託運單號 %2$s）：%3$s', $operation, $bln, $reason ),
			\str_contains( $reason, '重複' ) ? ErrorCode::CONFLICT : ErrorCode::VALIDATION,
			$reason
		);
	}

	/** @return bool 是否為「託運單號重複」（不含「取件編號重複」單獨出現） */
	public function is_duplicate_bln(): bool {
		return null !== $this->raw_code && \str_contains( $this->raw_code, '託運單號重複' );
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
	 * 狀態衝突（尚未建單 / 已交寄 / 環境不符等）
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
		return new self( \sprintf( '嘉里大榮（宅配）不支援%s', $operation ), ErrorCode::UNSUPPORTED );
	}
}
