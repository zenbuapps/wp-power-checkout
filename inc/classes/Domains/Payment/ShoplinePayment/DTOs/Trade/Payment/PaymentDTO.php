<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Params;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\ActionTypeTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\AdditionalDataTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\NextActionTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\OrderTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\PaymentMsgTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\PaymentTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\ReferenceOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\StatusTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\TradeOrderIdTrait;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\Webhook;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\PaymentMethod;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseStatus;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseSubStatus;
use J7\WpUtils\Classes\DTO;
use J7\WpUtils\Classes\WP;

/**
 * Shopline Payment 跳轉式支付 SessionDTO
 *
 * @see https://docs.shoplinepayments.com/api/trade/query/
 */
final class PaymentDTO extends DTO {
	use ReferenceOrderIdTrait;
	use TradeOrderIdTrait;
	use StatusTrait;
	use PaymentMsgTrait;
	use ActionTypeTrait;
	use NextActionTrait;
	use OrderTrait;
	use PaymentTrait;
	use AdditionalDataTrait;

	/** @var array<string> 必填屬性 */
	protected array $require_properties = [
		'referenceOrderId',
		'tradeOrderId',
		'status',
		'order',
		'payment',
	];

	/**
	 * 創建實例
	 *
	 * @param array $args 參數
	 * @return self 實例
	 * @throws \Exception DTO 實例化失敗
	 */
	public static function create( array $args ): self {
		$args['order']   = Webhook\Order::create( $args['order'] ?? [] );
		$args['payment'] = Webhook\Payment::create( $args['payment'] ?? [] );
		if (isset($args['paymentMsg']) && \is_array($args['paymentMsg'])) {
			$args['paymentMsg'] = new Components\ErrorMessage( $args['paymentMsg'] );
		}
		return new self( $args );
	}

	/**
	 * 從已經付款後的訂單拿到 PaymentDTO
	 *
	 * @param \WC_Order $order 已經付款後的訂單
	 *
	 * @return self
	 */
	public static function from_order( \WC_Order $order ): self {
		$param = ( new Params( $order) )->get_payment_detail();
		return self::create($param);
	}


	/** @return string 付款詳情 html */
	public function to_human_html(): string {
		$status_enum     = ResponseStatus::tryFrom($this->status);
		$sub_status_enum = isset( $this->subStatus) ? ResponseSubStatus::tryFrom( $this->subStatus) : null;

		$payment_method = PaymentMethod::tryFrom( $this->payment->paymentMethod )?->label() ?? $this->payment->paymentMethod;

		$title = \sprintf(
			'%1$s 付款狀態：%2$s %3$s <br> 付款方式：%4$s',
			$status_enum?->emoji(),
			$status_enum?->label(),
			$sub_status_enum?->label() ? "- {$sub_status_enum?->label()}" : '',
			$payment_method
		);

		if ($this->paymentMsg?->code) {
			$msg_dto   = $this->paymentMsg;
			$msg_array = $msg_dto->to_human_array();
			$msg       = \reset($msg_array );
			$title    .= "<p style='margin-bottom: 0px;'>{$msg}</p>";

			\add_action(
				'woocommerce_before_thankyou',
				static function ( $order_id ) use ( $msg ) { // phpcs:ignore
					\wc_print_notice( "付款失敗，{$msg}", 'error' );
				}
				);
		}

		$title .= '<p style="margin-bottom: 0px;">&nbsp;</p>';

		return WP::array_to_html(
			array_merge(
			$this->payment->to_human_array(),
			[
				'referenceOrderId' => $this->referenceOrderId,
				'tradeOrderId'     => $this->tradeOrderId,
			]
			),
			[ 'title' => $title ]
			);
	}


	/**  @return PaymentMethod 取得付款方式  */
	public function get_payment_method(): PaymentMethod {
		return PaymentMethod::from( $this->payment->paymentMethod );
	}
}
