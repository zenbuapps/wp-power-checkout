<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers;

use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks\Body;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks\Payment;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks\Session;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\EventType;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Webhooks;
use J7\WpUtils\Classes\DTO;
use J7\WpUtils\Classes\WP;


/**
 * EventTypeManager
 * */
final class EventTypeManager {

	/** Constructor */
	public function __construct( private EventType $event_type ) {
	}


	/**
	 * 依照事件類型不同的轉換不同的訂單狀態
	 * 付款成功  => 處理中
	 * 付款失敗  => 等待付款中
	 * 逾時未付  => 取消
	 * 退款成功 => 退款
	 *
	 * @param Body $webhook_dto Webhook
	 *
	 * @return void
	 * @ref https://docs.shoplinepayments.com/api/event/model/
	 * 只需處理 Session 的 webhook，因為只有 Session 有帶 referenceId (對應 order_id)
	 */
	public function update_order_status( Body $webhook_dto ): void {
		$event_type = $webhook_dto->get_event_type();
		if (!$event_type->is_trade_event_type()) {
			return;
		}

		$order = $webhook_dto->get_order();
		if ( !$order ) {
			return;
		}

		// 更新狀態 & 填寫備註
		/** @var Session $data  */
		$data = $webhook_dto->data;
		if ( $data->paymentDetails && is_array( $data->paymentDetails ) ) {
			foreach ( $data->paymentDetails as $payment_detail ) {
				$payment_detail_html = WP::array_to_html($payment_detail->to_array(), [ 'title' => $this->event_type->label() ]);
				$order->add_order_note($payment_detail_html);
			}
		} else {
			$order->add_order_note($this->event_type->label());
		}

		$order_status = match ( $event_type ) {
			EventType::SESSION_SUCCEEDED => OrderStatus::PROCESSING,
			EventType::SESSION_EXPIRED => OrderStatus::CANCELLED,
			// EventType::SESSION_PENDING,
			// EventType::SESSION_CREATED,
			default => OrderStatus::PENDING,
		};

		$order->update_status($order_status->value);
	}



	/**
	 * 依照依照事件類型的 DTO
	 *
	 * @param array<string, mixed> $data 原始資料
	 * @return DTO 事件類型的 DTO
	 */
	public function get_dto( array $data ): DTO {
		return match ( $this->event_type ) {
			EventType::SESSION_CREATED,
			EventType::SESSION_EXPIRED,
			EventType::SESSION_PENDING,
			EventType::SESSION_SUCCEEDED => Webhooks\Session::create($data),
			EventType::TRADE_SUCCEEDED,
			EventType::TRADE_FAILED,
			EventType::TRADE_EXPIRED,
			EventType::TRADE_PROCESSING,
			EventType::TRADE_CANCELLED,
			EventType::TRADE_CUSTOMER_ACTION => Webhooks\Payment::create($data),
			EventType::TRADE_REFUND_SUCCEEDED,
			EventType::TRADE_REFUND_FAILED => Webhooks\Refund::create($data),
			EventType::CUSTOMER_CREATED,
			EventType::CUSTOMER_UPDATED,
			EventType::CUSTOMER_DELETED => Webhooks\Member::parse($data),
			EventType::CUSTOMER_INSTRUMENT_BINDED,
			EventType::CUSTOMER_INSTRUMENT_UPDATED,
			EventType::CUSTOMER_INSTRUMENT_UNBINDED => Webhooks\Instrument::create($data),
		};
	}
}
