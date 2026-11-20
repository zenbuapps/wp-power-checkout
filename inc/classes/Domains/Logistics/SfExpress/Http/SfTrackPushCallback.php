<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Http;

use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\SfExpressProvider;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/**
 * 順豐運單軌跡推送接收端：`POST /wp-json/power-checkout/sf-express/track-push`
 *
 * - 無條件註冊（不論 provider 是否啟用：站主關掉順豐通常是「不再接新單」，在途包裹的貨態仍要更新）
 * - permission_callback __return_true：身分以 header `sign`（Base64(MD5(key + body + key))）驗證，
 *   驗證在 SfExpressProvider::handle_status_callback → TrackPushManager 內進行；未設金鑰整批拒收
 * - 回應一律 HTTP 200 + 順豐規定格式（含例外），避免重送風暴
 * - 推送需 email 順豐客戶經理聯調開通，並開放順豐推送 IP 白名單（skill tracking.md §4）
 */
final class SfTrackPushCallback extends ApiBase {
	use SingletonTrait;

	/** @var string Namespace */
	protected $namespace = 'power-checkout/sf-express';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint'            => 'track-push',
			'method'              => 'post',
			'permission_callback' => '__return_true',
			'callback'            => [ self::class, 'handle' ],
		],
	];

	/**
	 * Register hooks（SingletonTrait 跨請求 / 測試持久：hooks 被還原後以 has_action 守衛補掛）
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		$instance = self::instance();
		if (false === \has_action( 'rest_api_init', [ $instance, 'register_apis' ] )) {
			\add_action( 'rest_api_init', [ $instance, 'register_apis' ] );
		}
	}

	/** @return string 推送接收網址（給設定頁顯示，聯調時提供給順豐） */
	public static function url(): string {
		return \rest_url( 'power-checkout/sf-express/track-push' );
	}

	/**
	 * 推送接收
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		return SfExpressProvider::instance()->handle_status_callback( $request );
	}
}
