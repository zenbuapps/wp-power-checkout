<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\Http;

use J7\PowerCheckout\Domains\Payment\Linepay\Services\LinepayGateway;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/**
 * LINE Pay 導回端點（顧客瀏覽器 GET，非 server-to-server）
 *
 *  - GET /wp-json/power-checkout/linepay/confirm?order_id=&key=  （LINE Pay 補 transactionId / orderId）
 *  - GET /wp-json/power-checkout/linepay/cancel?order_id=&key=
 *
 * - 無條件註冊（gateway 停用期間已發出的付款請求仍要能 confirm）。
 * - 流程全交給統一外框 `LinepayGateway::handle_webhook()`：驗 order_key + transactionId + orderId → confirm → 302。
 * - 參數名刻意不用 `orderId` / `transactionId`（LINE Pay 會自動附加這兩個；URL 已含時只補剩下的）。
 */
final class LinepayReturnController extends ApiBase {
	use SingletonTrait;

	/** @var string Namespace */
	protected $namespace = 'power-checkout/linepay';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'confirm',
			'method'              => 'get',
			'permission_callback' => '__return_true',
		],
		[
			'endpoint'            => 'cancel',
			'method'              => 'get',
			'permission_callback' => '__return_true',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	/**
	 * 導回 confirmUrl（永遠 302）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function get_confirm_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return self::resolve_gateway()->handle_webhook( $request );
	}

	/**
	 * 導回 cancelUrl（永遠 302）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function get_cancel_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return self::resolve_gateway()->handle_webhook( $request );
	}

	/**
	 * 組 confirmUrl（自帶 order_id + order_key，LINE Pay 再補 transactionId / orderId）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	public static function get_confirm_url( \WC_Order $order ): string {
		return self::build_url( 'confirm', $order );
	}

	/**
	 * 組 cancelUrl
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	public static function get_cancel_url( \WC_Order $order ): string {
		return self::build_url( 'cancel', $order );
	}

	/**
	 * 組導回網址
	 *
	 * @param string    $endpoint confirm|cancel
	 * @param \WC_Order $order    訂單
	 * @return string
	 */
	private static function build_url( string $endpoint, \WC_Order $order ): string {
		return \add_query_arg(
			[
				'order_id' => (string) $order->get_id(),
				'key'      => $order->get_order_key(),
			],
			\rest_url( "power-checkout/linepay/{$endpoint}" )
		);
	}

	/** @return LinepayGateway WC 已實例化的 gateway；尚未初始化時新建 */
	private static function resolve_gateway(): LinepayGateway {
		$gateways = \function_exists( 'WC' ) ? \WC()->payment_gateways()->payment_gateways() : [];
		$gateway  = $gateways[ LinepayGateway::ID ] ?? null;
		return $gateway instanceof LinepayGateway ? $gateway : new LinepayGateway();
	}
}
