<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\ActionTypeTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\AdditionalDataTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\NextActionTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\OrderTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\PaymentMsgTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\PaymentTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\ReferenceOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\StatusTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\TradeOrderIdTrait;
use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseStatus;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseSubStatus;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Webhook;

/**
 * 付款交易
 *
 * @see https://docs.shoplinepayments.com/api/event/model/payment/
 */
final class Payment extends DTO {
	use ReferenceOrderIdTrait;
	use TradeOrderIdTrait;
	use StatusTrait;
	use PaymentMsgTrait;
	use ActionTypeTrait;
	use NextActionTrait;
	use OrderTrait;
	use PaymentTrait;
	use AdditionalDataTrait;

	/** @var array 必填屬性 */
	protected array $require_properties = [
		'referenceOrderId',
		'tradeOrderId',
		'status',
		'order',
		'payment',
	];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 * @param array<string, mixed> $args 原始資料
	 */
	public static function create( array $args ): self {
		$args['order']      = Webhook\Order::create( $args['order'] );
		$args['payment']    = Webhook\Payment::create( $args['payment'] );
		$args['paymentMsg'] = new Components\ErrorMessage( $args['paymentMsg'] );
		return new self( $args );
	}

	/** 自訂驗證邏輯 */
    protected function validate(): void {
		parent::validate();
		if (isset($this->status)) {
			ResponseStatus::from($this->status);
		}

		if ( isset($this->subStatus) ) {
			ResponseSubStatus::from($this->subStatus);
		}
	}
}
