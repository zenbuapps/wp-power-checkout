<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http;

use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Params;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Session\RequestParamsCreate;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Session\RequestParamsQuery;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Session\ResponseParams as SessionResponseParams;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Helpers\Requester;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\RequestParamsGet;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\ResponseParams as PaymentResponseParams;

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
	 * @return SessionResponseParams  shopline payment return 的 session url
	 * @throws \Exception 如果交易建立失敗
	 *  */
	public function create_session(): SessionResponseParams {
		$request_body  = RequestParamsCreate::create( $this->gateway, $this->order )->to_array();
		$response_body = $this->requester->post( '/trade/sessions/create', $request_body );
		return SessionResponseParams::create( $response_body );
	}

	/**
	 * 查詢結帳交易
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/sessionQuery/
	 * @return SessionResponseParams 結帳交易查詢結果
	 * @throws \Exception 如果結帳交易查詢失敗
	 *  */
	public function get_session(): SessionResponseParams {
		$request_body = RequestParamsQuery::create( $this->order )->to_array();

		$response_body = $this->requester->post(
			'/trade/sessions/query',
			$request_body
			);
		return SessionResponseParams::create( $response_body );
	}


	/**
	 * 查詢付款交易
	 * TODO 避免重複發送
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/query/
	 * @return PaymentResponseParams 結帳交易查詢結果
	 * @throws \Exception 如果結帳交易查詢失敗
	 *  */
	public function get_payment(): PaymentResponseParams {
		$request_body = RequestParamsGet::create()->to_array();

		$response_body = $this->requester->post(
			'/trade/payment/get',
			$request_body
		);
		return PaymentResponseParams::create( $response_body );
	}
}
