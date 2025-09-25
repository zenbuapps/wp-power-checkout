<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\Services;

use J7\PowerCheckout\Utils\IntegrationUtils;
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
			'endpoint' => 'integrations',
			'method'   => 'get',
		],
		[
			'endpoint' => 'toggle-integration',
			'method'   => 'post',
		],
		[
			'endpoint' => 'settings/(?P<setting_key>[a-zA-Z_-]+)',
			'method'   => 'get',
			'callback' => [ __CLASS__, 'get_settings_with_integration_key_callback' ],
		],
		[
			'endpoint' => 'settings/(?P<setting_key>[a-zA-Z_-]+)',
			'method'   => 'post',
			'callback' => [ __CLASS__, 'post_settings_with_integration_key_callback' ],
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
	public static function get_settings_with_integration_key_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$setting_key            = (string) $request['setting_key'];
		$integration            = IntegrationUtils::find_integration( $setting_key);
		$registered_integration = $integration->get_registered_integration();

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "取得 {$setting_key} 設定成功",
				'data'    => $registered_integration::get_settings(),
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
	public static function post_settings_with_integration_key_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$setting_key = (string) $request['setting_key'];
		$integration = IntegrationUtils::find_integration( $setting_key);

		$params = $request->get_params();
		$params = WP::sanitize_text_field_deep($params, false );

		$registered_integration = $integration->get_registered_integration();
		$registered_integration::save_settings( $params );
		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => '儲存成功',
				'data'    => $registered_integration::get_settings(),
			],
			200
		);
	}

	// endregion



	// region Integrations 相關

	/**
	 * 取得 integrations 設定
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_integrations_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$integrations = IntegrationUtils::get_integrations();

		$integrations_array = \array_map(static fn( $integration ) => $integration->to_array(), $integrations);

		return new \WP_REST_Response( $integrations_array, 200 );
	}

	/**
	 * 開關 Integration
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 * @throws \Exception 如果 integration_key 無效或其他錯誤
	 */
	public function post_toggle_integration_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$integration_key = (string) $request->get_param( 'integration_key' );
		$integration     = IntegrationUtils::get_integration( $integration_key);

		if (!$integration) {
			throw new \Exception("Can't find Integration with {$integration_key} key");
		}

		$integration->toggle();

		$updated_integration = IntegrationUtils::get_integration($integration_key);
		$toggle_text         = $updated_integration?->enabled ? '啟用' : '禁用';

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => "{$updated_integration?->name} {$toggle_text}成功",
				'data'    => $updated_integration?->to_array(),
			],
			200
		);
	}

	// endregion
}
