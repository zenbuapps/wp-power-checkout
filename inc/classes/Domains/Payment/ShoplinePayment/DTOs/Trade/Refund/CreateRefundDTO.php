<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Refund;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Amount;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\AdditionalDataTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\ReferenceOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\TradeOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\AmountTrait;
use J7\PowerCheckout\Utils\Helper;
use J7\WpUtils\Classes\DTO;

class CreateRefundDTO extends DTO {
	use ReferenceOrderIdTrait;
	use TradeOrderIdTrait;
	use AmountTrait;
	use AdditionalDataTrait;

	/** @var string (256) 退款原因 */
	public string $reason;

	/** @var string (256) Event Webhook callback 的 URL */
	public string $callbackUrl;

	/** @var array<string, string|int> 原始資料 */
	protected array $require_properties = [
		'referenceOrderId',
		'tradeOrderId',
		'amount',
	];


	/**
	 * @param \WC_Order $order 訂單
	 * @param float     $amount 退款金額
	 * @param string    $reason 退款原因
	 *
	 * @return self
	 * @throws \Exception DTO 錯誤
	 */
	public static function create( \WC_Order $order, float $amount, string $reason = '' ): self {
		$payment_dto = PaymentDTO::from_order($order);

		$args = [
			'referenceOrderId' => $payment_dto->referenceOrderId,
			'tradeOrderId'     => $payment_dto->tradeOrderId,
			'amount'           => Amount::create($amount),
			'reason'           => $reason ?? static::get_default_reason($amount),
		// 'callbackUrl' => '',
		// 'additionalData' => ''
		];

		return new self($args);
	}

	/** @return string 取得預設的 reason */
	private static function get_default_reason( float $amount ): string {
		$current_user = \wp_get_current_user();
		$reason       = "網站後台退款 {$amount} 元";
		if ($current_user) {
			$reason .= "，由 {$current_user->display_name} 操作";
		}
		return $reason;
	}


	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	protected function validate(): void {
		parent::validate();
		if (isset($this->reason)) {
			( new Helper($this->reason, 'reason', 256 ) )->validate_strlen();
		}
		if (isset($this->callbackUrl)) {
			( new Helper($this->callbackUrl, 'callbackUrl', 256 ) )->validate_strlen();
		}
	}
}
