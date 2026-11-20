<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\Shared\Exceptions;

use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * LINE Pay API 呼叫失敗（帶正規化錯誤碼與原始 returnCode）
 *
 * 訊息只含 returnCode / returnMessage / HTTP 狀態，絕不含 Channel Secret 或簽章。
 */
final class LinepayApiException extends \RuntimeException {

	/**
	 * Constructor
	 *
	 * @param string      $message    訊息
	 * @param ErrorCode   $error_code 正規化錯誤碼
	 * @param string|null $raw_code   LINE Pay returnCode（傳輸層失敗為 null）
	 */
	public function __construct(
		string $message,
		public readonly ErrorCode $error_code = ErrorCode::PROVIDER,
		public readonly ?string $raw_code = null
	) {
		parent::__construct( $message );
	}
}
