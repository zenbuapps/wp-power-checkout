<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Refund;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\AmountTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\ReferenceOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\RefundMsgTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\RefundOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\StatusTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\TradeOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseStatus;
use J7\WpUtils\Classes\DTO;

class RefundDTO extends DTO {
	use RefundOrderIdTrait;
	use ReferenceOrderIdTrait;
	use TradeOrderIdTrait;
	use AmountTrait;
	use StatusTrait;
	use RefundMsgTrait;

	protected array $require_properties = [
		'refundOrderId',
		'referenceOrderId',
		'tradeOrderId',
		'amount',
		'status',
	];

	/**
	 * 創建實例
	 *
	 * @param array $args 參數
	 * @return self 實例
	 */
	public static function create( array $args ): self {
		$args['amount'] = Components\Amount::parse( $args['amount'] );
		if ( isset( $args['refundMsg'] ) ) {
			$args['refundMsg'] = Components\ErrorMessage::parse( $args['refundMsg'] );
		}
		return new self( $args );
	}

	/** 驗證參數 */
	protected function validate(): void {
		parent::validate();
		ResponseStatus::from( $this->status );
	}

	/** @return array 人類可讀文字 */
	public function to_human_array(): array {
		return \array_merge(
			[ '狀態' => ResponseStatus::from( $this->status )->label() ],
			$this->amount->to_human_array(),
			$this->refundMsg?->to_human_array() ?? [],
			[
				'refundOrderId'    => $this->refundOrderId,
				'referenceOrderId' => $this->referenceOrderId,
				'tradeOrderId'     => $this->tradeOrderId,
			]
		);
	}
}
