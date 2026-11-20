<?php

declare( strict_types=1 );

namespace Tests\Integration\Payment\Doubles;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/**
 * SLP 嵌入式（shopline_payment_embedded）測試共用 helper
 *
 * - HTTP：pre_http_request 攔截，依 URL 片段回假回應並記錄請求；未設定的 URL 一律回 WP_Error（測試不連外）
 * - 設定：嵌入式共用導轉式（RedirectSettingsDTO）商店憑證，嵌入式自身只存顯示欄位
 *
 * 使用端須在 tear_down 呼叫 reset_slp_embedded_http()。
 */
trait SlpEmbeddedTestTrait {

	/** @var string 測試用 signKey（存於導轉式設定，嵌入式共用） */
	protected static string $slp_sign_key = 'test_sign_key_for_slp_embedded';

	/** @var array<string, array<string, mixed>|\WP_Error> URL 片段 => 假回應 */
	protected array $slp_fake_responses = [];

	/** @var array<int, array{url: string, body: string, headers: array<string, mixed>}> 已攔截的請求 */
	protected array $slp_requests = [];

	/** @var bool 攔截器是否已掛上 */
	private bool $slp_http_hooked = false;

	/**
	 * 啟用嵌入式（導轉式可選擇停用）並寫入導轉式商店憑證設定
	 *
	 * @param bool                 $redirect_enabled 導轉式是否啟用
	 * @param array<string, mixed> $redirect_extra   導轉式額外設定（例：mode=prod + 空憑證）
	 * @param array<string, mixed> $embedded_extra   嵌入式額外設定
	 * @return void
	 */
	final protected function setup_slp_embedded(
		bool $redirect_enabled = false,
		array $redirect_extra = [],
		array $embedded_extra = []
	): void {
		\update_option( 'woocommerce_currency', 'TWD' );
		ProviderUtils::update_option(
			RedirectGateway::ID,
			\array_merge(
				[
					'enabled' => $redirect_enabled ? 'yes' : 'no',
					'mode'    => 'test',
					'signKey' => self::$slp_sign_key,
				],
				$redirect_extra
			)
		);
		ProviderUtils::update_option(
			EmbeddedGateway::ID,
			\array_merge( [ 'enabled' => 'yes' ], $embedded_extra )
		);
	}

	/**
	 * 掛 HTTP 攔截：URL 含 $fragment 時回 $response
	 *
	 * @param string                         $fragment URL 片段（例：/trade/payment/create）
	 * @param array<string, mixed>|\WP_Error $response 假回應
	 * @return void
	 */
	final protected function fake_slp_http( string $fragment, array|\WP_Error $response ): void {
		$this->slp_fake_responses[ $fragment ] = $response;
		$this->hook_slp_http();
	}

	/** 只掛攔截器（全部請求擋下並記錄），用於斷言「不打 API」 @return void */
	final protected function block_slp_http(): void {
		$this->hook_slp_http();
	}

	/** @return void 掛上攔截器（冪等） */
	private function hook_slp_http(): void {
		if ( $this->slp_http_hooked ) {
			return;
		}
		\add_filter( 'pre_http_request', [ $this, 'intercept_slp_http' ], 10, 3 );
		$this->slp_http_hooked = true;
	}

	/** @return void 移除攔截器並清空紀錄 */
	final protected function reset_slp_embedded_http(): void {
		\remove_filter( 'pre_http_request', [ $this, 'intercept_slp_http' ], 10 );
		$this->slp_http_hooked    = false;
		$this->slp_fake_responses = [];
		$this->slp_requests       = [];
	}

