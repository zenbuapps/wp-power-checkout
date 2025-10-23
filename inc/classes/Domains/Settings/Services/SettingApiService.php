<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\Services;

use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
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
			'endpoint' => 'gateways',
			'method'   => 'get',
		],
		[
			'endpoint' => 'gateways/(?P<gateway_id>[a-zA-Z_-]+)/toggle',
			'method'   => 'post',
			'callback' => [ __CLASS__, 'toggle_gateways_with_gateway_id_callback' ],
		],
		[
			'endpoint' => 'gateways/(?P<gateway_id>[a-zA-Z_-]+)/settings',
			'method'   => 'get',
			'callback' => [ __CLASS__, 'get_gateways_settings_with_id_callback' ],
		],
		[
			'endpoint' => 'gateways/(?P<gateway_id>[a-zA-Z_-]+)/settings',
			'method'   => 'post',
			'callback' => [ __CLASS__, 'post_gateways_settings_with_id_callback' ],
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
	public static function get_gateways_settings_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$gateway_id = (string) $request['gateway_id'];
		$gateway    = GatewayUtils::get_gateway($gateway_id);
		if (!$gateway) {
			throw new \Exception("Can't find Gateway with gateway_id:{$gateway_id}");
		}
		$settings = $gateway->get_settings();

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "取得 {$gateway_id} 設定成功",
				'data'    => $settings->to_array(true),
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
	public static function post_gateways_settings_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$gateway_id = (string) $request['gateway_id'];
		$gateway    = GatewayUtils::get_gateway($gateway_id);
		if (!$gateway) {
			throw new \Exception("Can't find Gateway with gateway_id:{$gateway_id}");
		}

		$params = $request->get_params();
		$params = WP::sanitize_text_field_deep($params, false );

		GatewayUtils::update_option( $gateway_id, $params);

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => '儲存成功',
				'data'    => GatewayUtils::get_option( $gateway_id),
			],
			200
		);
	}

	// endregion



	// region Gateways 相關

	/**
	 * 取得 gateways 設定
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_gateways_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$gateways = GatewayUtils::get_gateways(false, true);
		return new \WP_REST_Response( $gateways, 200 );
	}

	/**
	 * 開關 Gateway
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 * @throws \Exception 如果 gateway_key 無效或其他錯誤
	 */
	public static function toggle_gateways_with_gateway_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$gateway_id = (string) $request['gateway_id'];
		$gateway    = GatewayUtils::get_gateway($gateway_id);

		if (!$gateway) {
			throw new \Exception("Can't find Gateway with gateway_id:{$gateway_id}");
		}

		GatewayUtils::toggle( $gateway_id);
		$toggle_text = \wc_string_to_bool( GatewayUtils::get_option( $gateway_id, 'enabled')) ? '啟用' : '禁用';

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "{$gateway?->title} {$toggle_text}成功",
				'data'    => $gateway,
			],
			200
		);
	}

	// endregion
}
