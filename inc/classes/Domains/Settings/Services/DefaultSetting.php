<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Settings\Services;

class DefaultSetting {

	/** Register hooks */
	public static function register_hooks(): void {
		\add_filter('woocommerce_localisation_address_formats', [ __CLASS__, 'modify_tw_address_formats' ], 100, 1);
	}

	/**
	 * 修改台灣地址格式
	 *
	 * @return array<string, string> 國家、地址格式 array
	 * @see \WC_Countries::get_address_formats
	 */
	public static function modify_tw_address_formats( array $address_formats ): array {
		$address_formats['TW'] = "{company}\n{last_name} {first_name}\n{postcode} {country}{state}{city}\n{address_1}\n{address_2}";
		return $address_formats;
	}
}
