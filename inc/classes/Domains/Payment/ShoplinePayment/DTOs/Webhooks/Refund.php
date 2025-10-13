<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\AmountTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\ReferenceOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\RefundMsgTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\RefundOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\StatusTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\TradeOrderIdTrait;
use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

/**
 * 退款交易
 *
 * @see https://docs.shoplinepayments.com/api/event/model/refund/
 */
final class Refund extends DTO {
	use ReferenceOrderIdTrait;
	use TradeOrderIdTrait;
	use AmountTrait;
	use StatusTrait;
	use RefundOrderIdTrait;
	use RefundMsgTrait;


	/** @var string|null 第三方平台流水號，街口支付和 LINE Pay 特店對帳使用 選填 */
	public string|null $channelDealId;

	/** @var array 必填屬性 */
	protected array $require_properties = [
		'refundOrderId',
		'referenceOrderId',
		'tradeOrderId',
		'amount',
		'status',
	];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 * @param array<string, mixed> $args 原始資料
	 */
	public static function create( array $args ): self {
		$args['amount'] = Components\Amount::parse( $args['amount'] );
		if ( isset( $args['refundMsg'] ) ) {
			$args['refundMsg'] = Components\ErrorMessage::parse( $args['refundMsg'] );
		}
		return new self( $args );
	}
}
