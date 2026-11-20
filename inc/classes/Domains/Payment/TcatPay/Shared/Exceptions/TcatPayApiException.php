<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Exceptions;

use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 黑貓PAY API 呼叫失敗（帶正規化錯誤碼）
 *
 * 訊息只含 HTTP 狀態與平台回傳的 msg，絕不含 cust_id 以外的憑證（api_password / hash_base / token）。
 */
final class TcatPayApiException extends \RuntimeException {

	/**
	 * Constructor
	 *
	 * @param string    $message    訊息
	 * @param ErrorCode $error_code 正規化錯誤碼
	 */
	public function __construct( string $message, public readonly ErrorCode $error_code = ErrorCode::PROVIDER ) {
		parent::__construct( $message );
	}
}
