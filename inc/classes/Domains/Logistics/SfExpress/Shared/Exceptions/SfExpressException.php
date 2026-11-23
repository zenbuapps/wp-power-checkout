<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Exceptions;

use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 順豐失敗（帶正規化錯誤碼與順豐原始碼）
 *
 * 順豐兩層錯誤：
 *  - 平台層 apiResultCode ≠ 0（500 token invalid / 1005 App is not exist / 2001 Signature is invalid / 504 系統繁忙）
 *  - 業務層解密後 success=false + code（123052 客戶訂單號重複 / 123002 訂單不存在 / 123057 寄件類型必填…）
 * raw_code 保存原始碼字串供後台判讀。訊息絕不含 appKey / appSecret / AES 金鑰 / token / 月結卡號。
 *
 * @see .claude/skills/sf-express/references/error-codes.md
 */
final class SfExpressException extends \RuntimeException {

	/** @var string 業務碼：客戶訂單號與歷史訂單重複 */
	public const DUPLICATE_ORDER_CODE = '123052';

	/** @var string 業務碼：訂單不存在或已歸檔 */
	public const ORDER_NOT_FOUND_CODE = '123002';

	/**
	 * Constructor
	 *
	 * @param string      $message    訊息
	 * @param ErrorCode   $error_code 正規化錯誤碼
	 * @param string|null $raw_code   順豐原始碼（本地驗證 / 傳輸層為 null）
	 */
	public function __construct(
		string $message,
		public readonly ErrorCode $error_code = ErrorCode::PROVIDER,
		public readonly ?string $raw_code = null
	) {
		parent::__construct( $message );
	}

	/**
	 * 平台層錯誤（apiResultCode ≠ 0）
	 *
	 * - 500 + token invalid / 1005 App is not exist → AUTH
	 * - 2001 Signature is invalid → SIGNATURE
	 * - 505 The format of the request body is incorrect（2026-11-21 實打：AES 金鑰錯、順豐解不開 body）→ SIGNATURE
	 * - 504 系統繁忙 → NETWORK（可稍後重試）
	 * - 其餘 → PROVIDER
	 *
	 * @param string $action  動作名稱（中文）
	 * @param string $code    apiResultCode
	 * @param string $message apiErrorMsg
	 * @return self
	 */
	public static function platform( string $action, string $code, string $message ): self {
		$error_code = match ( true ) {
			self::is_token_invalid( $code, $message ), '1005' === $code => ErrorCode::AUTH,
			'2001' === $code, '505' === $code => ErrorCode::SIGNATURE,
			'504' === $code => ErrorCode::NETWORK,
			default => ErrorCode::PROVIDER,
		};
		$text = '' !== \trim( $message ) ? \trim( $message ) : '平台錯誤';
		if ('505' === $code) {
			$text .= '，請確認 AES 金鑰（encodingAesKey）與 appKey 是否為同一個應用';
		}
		return new self( \sprintf( '順豐%1$s失敗：%2$s（%3$s）', $action, $text, $code ), $error_code, $code );
	}

	/**
	 * 業務層錯誤（success=false）
	 *
	 * @param string $action  動作名稱（中文）
	 * @param string $code    業務碼
	 * @param string $message 業務訊息
	 * @return self
	 */
	public static function business( string $action, string $code, string $message ): self {
		$error_code = match ( $code ) {
			self::DUPLICATE_ORDER_CODE => ErrorCode::CONFLICT,
			self::ORDER_NOT_FOUND_CODE => ErrorCode::NOT_FOUND,
			default => ErrorCode::PROVIDER,
		};
		$text   = '' !== \trim( $message ) ? \trim( $message ) : '未知錯誤';
		$suffix = '' !== $code ? "（{$code}）" : '';
		return new self( \sprintf( '順豐%1$s失敗：%2$s%3$s', $action, $text, $suffix ), $error_code, '' !== $code ? $code : null );
	}

	/**
	 * 是否為 token 失效（apiResultCode=500 且訊息為 Access denied, token invalid）
	 *
	 * 不能只看 500：順豐同時把 500 用在「系統異常」，那種錯誤換 token 也不會好。
	 *
	 * @param string $code    apiResultCode
	 * @param string $message apiErrorMsg
	 * @return bool
	 */
	public static function is_token_invalid( string $code, string $message ): bool {
		return '500' === $code && 1 === \preg_match( '/token\s+invalid/i', $message );
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
	 * 狀態衝突（尚未建單 / 已收件 / 重複單號不符等）
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
		return new self( \sprintf( '順豐速運（宅配）不支援%s', $operation ), ErrorCode::UNSUPPORTED );
	}
}
