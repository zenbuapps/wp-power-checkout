<?php
/**
 * 結帳體驗強化設定 DTO
 *
 * 設定存於 wp_options `woocommerce_checkout_enhancement_settings`（虛擬 provider id = checkout_enhancement）。
 * 刻意不繼承 wp-utils DTO / BaseSettingsDTO（R7：required_properties 拼錯導致必填驗證失效），
 * 改以純靜態方法提供讀取與鍵級驗證合併。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout\DTOs;

use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;

/** 結帳體驗強化設定 */
final class CheckoutEnhancementSettingsDTO {

	/** @var string 虛擬 provider id（對應 REST 路徑與 option 名稱） */
	public const PROVIDER_ID = 'checkout_enhancement';

	/** @var array<int, string> 開關鍵（值僅接受 yes / no） */
	public const TOGGLE_KEYS = [
		'last_name_first',
		'tw_address',
		'tw_phone_validation',
		'virtual_hide_address',
		'virtual_auto_complete',
		'free_shipping_hint',
	];

	/** @var array<int, string> 色碼鍵（值僅接受 #RRGGBB） */
	public const COLOR_KEYS = [
		'free_shipping_bg_color',
		'free_shipping_text_color',
	];

	/** @var array<int, string> 文案鍵（sanitize 後不可為空字串） */
	public const TEXT_KEYS = [
		'free_shipping_text_left',
		'free_shipping_text_achieved',
	];

	/** @var string 未達門檻文案必須包含的金額佔位符 */
	public const PRICE_PLACEHOLDER = '{price}';

	/** @var array<string, string> 預設值（對齊 specs/erm.dbml 1.5.0） */
	public const DEFAULTS = [
		'last_name_first'             => 'no',
		'tw_address'                  => 'no',
		'tw_phone_validation'         => 'no',
		'virtual_hide_address'        => 'no',
		'virtual_auto_complete'       => 'no',
		'free_shipping_hint'          => 'no',
		'free_shipping_text_left'     => '再買 {price} 元即可享免運',
		'free_shipping_text_achieved' => '已享免運',
		'free_shipping_bg_color'      => '#d36f6f',
		'free_shipping_text_color'    => '#ffffff',
	];

	/**
	 * 取得 wp_options 名稱
	 *
	 * @return string option 名稱
	 */
	public static function get_option_name(): string {
		return ProviderUtils::get_option_name( self::PROVIDER_ID );
	}

	/**
	 * 取得已儲存設定（僅保留已知鍵且值為字串者；option 不存在或損毀時回傳空陣列）
	 *
	 * @return array<string, string> 已儲存設定
	 */
	public static function get_stored(): array {
		$stored = \get_option( self::get_option_name(), [] );
		return \is_array( $stored ) ? self::filter_known( $stored ) : [];
	}

	/**
	 * 取得完整 10 鍵設定（未儲存的鍵以預設值補齊）
	 *
	 * @return array<string, string> 完整設定
	 */
	public static function get_all(): array {
		return \array_merge( self::DEFAULTS, self::get_stored() );
	}

	/**
	 * 開關是否開啟
	 *
	 * @param string $key 開關鍵
	 * @return bool 是否為 yes
	 */
	public static function is_on( string $key ): bool {
		return 'yes' === ( self::get_all()[ $key ] ?? '' );
	}

	/**
	 * 取得字串設定值
	 *
	 * @param string $key 設定鍵
	 * @return string 設定值（未知鍵回傳空字串）
	 */
	public static function get_string( string $key ): string {
		return self::get_all()[ $key ] ?? '';
	}

	/**
	 * 鍵級驗證合併：合法輸入覆寫儲存值，不合法輸入忽略（保留原值），未知鍵丟棄
	 *
	 * @param array<mixed> $input  使用者輸入
	 * @param array<mixed> $stored 目前已儲存設定
	 * @return array<string, string> 合併後應寫入的設定（僅含已知鍵，未補預設值）
	 */
	public static function merge_valid( array $input, array $stored ): array {
		$merged = self::filter_known( $stored );

		foreach ( \array_keys( self::DEFAULTS ) as $key ) {
			if ( !\array_key_exists( $key, $input ) ) {
				continue;
			}

			$value = self::validate( $key, $input[ $key ] );
			if ( null !== $value ) {
				$merged[ $key ] = $value;
			}
		}

		return $merged;
	}

	/**
	 * 驗證單一鍵的值
	 *
	 * @param string $key   設定鍵
	 * @param mixed  $value 輸入值
	 * @return string|null 合法時回傳（消毒後的）值，不合法回傳 null
	 */
	private static function validate( string $key, mixed $value ): ?string {
		if ( !\is_string( $value ) ) {
			return null;
		}

		if ( \in_array( $key, self::TOGGLE_KEYS, true ) ) {
			return \in_array( $value, [ 'yes', 'no' ], true ) ? $value : null;
		}

		if ( \in_array( $key, self::COLOR_KEYS, true ) ) {
			return 1 === \preg_match( '/^#[0-9a-fA-F]{6}$/D', $value ) ? $value : null;
		}

		if ( \in_array( $key, self::TEXT_KEYS, true ) ) {
			$text = StrHelper::trim_invisible( \sanitize_text_field( $value ) );
			if ( '' === $text ) {
				return null; // ASM-1：消毒後為空字串視為不合法
			}
			if ( 'free_shipping_text_left' === $key && !\str_contains( $text, self::PRICE_PLACEHOLDER ) ) {
				return null;
			}
			return $text;
		}

		return null;
	}

	/**
	 * 只保留已知鍵且值為字串的項目
	 *
	 * @param array<mixed> $values 原始陣列
	 * @return array<string, string> 過濾後陣列
	 */
	private static function filter_known( array $values ): array {
		$filtered = [];
		foreach ( \array_keys( self::DEFAULTS ) as $key ) {
			if ( isset( $values[ $key ] ) && \is_string( $values[ $key ] ) ) {
				$filtered[ $key ] = $values[ $key ];
			}
		}
		return $filtered;
	}
}
