<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services;

use J7\PowerCheckout\Domains\Payment\Contracts\IGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Domains\Payment\Shared\Params;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\ResponseParams;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Abstracts\PaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\ApiClient;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseStatus;
use J7\WpUtils\Classes\WP;

/**
 * RedirectGateway 跳轉支付
 * TODO Shopline payment 似乎是跳轉到 Shopline 的頁面才選擇支付方式，與綠界不同  確認後，再改成正確的備註
 * */
final class RedirectGateway extends PaymentGateway implements IGateway {

	/** @var string 付款方式 ID */
	public const ID = 'shopline_payment_redirect';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** Constructor */
	public function __construct() {
		$this->payment_label = \__( 'Shopline Payment (導轉式)', 'power_checkout' );
		parent::__construct();
	}

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

		$expire_date = (int) $expire_date;
		$min_amount  = (float) $min_amount;
		$max_amount  = (float) $max_amount;

		if ( $expire_date < 1 || $expire_date > 60 ) {
			$this->errors[] = __( 'Save failed. ATM payment deadline out of range.', 'power_checkout' );
		}

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
			if (!isset($_GET['tradeOrderId'])) { //phpcs:ignore
				return;
			}
			$response_dto   = ( new ApiClient( $this, $order ) )->get_payment();
			$status_manager = new StatusManager( $response_dto, $order );
			$status_manager->update_order_status();
		} catch (\Throwable $e) {
			$this->logger( "❌ {$this->payment_label} 發生錯誤<br>{$e->getMessage()}", 'error', [], 5 );
		}
	}

	/** [Admin] 在後台 order detail 頁地址下方顯示資訊 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$payment_detail_array = $order->get_meta( Params::PAYMENT_DETAIL_KEY );
		if ( !$payment_detail_array ) {
			return;
		}
		try {
			$html = ResponseParams::create( $payment_detail_array)->to_human_html();
		} catch (\Throwable $e) {
			$html = WP::array_to_html( $payment_detail_array );
		}

		echo $html;
	}
}
