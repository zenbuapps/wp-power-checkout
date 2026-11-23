<?php
/**
 * 物流「測試連線」REST：POST power-checkout/v1/logistics/{provider}/ping
 *
 * - 權限：ApiBase 預設（manage_options / manage_woocommerce），前端帶 X-WP-Nonce
 * - 一律註冊（不需任何物流已啟用）：設定頁儲存憑證後、啟用前即可測試
 * - provider 依 Logistics\ProviderRegister 註冊表實例化（不經已啟用容器）
 * - 回應：成功 200 {code: success, data}；未知 provider 404；未實作 ISupportsConnectionTest 501 UNSUPPORTED；
 *   失敗帶 error_code / raw_code（VALIDATION 422，其餘 424 Failed Dependency），訊息為 provider 原始錯誤（admin 才看得到，不含憑證）
 * - 失敗不回 5xx：站點常掛在 Cloudflare 等 CDN / 反向代理後，origin 的 502 本文會被改寫成 CDN 錯誤頁，
 *   設定頁就看不到「帳號或密碼錯誤」等診斷訊息（P8 live 驗收實測）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Services;

use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ISupportsConnectionTest;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** 物流測試連線 Api Service */
final class LogisticsPingApiService extends ApiBase {
	use SingletonTrait;

	/** @var int provider 端失敗（AUTH / NETWORK / PROVIDER…）的 HTTP 狀態：424 Failed Dependency（不用 5xx，避免 CDN 改寫本文） */
	public const PROVIDER_FAILURE_STATUS = 424;

	/** @var string REST API namespace */
	protected $namespace = 'power-checkout/v1';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint' => 'logistics/(?P<provider>[a-z0-9_]+)/ping',
			'method'   => 'post',
			'callback' => [ self::class, 'post_ping_callback' ],
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	/**
	 * 測試連線
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public static function post_ping_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_id = (string) ( $request->get_url_params()['provider'] ?? '' );
		$class_name  = ProviderRegister::get_provider_class( $provider_id );
		if (null === $class_name) {
			return self::error( \__( '找不到此物流服務', 'power_checkout' ), 404, ErrorCode::NOT_FOUND );
		}

		/** @var callable $factory */
		$factory  = [ $class_name, 'instance' ];
		$provider = \call_user_func( $factory );
		if (!$provider instanceof ISupportsConnectionTest) {
			return self::error( \__( '此物流服務尚未提供測試連線', 'power_checkout' ), 501, ErrorCode::UNSUPPORTED );
		}

		try {
			return new \WP_REST_Response(
				[
					'code'    => 'success',
					'message' => \__( '連線成功', 'power_checkout' ),
					'data'    => $provider->test_connection(),
				],
				200
			);
		} catch ( \Throwable $e ) {
			$error_code = property_exists( $e, 'error_code' ) && $e->error_code instanceof ErrorCode ? $e->error_code : ErrorCode::UNKNOWN;
			$raw_code   = property_exists( $e, 'raw_code' ) && \is_string( $e->raw_code ) ? $e->raw_code : null;
			Plugin::logger(
				'物流測試連線失敗',
				'warning',
				[
					'provider'   => $provider_id,
					'error_code' => $error_code->value,
					'raw_code'   => $raw_code,
				]
			);
			return self::error( $e->getMessage(), ErrorCode::VALIDATION === $error_code ? 422 : self::PROVIDER_FAILURE_STATUS, $error_code, $raw_code );
		}
	}

	/**
	 * 錯誤回應
	 *
	 * @param string      $message    訊息
	 * @param int         $status     HTTP 狀態碼
	 * @param ErrorCode   $error_code 正規化錯誤碼
	 * @param string|null $raw_code   provider 原始錯誤碼
	 * @return \WP_REST_Response
	 */
	private static function error( string $message, int $status, ErrorCode $error_code, ?string $raw_code = null ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'       => 'error',
				'error_code' => $error_code->value,
				'raw_code'   => $raw_code,
				'message'    => $message,
				'data'       => null,
			],
			$status
		);
	}
}
