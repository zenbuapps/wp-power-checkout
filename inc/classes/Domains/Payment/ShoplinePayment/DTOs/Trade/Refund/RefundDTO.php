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
use J7\WpUtils\Classes\WP;

class RefundDTO extends DTO {
	use RefundOrderIdTrait;
	use ReferenceOrderIdTrait;
	use TradeOrderIdTrait;
	use AmountTrait;
	use StatusTrait;
	use RefundMsgTrait;

	/** @var array 必填屬性 */
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
	 * @return static 實例
	 */
	public static function create( array $args ): static {
		$args['amount'] = Components\Amount::parse( $args['amount'] );
		if ( isset( $args['refundMsg'] ) ) {
			$args['refundMsg'] = Components\ErrorMessage::parse( $args['refundMsg'] );
		}
		return new static( $args );
	}

	/** 驗證參數 */
	protected function validate(): void {
		parent::validate();
		ResponseStatus::from( $this->status );
	}

	/** @return array 人類可讀文字 */
	public function to_human_array(): array {
		return \array_merge(
			$this->amount->to_human_array(),
			$this->refundMsg?->to_human_array() ?? [],
			[
				'refundOrderId'    => $this->refundOrderId,
				'referenceOrderId' => $this->referenceOrderId,
				'tradeOrderId'     => $this->tradeOrderId,
			]
		);
	}

	/**
	 * @param string $reason 原因
	 * @return string 人類可讀文字
	 */
	public function to_human_html( string $reason = '' ): string {
		$title  = $this->to_human_title($reason);
		$title .= '<p style="margin-bottom: 0px;">&nbsp;</p>';

		return WP::array_to_html($this->to_human_array(), [ 'title' => $title ] );
	}

	/**
	 * @param string $reason 原因
	 * @return string 人類可讀文字
	 */
	public function to_human_title( string $reason = '' ): string {
		$status = ResponseStatus::from( $this->status );
		$title  = "{$status->emoji()} 退款狀態：{$status->label()}";
		if ($this->refundMsg?->code) {
			$msg_array = $this->refundMsg->to_human_array();
			$msg       = \reset( $msg_array);
			$title    .= ": {$msg}";
		}
		if ($reason) {
			$title .= "<p>退款原因：{$reason}</p>";
		}
		return $title;
	}
}
