<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Refund;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Amount;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\AdditionalDataTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\ReferenceOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\TradeOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\AmountTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\WebHook;
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
		try {
			$payment_dto = PaymentDTO::from_order($order);
			$args        = [
				// Refund 的 referenceOrderID 是自己定義的，不是使用 payment 的 referenceOrderID
				// 也不能重複，如果重複會導致 1001 重複下單
				'referenceOrderId' => "RF_{$order->get_id()}_" . \time(),
				'tradeOrderId'     => $payment_dto->tradeOrderId,
				'amount'           => Amount::create($amount),
				'reason'           => $reason ?: static::get_default_reason($amount),
				'callbackUrl'      => WebHook::get_webhook_url(),
				// 'additionalData' => ''
			];

			return new self($args);
		} catch (\Throwable $e) {
			throw new \Exception("退款失敗，找不到訂單 #{$order->get_id()} 相關的付款詳情資料");
		}
	}

	/** @return string 取得預設的 reason */
	public static function get_default_reason( float $amount ): string {
		$reason = "網站後台退款 {$amount} 元";
		// $current_user = \wp_get_current_user();
		// if ($current_user) {
		// $reason .= "，由 {$current_user->display_name} 操作";
		// }
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
