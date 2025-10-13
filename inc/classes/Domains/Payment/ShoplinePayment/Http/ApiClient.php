<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http;

use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Refund\CreateRefundDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Refund\RefundDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Session\CreateSessionDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Session\QuerySessionDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Session\SessionDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Helpers\Requester;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\GetPaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO;

/**
 * Shopline Payment 跳轉式支付服務類 工廠模式
 * 方法
 * 1. 建立交易
 *
 * @see https://docs.shoplinepayments.com/guide/session/
 *  */
final class ApiClient {

	/** @var Requester 請求器 */
	private Requester $requester;

	/** Constructor */
	public function __construct(
		/** @var AbstractPaymentGateway 付款閘道 */
		private readonly AbstractPaymentGateway $gateway,
		/** @var \WC_Order 訂單 */
		private readonly \WC_Order $order
	) {
		$this->requester = new Requester( $this->gateway, $this->order );
	}

	/**
	 * 建立結帳交易
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/session/
	 * @return SessionDTO
	 * @throws \Exception 如果交易建立失敗
	 *  */
	public function create_session(): SessionDTO {
        $return_url = $this->gateway->get_return_url( $this->order );
		$request_body  = CreateSessionDTO::create( $this->order, $return_url )->to_array();
		$response_body = $this->requester->post( '/trade/sessions/create', $request_body );
		return SessionDTO::create( $response_body );
	}

	/**
	 * 查詢結帳交易
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/sessionQuery/
	 * @return SessionDTO 結帳交易查詢結果
	 * @throws \Exception 如果結帳交易查詢失敗
	 *  */
	public function get_session(): SessionDTO {
		$request_body = QuerySessionDTO::create( $this->order )->to_array();

		$response_body = $this->requester->post(
			'/trade/sessions/query',
			$request_body
			);
		return SessionDTO::create( $response_body );
	}


	/**
	 * 查詢付款交易
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/query/
	 * @return PaymentDTO 結帳交易查詢結果
	 * @throws \Exception 如果結帳交易查詢失敗
	 *  */
	public function get_payment(): PaymentDTO {
		$request_body = GetPaymentDTO::create()->to_array();

		$response_body = $this->requester->post(
			'/trade/payment/get',
			$request_body
		);
		return PaymentDTO::create( $response_body );
	}


	/**
	 * 建立退款交易
	 *
	 * @param float  $amount 退款金額
	 * @param string $reason 退款原因
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/refund/
	 * @return RefundDTO  shopline payment return 的 session url
	 * @throws \Exception 如果交易建立失敗
	 *  */
	public function create_refund( float $amount, string $reason ): RefundDTO {
		$request_body  = CreateRefundDTO::create( $this->order, $amount, $reason )->to_array();
		$response_body = $this->requester->post( '/trade/refund/create', $request_body );
		return RefundDTO::create( $response_body );
	}
}
