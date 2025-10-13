<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services;

use J7\PowerCheckout\Domains\Payment\Contracts\IGateway;
use J7\PowerCheckout\Domains\Payment\Contracts\IGatewaySettings;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Domains\Payment\Shared\Params;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Refund\RefundDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Abstracts\PaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\ApiClient;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseStatus;
use J7\WpUtils\Classes\DTO;
use J7\WpUtils\Classes\WP;

/**
 * RedirectGateway 跳轉支付
 * */
final class RedirectGateway extends PaymentGateway implements IGateway {

	/** @var string 付款方式 ID */
	public const ID = 'shopline_payment_redirect';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示付款方式標題 */
	public $method_title = 'Shopline Payment (導轉式)';

	/** @var string 後台顯示付款方式描述 */
	public $method_description = '';

	/**
	 * Shopline 跳轉式支付核心支付邏輯
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 * @throws \Exception 如果訂單不存在
	 * @see \WC_Payment_Gateway::process_payment
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		$response_dto = ( new ApiClient( $this, $order ) )->create_session();
		// 取得要跳轉的 url

		if (ResponseStatus::tryFrom( $response_dto->status) === ResponseStatus::EXPIRED) {
			// 訂單過期
			$msg     = '已超過 Shopline Payment 付款期限，請重新下單';
			$sys_msg = "session_id: {$response_dto->sessionId}";
			$order->add_order_note( "{$msg}<br>{$sys_msg}");
			$order->update_status( OrderStatus::CANCELLED->value);
			$order_url = $order->get_view_order_url();
			\wc_add_notice( $msg, 'error' );
			\wp_safe_redirect( $order_url );
			exit;
		}

		return $response_dto->sessionUrl;
	}

	/**
	 * [後台] 自訂欄位驗證邏輯
	 * 可以用 \WC_Admin_Settings::add_error 來替欄位加入錯誤訊息
	 * ATM手續費最低收取金額*+1元」(含)~49,999元(含)
	 * TODO 待處理
	 *
	 * @see https://docs.shoplinepayments.com/api/trade/session/
	 * @see WC_Settings_API::process_admin_options
	 * @return bool was anything saved?
	 */
	public function process_admin_options(): bool {

		// 取得 $_POST 的指定欄位 name
		$expire_date_name = $this->get_field_key( 'expire_date' );
		$min_amount_name  = $this->get_field_key( 'min_amount' );
		$max_amount_name  = $this->get_field_key( 'max_amount' );

		// 解構，不存在就會是 null
		@[
			$expire_date_name => $expire_date,
			$min_amount_name  => $min_amount,
			$max_amount_name  => $max_amount,
		] = $this->get_post_data();

		$min_amount = (float) $min_amount;
		$max_amount = (float) $max_amount;

		if ( $min_amount < 5 ) {
			$this->errors[] = sprintf(
				__( 'Save failed. %s minimum amount out of range.', 'power_checkout' ),
				$this->method_title
			);
		}

		if ( $max_amount > 50000 ) {
			$this->errors[] = sprintf(
				__( 'Save failed. %s maximum amount out of range.', 'power_checkout' ),
				$this->method_title
			);
		}

		if ( $this->errors ) {
			$this->display_errors();
			return false;
		}

		return parent::process_admin_options();
	}


	/**
	 * 過濾預設的表單欄位
	 *
	 * @param array<string, mixed> $fields 表單欄位
	 *
	 * @return array<string, mixed> 過濾後的表單欄位
	 * */
	// public function filter_fields( array $fields ): array {
	// return [];
	// }


	/**
	 * 第三方金流 callback 回來之後，頁面 render 前
	 * 在 /checkout/order-received/{$order_id}/?key=wc_order_{$order_key}
	 * 前執行
	 *
	 * 例如綠界需透過前端網頁導轉(Submit)到綠界付款API網址
	 *
	 * @param \WC_Order $order 訂單
	 */
	protected function before_order_received( \WC_Order $order ): void {
		try {
            $trade_order_id = $_GET['tradeOrderId'] ?? '';//phpcs:ignore
			if (!$trade_order_id) {
				return;
			}
			$order_params = new Params($order);
			// 檢查 payment_identity (tradeOrderId) 是否重複，重複代表發過，就不用再發 API
			$payment_identity = $order_params->get_payment_identity();
			if ($payment_identity === $trade_order_id) {
				return;
			}

			$response_dto   = ( new ApiClient( $this, $order ) )->get_payment();
			$status_manager = new StatusManager( $response_dto, $order );
			$status_manager->update_order_status();

			// 儲存 payment_identity
			$order_params->update_payment_identity( $response_dto->tradeOrderId);
		} catch (\Throwable $e) {
			$this->logger( "❌ {$this->title} 發生錯誤<br>{$e->getMessage()}", 'error', [], 5 );
		}
	}

	/** [Admin] 在後台 order detail 頁地址下方顯示資訊 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$payment_detail_array = ( new Params( $order) )->get_payment_detail();
		if ( !$payment_detail_array ) {
			return;
		}
		try {
			$html = PaymentDTO::create( $payment_detail_array)->to_human_html();
		} catch (\Throwable $e) {
			$html = WP::array_to_html( $payment_detail_array );
		}

		echo $html;
	}

	/** @return IGatewaySettings 取得 gateway 設定 */
	public function get_settings(): DTO {
		return RedirectSettingsDTO::instance();
	}

	// region 退款

	/**
	 * 處理退款
	 * 這不是訂單狀態轉換時觸發，而是 admin 點按部分退款時觸發
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   退款金額
	 * @param string     $reason   退款原因
	 *
	 * @return bool True or false based on success, or a WP_Error object.
	 * @noinspection PhpMissingReturnTypeInspection
	 * @see          WC_Payment_Gateway::process_refund
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = \wc_get_order( $order_id );
		if (!$order instanceof \WC_Order || !$amount) {
			return false;
		}
		$response_dto = ( new ApiClient( $this, $order ) )->create_refund( (float) $amount, $reason );
		return $this->handle_refund_response( $response_dto, $order);
	}

	/**
	 * 處理退款 API 回傳結果
	 *
	 * @param RefundDTO $response_dto 退款回應資料
	 * @param \WC_Order $order        WooCommerce 訂單物件
	 * @return bool|\WP_Error         成功回傳 true，失敗回傳 WP_Error
	 */
	private function handle_refund_response( RefundDTO $response_dto, \WC_Order $order ): bool|\WP_Error {
		$status = ResponseStatus::from( $response_dto->status );
		$title  = "{$status->emoji()} 訂單 #{$order->get_id()} 退款{$status->label()}";
		if (isset($response_dto->refundMsg)) {
			$msg_array = $response_dto->refundMsg->to_human_array();
			$msg       = \reset( $msg_array);
			$title    .= ": {$msg}";
		}

		$html = WP::array_to_html($response_dto->to_human_array(), [ 'title' => $title ] );
		$order->add_order_note( $html );

		if (isset($response_dto->refundMsg)) {
			return new \WP_Error( 'refund_failed', $title, $response_dto->to_array() );
		}

		return $status === ResponseStatus::SUCCEEDED;
	}

	// endregion
}
