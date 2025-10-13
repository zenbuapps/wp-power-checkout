<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ErrorCode;
use J7\WpUtils\Classes\DTO;

/**
 * ErrorMessage 付款錯誤訊息
 *
 * @see https://docs.shoplinepayments.com/appendix/errorCode/
 *  */
final class ErrorMessage extends DTO {
	/** @var string *錯誤碼，錯誤碼及錯誤描述詳情查看 */
	public string $code;

	/** @var string *錯誤描述，錯誤碼及錯誤描述詳情查看 */
	public string $msg;

	/** @var array<string> 必填屬性 */
	protected $required_properties = [
		'code',
		'msg',
	];

	/** @return array 人類可讀文字 */
	public function to_human_array(): array {
		$msg  = ErrorCode::tryFrom($this->code)?->label() ?? $this->msg;
		$msg .= " ( 錯誤碼：{$this->code} )";
		return [
			'錯誤訊息' => $msg,
		];
	}
}
