<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\Services;

use J7\PowerCheckout\Utils\IntegrationUtils;
use J7\WpUtils\Classes\ApiBase;
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
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

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
		$integration_key = $request->get_param( 'integration_key' );
		$integration     = IntegrationUtils::get_integration( $integration_key);

		if (!$integration) {
			throw new \Exception("Can't find Integration with {$integration_key} key");
		}

		$integration->toggle();

		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => 'Integration toggled successfully',
				'data'    => IntegrationUtils::get_integration($integration_key)?->to_array(),
			],
			200
			);
	}
}
