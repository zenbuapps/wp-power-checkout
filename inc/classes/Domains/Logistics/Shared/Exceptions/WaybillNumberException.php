<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Exceptions;

use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 號段配號失敗（帶正規化錯誤碼；LogisticsApiService 依 error_code 映射 HTTP：NUMBER_EXHAUSTED → 409、VALIDATION → 422）
 */
final class WaybillNumberException extends \RuntimeException {

	/**
	 * Constructor
	 *
	 * @param string      $message    訊息
	 * @param ErrorCode   $error_code 正規化錯誤碼
	 * @param string|null $raw_code   原始碼（配號器無外部碼，保留欄位與其他物流例外一致）
	 */
	public function __construct(
		string $message,
		public readonly ErrorCode $error_code = ErrorCode::VALIDATION,
		public readonly ?string $raw_code = null
	) {
		parent::__construct( $message );
	}
}
