<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Utils;

use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGateway;

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
}
