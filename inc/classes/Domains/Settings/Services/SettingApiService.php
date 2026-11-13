<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\Services;

use J7\PowerCheckout\Domains\Checkout\DTOs\CheckoutEnhancementSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister as LogisticsProviderRegister;
use J7\PowerCheckout\Domains\Receipt\ProviderRegister as ReceiptProviderRegister;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Classes\WP;
use J7\WpUtils\Traits\SingletonTrait;

/**
 * 設定相關的 REST API
 *
 * GET /wp-json/power-checkout/v1/settings 取得目前的設定
 */
final class SettingApiService extends ApiBase {
	use SingletonTrait;

	/** @var string REST API namespace */
	protected $namespace = 'power-checkout/v1';

	/** @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> 已註冊的 API 列表 */
	protected $apis = [
		[
			'endpoint' => 'settings',
			'method'   => 'get',
		],
		[
			'endpoint' => 'settings/(?P<provider_id>[a-zA-Z_-]+)/toggle',
			'method'   => 'post',
			'callback' => [ __CLASS__, 'toggle_providers_with_id_callback' ],
		],
		[
			'endpoint' => 'settings/(?P<provider_id>[a-zA-Z_-]+)',
			'method'   => 'get',
			'callback' => [ __CLASS__, 'get_providers_with_id_callback' ],
		],
		[
			'endpoint' => 'settings/(?P<provider_id>[a-zA-Z_-]+)',
			'method'   => 'post',
			'callback' => [ __CLASS__, 'post_providers_with_id_callback' ],
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	// region settings 相關

	/**
	 * 取得設定
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_settings_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$gateways = GatewayUtils::get_gateways(false, true);

		return new \WP_REST_Response(
			[
				'code'    => 'get_settings_success',
				'message' => '取得設定成功',
				'data'    => [
					'gateways'  => $gateways,
					'invoices'  => ProviderRegister::get_registered_provider_dtos(),
					'receipts'  => ReceiptProviderRegister::get_registered_provider_dtos(),
					'logistics' => LogisticsProviderRegister::get_registered_provider_dtos(),
				],
			],
			200
		);
	}


	/**
	 * 開關服務
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 * @throws \Exception 如果 gateway_key 無效或其他錯誤
	 */
	public static function toggle_providers_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$provider_id = self::route_provider_id( $request );
		ProviderUtils::toggle( $provider_id);
		$toggle_text = ProviderUtils::is_enabled( $provider_id) ? '啟用' : '禁用';

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "{$provider_id} {$toggle_text}成功",
				'data'    => $provider_id,
			],
			200
		);
	}

	/**
	 * 取得設定
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_providers_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		if (self::is_checkout_enhancement_route( $request )) {
			return self::get_checkout_enhancement_settings();
		}

		$provider_id = self::route_provider_id( $request );
		$provider    = ProviderUtils::get_provider( $provider_id);

		// 已啟用的 provider 才會進入 ProviderUtils::$container（發票 / 物流 provider 停用時不實例化）。
		// 停用狀態仍是合法可查詢狀態（/settings 列表一律列出該 provider），
		// 因此找不到實例時不可拋例外（會回 500），改回傳 wp_options 內已持久化的設定，
		// 與停用後仍可在設定頁讀取既有設定的預期一致。
		if (!$provider) {
			/** @var array<string, mixed> $stored_settings */
			$stored_settings = ProviderUtils::get_option( $provider_id);
			return new \WP_REST_Response(
				[
					'code'    => 'success',
					'message' => "取得 {$provider_id} 設定成功（provider 未啟用，回傳已儲存設定）",
					'data'    => $stored_settings,
				],
				200
			);
		}

		/** @var callable $get_settings */
		$get_settings = [ $provider, 'get_settings' ];
		/** @var array<string, mixed> $settings */
		$settings = \call_user_func($get_settings);

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "取得 {$provider->method_title} 設定成功",
				'data'    => $settings,
			],
			200
		);
	}

	/**
	 * 更新設定
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public static function post_providers_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		if (self::is_checkout_enhancement_route( $request )) {
			return self::post_checkout_enhancement_settings( $request );
		}

		$provider_id = self::route_provider_id( $request );

		$params = $request->get_params();
		unset( $params['provider_id'] ); // 路由參數不是設定值，不寫入 option
		$params = WP::sanitize_text_field_deep($params, false );

		ProviderUtils::update_option( $provider_id, $params);

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => '儲存成功',
				'data'    => ProviderUtils::get_option( $provider_id),
			],
			200
		);
	}

	// endregion settings 相關

	// region 結帳體驗強化（虛擬 provider checkout_enhancement）

	/**
	 * 是否為結帳體驗強化設定路由
	 *
	 * 只看路由 URL 參數，不可用 $request['provider_id']：
	 * WP 的參數優先序是 JSON 本文 > URL 參數，本文夾帶 provider_id 會改寫路由目標。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private static function is_checkout_enhancement_route( \WP_REST_Request $request ): bool {
		return CheckoutEnhancementSettingsDTO::PROVIDER_ID === self::route_provider_id( $request );
	}

	/**
	 * 取得路由上的 provider_id（只讀 URL 參數）
	 *
	 * 不可用 $request['provider_id']：WP 的參數優先序是 JSON 本文 > POST > 查詢字串 > URL 參數，
	 * 本文或查詢字串夾帶 provider_id 會把請求改寫到別的 `woocommerce_{x}_settings`。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return string
	 */
	private static function route_provider_id( \WP_REST_Request $request ): string {
		$url_params = $request->get_url_params();
		$value      = $url_params['provider_id'] ?? '';
		return \is_string( $value ) ? $value : '';
	}

	/**
	 * 取得結帳體驗強化設定（未儲存的鍵以預設值補齊；option 損毀時回傳預設值）
	 *
	 * @return \WP_REST_Response
	 */
	private static function get_checkout_enhancement_settings(): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => '取得結帳體驗強化設定成功',
				'data'    => CheckoutEnhancementSettingsDTO::get_all(),
			],
			200
		);
	}

	/**
	 * 儲存結帳體驗強化設定
	 *
	 * 逐鍵驗證：不合法的鍵忽略並保留原值，其餘合法鍵照存；未知鍵與 provider_id 不寫入。
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	private static function post_checkout_enhancement_settings( \WP_REST_Request $request ): \WP_REST_Response {
		/** @var array<mixed> $params */
		$params = WP::sanitize_text_field_deep( $request->get_params(), false );
		$merged = CheckoutEnhancementSettingsDTO::merge_valid( $params, CheckoutEnhancementSettingsDTO::get_stored() );

		/** @var array<string, string> $merged */
		$merged = StrHelper::trim_invisible_deep( $merged );
		\update_option( CheckoutEnhancementSettingsDTO::get_option_name(), $merged );

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => '儲存成功',
				'data'    => CheckoutEnhancementSettingsDTO::get_all(),
			],
			200
		);
	}

	// endregion 結帳體驗強化
}