	/**
	 * pre_http_request 攔截器
	 *
	 * @param false|array<string, mixed>|\WP_Error $preempt     既有 preempt
	 * @param array<string, mixed>                 $parsed_args 請求參數
	 * @param string                               $url         URL
	 * @return array<string, mixed>|\WP_Error
	 */
	final public function intercept_slp_http( $preempt, array $parsed_args, string $url ) {
		$this->slp_requests[] = [
			'url'     => $url,
			'body'    => \is_string( $parsed_args['body'] ?? null ) ? $parsed_args['body'] : '',
			'headers' => \is_array( $parsed_args['headers'] ?? null ) ? $parsed_args['headers'] : [],
		];
		foreach ( $this->slp_fake_responses as $fragment => $response ) {
			if ( \str_contains( $url, $fragment ) ) {
				return $response;
			}
		}
		return new \WP_Error( 'http_blocked_in_test', "測試中禁止外部請求：{$url}" );
	}

	/**
	 * 只計算打到 SLP API 的請求（排除 wp-cron 等）
	 *
	 * @return array<int, array{url: string, body: string, headers: array<string, mixed>}>
	 */
	final protected function slp_api_requests(): array {
		return \array_values(
			\array_filter(
				$this->slp_requests,
				static fn( array $req ): bool => \str_contains( $req['url'], 'shoplinepayments.com' )
			)
		);
	}

	/**
	 * HTTP 200 + JSON body 假回應（body 為原始字串，可測 raw pass-through）
	 *
	 * @param string $json 回應 body
	 * @return array<string, mixed>
	 */
	final protected function slp_raw_json_response( string $json ): array {
		return [
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => $json,
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];
	}

	/**
	 * HTTP 200 + JSON body 假回應
	 *
	 * @param array<string, mixed> $body 回應 body
	 * @return array<string, mixed>
	 */
	final protected function slp_json_response( array $body ): array {
		return $this->slp_raw_json_response( (string) \wp_json_encode( $body ) );
	}

	/**
	 * 建立嵌入式訂單
	 *
	 * @param float       $total          訂單金額
	 * @param string      $status         訂單狀態
	 * @param string|null $trade_order_id 寫入 _pc_payment_identity 的值，null 不寫
	 * @param string      $payment_method gateway id
	 * @return \WC_Order
	 */
	final protected function make_slp_embedded_order(
		float $total = 1000.0,
		string $status = 'pending',
		?string $trade_order_id = null,
		string $payment_method = EmbeddedGateway::ID
	): \WC_Order {
		$order = \wc_create_order();
		$order->set_payment_method( $payment_method );
		$order->set_currency( 'TWD' );
		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
		$order->set_billing_email( 'buyer@example.com' );
		$order->set_billing_phone( '0912345678' );
		$order->set_billing_country( 'TW' );
		$order->set_total( (string) $total );
		$order->save();
		$order->update_status( $status );
		$order->save();

		if ( null !== $trade_order_id ) {
			( new MetaKeys( $order ) )->update_payment_identity( $trade_order_id );
		}

		return $order;
	}

	/**
	 * SLP 付款交易物件（trade/payment/get 回應 / trade.* webhook 的 data）
	 *
	 * @param string $trade_order_id     tradeOrderId
	 * @param string $reference_order_id referenceOrderId
	 * @param string $status             付款狀態
	 * @param int    $amount_cents       金額（cents）
	 * @return array<string, mixed>
	 */
	final protected function slp_payment_payload( string $trade_order_id, string $reference_order_id, string $status = 'SUCCEEDED', int $amount_cents = 100000 ): array {
		return [
			'referenceOrderId' => $reference_order_id,
			'tradeOrderId'     => $trade_order_id,
			'status'           => $status,
			'order'            => [
				'merchantId'       => 'MERCHANT_TEST',
				'referenceOrderId' => $reference_order_id,
				'createTime'       => \time(),
				'amount'           => [
					'value'    => $amount_cents,
					'currency' => 'TWD',
				],
				'customer'         => [
					'referenceCustomerId' => 'CUSTOMER_EMB_001',
					'customerId'          => 'SLP_CUSTOMER_EMB_001',
				],
			],
			'payment'          => [
				'paymentMethod'   => 'CreditCard',
				'paymentBehavior' => 'Regular',
				'paidAmount'      => [
					'value'    => $amount_cents,
					'currency' => 'TWD',
				],
			],
		];
	}
}
