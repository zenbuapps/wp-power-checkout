<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Services;

use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IEmbeddedPaymentProvider;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/**
 * 嵌入式（站內付）金流統一 dispatch REST
 *
 * POST power-checkout/v1/pay/{order_id}/dispatch
 *  - permission_callback: __return_true（顧客端呼叫）；body order_key 以 hash_equals 驗（防 IDOR）
 *  - 驗證順序：訂單存在（404）→ order_key（403）→ gateway 為 IEmbeddedPaymentProvider（400 UNSUPPORTED）
 *    → needs_payment（409）→ gateway::dispatch()，回傳值原樣放進 data
 *  - dispatch 拋例外 → 500 通用訊息（細節只進 log，不外洩）
 *
 * 回應 envelope 對齊 PaymentApiService：失敗帶 code / error_code / message / data。
 */
final class PaymentDispatchApiService extends ApiBase {
	use SingletonTrait;

	/** @var string REST API namespace */
	protected $namespace = 'power-checkout/v1';

	/** @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表 */
	protected $apis = [
		[
			'endpoint'            => 'pay/(?P<order_id>\d+)/dispatch',
			'method'              => 'post',
			'permission_callback' => '__return_true',
			'callback'            => [ __CLASS__, 'post_pay_with_order_id_dispatch_callback' ],
		],
	];

	/** @var array<int, string> 不轉交給 gateway 的參數 */
	private const RESERVED_PARAMS = [ 'order_id', 'order_key' ];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	/**
	 * Dispatch callback
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public static function post_pay_with_order_id_dispatch_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order = \wc_get_order( (int) $request->get_param( 'order_id' ) );
		if ( ! $order instanceof \WC_Order ) {
			return self::error_response( ErrorCode::NOT_FOUND, \__( '找不到訂單', 'power_checkout' ), 404 );
		}

		$order_key = $request->get_param( 'order_key' );
		if ( ! \is_string( $order_key ) || '' === $order_key || ! \hash_equals( $order->get_order_key(), $order_key ) ) {
			return self::error_response( ErrorCode::AUTH, \__( '訂單驗證失敗', 'power_checkout' ), 403 );
		}

		$gateway = self::resolve_gateway( $order );
		if ( null === $gateway ) {
			return self::error_response( ErrorCode::UNSUPPORTED, \__( '此訂單的付款方式不支援站內付', 'power_checkout' ), 400 );
		}

		if ( ! $order->needs_payment() ) {
			return self::error_response( ErrorCode::CONFLICT, \__( '訂單已付款或無需付款', 'power_checkout' ), 409 );
		}

		return self::dispatch( $gateway, $order, $request );
	}

	/**
	 * 呼叫 gateway dispatch，例外轉 500 通用訊息
	 *
	 * @param IEmbeddedPaymentProvider $gateway 嵌入式 gateway
	 * @param \WC_Order                $order   訂單
	 * @param \WP_REST_Request         $request 請求
	 * @return \WP_REST_Response
	 */
	private static function dispatch( IEmbeddedPaymentProvider $gateway, \WC_Order $order, \WP_REST_Request $request ): \WP_REST_Response {
		$payload = \array_diff_key( $request->get_params(), \array_flip( self::RESERVED_PARAMS ) );

		try {
			return new \WP_REST_Response(
				[
					'code'    => 'success',
					'message' => \__( '成功', 'power_checkout' ),
					'data'    => $gateway->dispatch( $order, $payload ),
				],
				200
			);
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'嵌入式金流 dispatch 失敗',
				'error',
				[
					'order_id' => $order->get_id(),
					'gateway'  => $order->get_payment_method(),
					'error'    => $e->getMessage(),
				]
			);
			return self::error_response( ErrorCode::UNKNOWN, \__( '付款處理失敗，請稍後再試或聯繫商家', 'power_checkout' ), 500 );
		}
	}

	/**
	 * 取得訂單 gateway（須為 IEmbeddedPaymentProvider）
	 *
	 * 先找 ProviderUtils 容器，再找 WC 已註冊 gateway。
	 *
	 * @param \WC_Order $order 訂單
	 * @return IEmbeddedPaymentProvider|null
	 */
	private static function resolve_gateway( \WC_Order $order ): ?IEmbeddedPaymentProvider {
		$id      = $order->get_payment_method();
		$gateway = ProviderUtils::get_provider( $id ) ?? ( \WC()->payment_gateways()->payment_gateways()[ $id ] ?? null );

		return $gateway instanceof IEmbeddedPaymentProvider ? $gateway : null;
	}

	/**
	 * 錯誤回應
	 *
	 * @param ErrorCode $code    正規化錯誤碼
	 * @param string    $message 對外訊息
	 * @param int       $status  HTTP 狀態碼
	 * @return \WP_REST_Response
	 */
	private static function error_response( ErrorCode $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'       => $code->value,
				'error_code' => $code->value,
				'message'    => $message,
				'data'       => null,
			],
			$status
		);
	}
}
