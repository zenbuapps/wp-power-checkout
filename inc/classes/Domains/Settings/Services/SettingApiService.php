<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\Services;

use _PHPStan_bc6352b8e\Nette\Neon\Exception;
use J7\WpUtils\Classes\ApiBase;
use J7\PowerCheckout\Domains\Settings\DTOs\SettingsDTO as PowerCheckoutSettings;
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
			'endpoint' => 'toggle-gateway',
			'method'   => 'post',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	/**
	 * 取得設定
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 * @phpstan-ignore-next-line
	 */
	public function get_settings_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( PowerCheckoutSettings::instance()->to_array(), 200 );
	}

	/**
	 * 開關 Gateway
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response
	 * @throws \_PHPStan_bc6352b8e\Nette\Neon\Exception 錯誤
	 * @throw Exception 如果 gateway_id 無效或其他錯誤
	 * @phpstan-ignore-next-line
	 */
	public function post_toggle_gateway_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$gateway_id = $request->get_param( 'gateway_id' );
		$gateways   = \WC()->payment_gateways()->get_available_payment_gateways();
		if ( !isset( $gateways[ $gateway_id ] ) ) {
			throw new Exception('Invalid gateway ID');
		}
		$gateway = $gateways[ $gateway_id ];

		// 檢查服務類是否存在
		if (!\method_exists($gateway, 'get_service_class')) {
			throw new Exception('get_service_class method does not exist on ' . $gateway::class);
		}

		$service_class = $gateway->get_service_class();
		$service_class::toggle();

		return new \WP_REST_Response(
			[
				'status'     => 'success',
				'gateway_id' => $gateway_id,
			],
			200
			);
	}
}
