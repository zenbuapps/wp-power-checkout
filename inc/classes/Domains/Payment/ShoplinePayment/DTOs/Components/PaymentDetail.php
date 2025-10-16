<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\StatusTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\TradeOrderIdTrait;
use J7\WpUtils\Classes\DTO;

/**
 * PaymentDetail 付款方式詳細資訊
 * 回應會帶
 *  */
final class PaymentDetail extends DTO {
	use TradeOrderIdTrait;
	use StatusTrait;

	/** @var int *付款成功时间 */
	public int $paymentSuccessTime;

	/** @var string (512) *付款方式 */
	public string $paymentMethod;

	/** @var array<string> 必填屬性 */
	protected array $required_properties = [ 'tradeOrderId', 'status', 'paymentSuccessTime', 'paymentMethod' ];
}
