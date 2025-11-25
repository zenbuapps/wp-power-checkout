<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\Services;

use J7\PowerCheckout\Domains\Invoice\ServiceRegister;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Shared\Utils\IntegrationUtils;
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

	/** @var array 已註冊的 API 列表 */
	protected $apis = [
		[
			'endpoint' => 'settings',
			'method'   => 'get',
		],
		[
			'endpoint' => 'settings/(?P<integration_id>[a-zA-Z_-]+)/toggle',
			'method'   => 'post',
			'callback' => [ __CLASS__, 'toggle_integrations_with_id_callback' ],
		],
		[
			'endpoint' => 'settings/(?P<integration_id>[a-zA-Z_-]+)',
			'method'   => 'get',
			'callback' => [ __CLASS__, 'get_integrations_with_id_callback' ],
		],
		[
			'endpoint' => 'settings/(?P<integration_id>[a-zA-Z_-]+)',
			'method'   => 'post',
			'callback' => [ __CLASS__, 'post_integrations_with_id_callback' ],
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
					'invoices'  => ServiceRegister::get_registered_integration_dtos(),
					'logistics' => [],
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
	public static function toggle_integrations_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$integration_id = (string) $request['integration_id'];
		IntegrationUtils::toggle( $integration_id);
		$toggle_text = IntegrationUtils::is_enabled( $integration_id) ? '啟用' : '禁用';

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "{$integration_id} {$toggle_text}成功",
				'data'    => $integration_id,
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
	public static function get_integrations_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$integration_id = (string) $request['integration_id'];
		$integration    = IntegrationUtils::get_integration_instance( $integration_id);
		if (!$integration) {
			throw new \Exception("Can't find Integration with integration_id: {$integration_id}");
		}
		$settings = $integration->get_settings();

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "取得 {$integration->method_title} 設定成功",
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
	public static function post_integrations_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$integration_id = (string) $request['integration_id'];

		$params = $request->get_params();
		$params = WP::sanitize_text_field_deep($params, false );

		IntegrationUtils::update_option( $integration_id, $params);

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => '儲存成功',
				'data'    => IntegrationUtils::get_option( $integration_id),
			],
			200
		);
	}

	// endregion
}
