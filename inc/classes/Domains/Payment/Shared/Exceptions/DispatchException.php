<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Exceptions;

use J7\PowerCheckout\Shared\Errors\ErrorCode;

/**
 * 嵌入式 dispatch 的「可對外」業務錯誤
 *
 * IEmbeddedPaymentProvider::dispatch() 拋出此例外時，PaymentDispatchApiService 以其 HTTP 狀態碼、
 * 正規化錯誤碼與訊息回應（訊息必須是可直接給顧客看的通用文字，不得含金流原始錯誤）；
 * 其他 \Throwable 一律回 500 通用訊息。
 *
 * 用途：參數驗證失敗（400 VALIDATION）、重複送出（409 CONFLICT）等「顧客可修正 / 可等待」的情境。
 */
final class DispatchException extends \RuntimeException {

	/**
	 * Constructor
	 *
	 * @param ErrorCode $error_code  正規化錯誤碼
	 * @param string    $message     對外訊息（顧客可見，勿含內部細節）
	 * @param int       $http_status HTTP 狀態碼（4xx）
	 */
	public function __construct(
		public readonly ErrorCode $error_code,
		string $message,
		public readonly int $http_status = 400,
	) {
		parent::__construct( $message );
	}
}
