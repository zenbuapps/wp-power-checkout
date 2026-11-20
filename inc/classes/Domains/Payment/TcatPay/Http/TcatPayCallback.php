<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Http;

use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/**
 * 黑貓PAY APN 主動通知端點：POST /wp-json/power-checkout/tcat-pay/apn
 *
 * - 無條件註冊（同 PayNow 理由：server-to-server 通知端點須恆常可達；gateway 停用期間的既有訂單仍要能認列）。
 * - 流程全交給統一外框 `TcatPayGateway::handle_webhook()`（WebhookPipeline）：
 *   checksum → 找單 → 平台查詢（source of truth）→ StatusManager → ack。
 * - 平台只認**純文字** `OK` 停止重送：WP REST 預設會把字串 JSON 序列化成 `"OK"`，
 *   因此以 rest_pre_echo_response 對本路由直接輸出原始字串（同 AioCallback 的 1|OK 手法）。
 */
final class TcatPayCallback extends ApiBase {
	use SingletonTrait;

	/** @var string Namespace */
	protected $namespace = 'power-checkout/tcat-pay';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'apn',
			'method'              => 'post',
			'permission_callback' => '__return_true',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
		\add_filter( 'rest_pre_echo_response', [ __CLASS__, 'echo_plain_text' ], 10, 3 );
	}

	/**
	 * APN 回呼（永遠由外框決定 ack：OK / RETRY）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_apn_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return self::resolve_gateway()->handle_webhook( $request );
	}

	/**
	 * 本路由的字串回應以純文字輸出（不 JSON 序列化）
	 *
	 * @param mixed            $result  回應資料
	 * @param \WP_REST_Server  $server  server
	 * @param \WP_REST_Request $request 請求
	 * @return mixed null 表示已自行輸出
	 */
	public static function echo_plain_text( mixed $result, \WP_REST_Server $server, \WP_REST_Request $request ): mixed {
		if ( ! \is_string( $result ) || ! \str_starts_with( (string) $request->get_route(), '/power-checkout/tcat-pay/' ) ) {
			return $result;
		}
		echo \esc_html( $result );
		return null;
	}

	/** @return string APN 網址（帶入 CvsOrderAppend / CocsOrderAppend 的 apn_url） */
	public static function get_apn_url(): string {
		return \rest_url( 'power-checkout/tcat-pay/apn' );
	}

	/** @return TcatPayGateway WC 已實例化的 gateway；尚未初始化時新建 */
	private static function resolve_gateway(): TcatPayGateway {
		$gateways = \function_exists( 'WC' ) ? \WC()->payment_gateways()->payment_gateways() : [];
		$gateway  = $gateways[ TcatPayGateway::ID ] ?? null;
		return $gateway instanceof TcatPayGateway ? $gateway : new TcatPayGateway();
	}
}
