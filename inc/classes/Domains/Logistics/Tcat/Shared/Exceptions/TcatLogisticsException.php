<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Exceptions;

use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 黑貓宅急便直串失敗（帶正規化錯誤碼與黑貓原始錯誤碼 Exxx）
 *
 * 黑貓回應外殼沒有獨立錯誤碼欄位，代碼內嵌於 Message：「執行失敗 -> E009-契客資料不正確。」
 * （2026-11-21 以假憑證實打測試站確認）。訊息絕不含 CustomerToken。
 *
 * @see .claude/skills/tcat-logistics/references/api-reference.md §16
 */
final class TcatLogisticsException extends \RuntimeException {

	/** @var array<int, string> 契約 / 權限類（不可重試，通知營運聯繫速達） */
	private const AUTH_CODES = [ 'E007', 'E009', 'E011', 'E012', 'E013', 'E014', 'E015', 'E016' ];

	/** @var array<int, string> 查無資料類 */
	private const NOT_FOUND_CODES = [ 'E071', 'E073', 'E077', 'E080' ];

	/** @var array<int, string> 狀態衝突類（依業務流程處理） */
	private const CONFLICT_CODES = [ 'E070', 'E074', 'E075', 'E076', 'E079' ];

	/**
	 * Constructor
	 *
	 * @param string      $message    訊息
	 * @param ErrorCode   $error_code 正規化錯誤碼
	 * @param string|null $raw_code   黑貓錯誤碼（E001–E091；傳輸層失敗為 null）
	 */
	public function __construct(
		string $message,
		public readonly ErrorCode $error_code = ErrorCode::PROVIDER,
		public readonly ?string $raw_code = null
	) {
		parent::__construct( $message );
	}

	/**
	 * 由黑貓回應 Message 建立例外（解析內嵌的 Exxx 碼並分類）
	 *
	 * @param string $service 服務名稱（如 PrintOBT）
	 * @param string $message 黑貓回應 Message
	 * @return self
	 */
	public static function from_api_message( string $service, string $message ): self {
		$raw_code = 1 === \preg_match( '/\b(E\d{3})\b/', $message, $matches ) ? $matches[1] : null;
		$text     = '' !== \trim( $message ) ? \trim( $message ) : '未知錯誤';

		return new self(
			\sprintf( '黑貓宅急便 %1$s 失敗：%2$s', $service, $text ),
			self::classify( $raw_code ),
			$raw_code
		);
	}

	/**
	 * 黑貓錯誤碼 → 正規化錯誤碼（對齊 skill §16.4 分類；限流 / 暫時性 / 未知歸 PROVIDER，保留 raw_code）
	 *
	 * @param string|null $raw_code 黑貓錯誤碼
	 * @return ErrorCode
	 */
	public static function classify( ?string $raw_code ): ErrorCode {
		if (null === $raw_code) {
			return ErrorCode::PROVIDER;
		}
		if (\in_array( $raw_code, self::AUTH_CODES, true )) {
			return ErrorCode::AUTH;
		}
		if (\in_array( $raw_code, self::NOT_FOUND_CODES, true )) {
			return ErrorCode::NOT_FOUND;
		}
		if (\in_array( $raw_code, self::CONFLICT_CODES, true )) {
			return ErrorCode::CONFLICT;
		}

		$number        = (int) \substr( $raw_code, 1 );
		$is_validation = ( $number >= 1 && $number <= 6 )
		|| ( $number >= 17 && $number <= 62 )
		|| 68 === $number || 69 === $number
		|| 82 === $number || 83 === $number
		|| ( $number >= 88 && $number <= 91 );

		return $is_validation ? ErrorCode::VALIDATION : ErrorCode::PROVIDER;
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
	 * 不支援的操作
	 *
	 * @param string $operation 操作名稱
	 * @return self
	 */
	public static function unsupported( string $operation ): self {
		return new self( \sprintf( '黑貓宅急便（宅配直串）不支援%s', $operation ), ErrorCode::UNSUPPORTED );
	}
}
