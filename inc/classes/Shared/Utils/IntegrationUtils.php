<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Shared\Utils;

use J7\PowerCheckout\Shared\Abstracts\BaseService;

/** Integration Utils 整合 Utils，包含金流、物流、電子發票 */
abstract class IntegrationUtils {

	/** @var array<string, BaseService> 存放已啟用服務的容器  */
	public static array $container = [];


	/** @return BaseService|\WC_Payment_Gateway|null 取得實例  */
	public static function get_integration_instance( string $id ): BaseService|\WC_Payment_Gateway|null {
		return self::$container[ $id ] ?? null;
	}

	/**
	 * @param string $integration_id Payment Gateway ID
	 *
	 * @return mixed 值
	 */
	public static function toggle( string $integration_id ): bool {
		$from_value = self::get_option( $integration_id, 'enabled' );
		$to_value   = $from_value === 'yes' ? 'no' : 'yes';
		return self::update_option( $integration_id, 'enabled', $to_value );
	}


	/**
	 * @param string $integration_id Payment Gateway ID
	 * @param string $key            設定 key
	 *
	 * @return mixed 值
	 */
	public static function get_option( string $integration_id, string $key = '' ): mixed {
		$settings_array = \get_option( self::get_option_name( $integration_id ), [] );
		$settings_array = \is_array( $settings_array) ? $settings_array : [];
		if ($key) {
			return $settings_array[ $key ] ?? null;
		}

		return $settings_array;
	}

	/**
	 * 更新設定
	 *
	 * @param string       $integration_id Payment Gateway ID
	 * @param string|array $key_or_values  設定 key 或 values
	 * @param mixed        $value          值
	 *
	 * @return bool 儲存成功
	 */
	public static function update_option( string $integration_id, string|array $key_or_values, mixed $value = '' ): bool {
		$settings_array = \get_option( self::get_option_name( $integration_id ), [] );
		$settings_array = \is_array( $settings_array) ? $settings_array : [];

		if (\is_array( $key_or_values ) && !$value) {
			$values = $key_or_values;
			return \update_option( self::get_option_name( $integration_id ), \wp_parse_args( $values, $settings_array) );
		}

		$key                    = $key_or_values;
		$settings_array[ $key ] = $value;
		return \update_option( self::get_option_name( $integration_id ), $settings_array );
	}

	/** @return string payment gateway 儲存在 wp_option 的 option_name */
	public static function get_option_name( string $integration_id ): string {
		return "woocommerce_{$integration_id}_settings";
	}


	/**
	 * 該整合服務是否已啟用
	 *
	 * @param string $id 整合 id
	 *
	 * @return bool
	 */
	public static function is_enabled( string $id ): bool {
		$enabled = self::get_option( $id, 'enabled' ) ?? 'no';
		return 'yes' === $enabled;
	}
}
