<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Utils;

use J7\PowerCheckout\Domains\Payment\Contracts\IGateway;

/** Payment Gateway Utils */
abstract class GatewayUtils {

	/**
	 * @param string $gateway_id Gateway ID
	 * @param bool   $available_only 是否只顯示能用的 gateway
	 * @param bool   $power_checkout_only 是否只顯示 power_checkout 的 gateway
	 * @return IGateway|null 取得 gateway
	 */
	public static function get_gateway( string $gateway_id, bool $available_only = false, bool $power_checkout_only = true ): IGateway|null {
		$gateways = self::get_gateways($available_only, $power_checkout_only);
		return $gateways[ $gateway_id ] ?? null;
	}


	/** @return IGateway[] 取得 gateways */
	public static function get_gateways( bool $available_only = false, bool $power_checkout_only = true ): array {
		if ($available_only) {
			$gateways = \WC()->payment_gateways()->get_available_payment_gateways();
		} else {
			$gateways = \WC()->payment_gateways()->payment_gateways();
		}

		if ($power_checkout_only) {
			return \array_filter($gateways, static fn ( $gateway ) => \in_array($gateway->id, self::get_powercheckout_gateway_ids(), true));
		}

		return $gateways;
	}


	/** @return string[] 回傳 power_checkout 註冊的 payment_gateway_ids */
	public static function get_powercheckout_gateway_ids(): array {
		return \apply_filters( 'power_checkout_payment_gateway_ids', [] );
	}


	/**
	 * @param string $gateway_id Payment Gateway ID
	 *
	 * @return mixed 值
	 */
	public static function toggle( string $gateway_id ): bool {
		$from_value = self::get_option( $gateway_id, 'enabled' );
		$to_value   = \wc_bool_to_string(!\wc_string_to_bool( $from_value ));
		return self::update_option( $gateway_id, 'enabled', $to_value );
	}


	/**
	 * @param string $gateway_id Payment Gateway ID
	 * @param string $key 設定 key
	 *
	 * @return mixed 值
	 */
	public static function get_option( string $gateway_id, string $key = '' ): mixed {
		$settings_array = \get_option( self::get_option_name( $gateway_id ), [] );
		$settings_array = \is_array( $settings_array) ? $settings_array : [];
		if ($key) {
			return $settings_array[ $key ] ?? null;
		}

		return $settings_array;
	}

	/**
	 * @param string       $gateway_id    Payment Gateway ID
	 * @param string|array $key_or_values 設定 key 或 values
	 * @param mixed        $value         值
	 *
	 * @return bool 儲存成功
	 */
	public static function update_option( string $gateway_id, string|array $key_or_values, mixed $value = '' ): bool {
		$settings_array = \get_option( self::get_option_name( $gateway_id ), [] );
		$settings_array = \is_array( $settings_array) ? $settings_array : [];

		if (\is_array( $key_or_values ) && !$value) {
			$values = $key_or_values;
			return \update_option( self::get_option_name( $gateway_id ), \wp_parse_args( $values, $settings_array) );
		}

		$key                    = $key_or_values;
		$settings_array[ $key ] = $value;
		return \update_option( self::get_option_name( $gateway_id ), $settings_array );
	}

	/** @return string payment gateway 儲存在 wp_option 的 option_name */
	public static function get_option_name( string $gateway_id ): string {
		return "woocommerce_{$gateway_id}_settings";
	}
}
