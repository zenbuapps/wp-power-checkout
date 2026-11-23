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
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\CreateSdkPaymentDTO;
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
		if (!$this->gateway->order) {
			$this->gateway->order = $this->order;
		}
	}

	/**
	 * 建立結帳交易
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/session/
	 * @return SessionDTO
	 * @throws \Exception 如果交易建立失敗
	 *  */
	public function create_session(): SessionDTO {
		$return_url    = $this->gateway->get_return_url( $this->order );
		$request_body  = CreateSessionDTO::create( $this->order, $return_url )->to_array();
		$response_body = $this->requester->post( '/trade/sessions/create', $request_body );
		return SessionDTO::create( $response_body );
	}

	/**
	 * 查詢結帳交易
	 *
	 * 目前無呼叫端。`MetaKeys::update_identity()` 已於結帳時把 sessionId 落盤，
	 * 本方法保留供未來「主動查詢 session 補單」路徑使用——那是
	 * 「客戶付款後從未導回、且沒有 tradeOrderId」訂單的唯一人工救援路徑
	 * （見 issue #18 第六階段，本次未實作）。請勿誤刪。
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
	 * @param string   $trade_order_id SLP 付款交易訂單編號，由呼叫端負責清理
	 * @param int|null $timeout        逾時秒數，null 使用 Requester 預設值。
	 *                                 前台導回同步查詢請傳入短逾時，避免阻塞 thankyou 頁。
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/query/
	 * @return PaymentDTO 結帳交易查詢結果
	 * @throws \Exception 如果結帳交易查詢失敗
	 *  */
	public function get_payment( string $trade_order_id, ?int $timeout = null ): PaymentDTO {
		$request_body = GetPaymentDTO::create( $trade_order_id )->to_array();

		$response_body = $this->requester->post(
			'/trade/payment/get',
			$request_body,
			$timeout
		);
		return PaymentDTO::create( $response_body );
	}


	/**
	 * 建立付款交易（內嵌式 SDK）
	 *
	 * 官方明示 nextAction「不要解析、資料類型隨時可變」（R6）：以原始回應 body 重新 decode 為物件後
	 * 只取 nextAction 節點 encode 回 JSON 字串轉傳前端，不經 assoc array（避免空物件 {} 變成 []）。
	 * 回應沒有 nextAction 代表建單失敗，不可呼叫 SDK pay()，由呼叫端判斷。
	 *
	 * @param CreateSdkPaymentDTO $dto 建立付款交易參數
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/payment/
	 * @return array{trade_order_id: string, status: string, next_action: string|null, payment_msg: string}
	 * @throws \Exception 請求失敗（連線錯誤或 SLP 回錯誤碼）
	 *  */
	public function create_sdk_payment( CreateSdkPaymentDTO $dto ): array {
		$response_body = $this->requester->post( '/trade/payment/create', $dto->to_array() );

		$next_action = null;
		$raw         = \json_decode( $this->requester->last_response_body, false );
		if ( $raw instanceof \stdClass && isset( $raw->nextAction ) && '' !== $raw->nextAction ) {
			$encoded     = \wp_json_encode( $raw->nextAction );
			$next_action = \is_string( $encoded ) ? $encoded : null;
		}

		$payment_msg = $response_body['paymentMsg'] ?? null;

		return [
			'trade_order_id' => \is_scalar( $response_body['tradeOrderId'] ?? null ) ? (string) $response_body['tradeOrderId'] : '',
			'status'         => \is_scalar( $response_body['status'] ?? null ) ? (string) $response_body['status'] : '',
			'next_action'    => $next_action,
			'payment_msg'    => \is_array( $payment_msg ) ? \implode( ' ', \array_map( 'strval', \array_filter( $payment_msg, 'is_scalar' ) ) ) : '',
		];
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
