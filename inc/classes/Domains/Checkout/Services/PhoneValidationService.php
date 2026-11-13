<?php
/**
 * F3：結帳送出時的台灣手機格式檢查（DF3）
 *
 * - 傳統結帳：woocommerce_after_checkout_validation 以 $errors->add() 回報（訊息含 <strong>欄位名</strong>）
 * - 區塊結帳：woocommerce_store_api_checkout_update_order_from_request 丟 RouteException（HTTP 400、純文字訊息）
 * - 國家判定：運送國家優先，未填用帳單國家，兩者皆空（例如全虛擬購物車隱藏國家）用商店基本國家；非 TW 不檢查
 * - 判斷前先去除空白與連字號，只用 normalize 結果判斷，不改寫顧客輸入；空字串不報錯（必填交給 WC）
 * - 非 RouteException 的 \Throwable 一律記 log 吞掉，不擋單
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout\Services;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use J7\PowerCheckout\Domains\Checkout\DTOs\CheckoutEnhancementSettingsDTO;
use J7\PowerCheckout\Plugin;

/** 台灣手機格式檢查 */
final class PhoneValidationService {

	/** @var string 設定開關鍵 */
	private const SETTING_KEY = 'tw_phone_validation';

	/** @var string 需要檢查的國家 */
	private const TW = 'TW';

	/** @var string 區塊結帳錯誤碼 */
	private const ERROR_CODE = 'power_checkout_invalid_phone';

	/** @var string 傳統結帳 WP_Error code（同 WC 原生欄位驗證） */
	private const CLASSIC_ERROR_CODE = 'validation';

	/**
	 * 註冊 hooks（無條件掛上，開關於 callback 內判斷）
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate_classic' ], 10, 2 );
		\add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'validate_block' ], 10, 2 );
	}

	/**
	 * 是否為台灣手機號碼（去除空白與連字號後須為 09 開頭的 10 碼數字）
	 *
	 * @param string $phone 電話（不會被改寫）
	 * @return bool 是否合法
	 */
	public static function is_valid_tw_mobile( string $phone ): bool {
		$normalized = \preg_replace( '/[\s\-]+/', '', $phone );
		if ( !\is_string( $normalized ) ) {
			return false;
		}

		return 1 === \preg_match( '/^09[0-9]{8}\z/', $normalized );
	}

	/**
	 * 傳統結帳：檢查帳單 / 運送電話
	 *
	 * @param mixed $data   WC_Checkout::get_posted_data() 的結果
	 * @param mixed $errors 驗證錯誤集合
	 * @return void
	 */
	public static function validate_classic( mixed $data, mixed $errors ): void {
		try {
			if ( !\is_array( $data ) || !$errors instanceof \WP_Error || !CheckoutEnhancementSettingsDTO::is_on( self::SETTING_KEY ) ) {
				return;
			}

			$country = self::resolve_country(
				self::string_value( $data['shipping_country'] ?? null ),
				self::string_value( $data['billing_country'] ?? null )
			);
			if ( self::TW !== $country ) {
				return;
			}

			foreach ( self::find_invalid_labels( self::string_value( $data['billing_phone'] ?? null ), self::classic_shipping_phone( $data ) ) as $label ) {
				$errors->add(
					self::CLASSIC_ERROR_CODE,
					\sprintf(
						/* translators: %s: 欄位名稱（含 <strong> 標籤） */
						\__( '%s 格式有誤，請輸入 09 開頭的 10 碼手機號碼', 'power_checkout' ),
						"<strong>{$label}</strong>"
					)
				);
			}
		} catch ( \Throwable $e ) {
			Plugin::logger( "PhoneValidationService::validate_classic 失敗：{$e->getMessage()}", 'error' );
		}
	}

	/**
	 * 區塊結帳：檢查帳單 / 運送電話，失敗時丟 RouteException（400）
	 *
	 * @param mixed $order   已依請求更新的訂單
	 * @param mixed $request Store API 請求
	 * @return void
	 * @throws RouteException 電話格式錯誤
	 */
	public static function validate_block( mixed $order, mixed $request = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		try {
			if ( !$order instanceof \WC_Order || !CheckoutEnhancementSettingsDTO::is_on( self::SETTING_KEY ) ) {
				return;
			}

			$country = self::resolve_country( $order->get_shipping_country(), $order->get_billing_country() );
			if ( self::TW !== $country ) {
				return;
			}

			$labels = self::find_invalid_labels( $order->get_billing_phone(), $order->get_shipping_phone() );
			if ( [] === $labels ) {
				return;
			}

			throw new RouteException(
				self::ERROR_CODE,
				\sprintf(
					/* translators: %s: 欄位名稱 */
					\__( '%s格式有誤，請輸入 09 開頭的 10 碼手機號碼', 'power_checkout' ),
					$labels[0]
				),
				400
			);
		} catch ( RouteException $e ) {
			throw $e; // 驗證失敗必須穿出，交給 Store API 回 400
		} catch ( \Throwable $e ) {
			Plugin::logger( "PhoneValidationService::validate_block 失敗：{$e->getMessage()}", 'error' );
		}
	}

	/**
	 * 找出格式錯誤的欄位名稱（空字串跳過，必填交給 WC）
	 *
	 * @param string $billing_phone  帳單電話
	 * @param string $shipping_phone 運送電話
	 * @return array<int, string> 格式錯誤的欄位名稱（帳單在前）
	 */
	private static function find_invalid_labels( string $billing_phone, string $shipping_phone ): array {
		$labels = [];

		if ( '' !== \trim( $billing_phone ) && !self::is_valid_tw_mobile( $billing_phone ) ) {
			$labels[] = \__( '帳單電話', 'power_checkout' );
		}
		if ( '' !== \trim( $shipping_phone ) && !self::is_valid_tw_mobile( $shipping_phone ) ) {
			$labels[] = \__( '收件人電話', 'power_checkout' );
		}

		return $labels;
	}

	/**
	 * 判定檢查用國家：運送國家優先，未填用帳單國家，皆空用商店基本國家
	 *
	 * @param string $shipping_country 運送國家
	 * @param string $billing_country  帳單國家
	 * @return string 國家代碼
	 */
	private static function resolve_country( string $shipping_country, string $billing_country ): string {
		if ( '' !== $shipping_country ) {
			return $shipping_country;
		}
		if ( '' !== $billing_country ) {
			return $billing_country;
		}

		return (string) \WC()->countries->get_base_country();
	}

	/**
	 * 傳統結帳的運送電話
	 *
	 * WC 傳統結帳預設沒有 shipping_phone 欄位（get_posted_data() 不會帶），
	 * 因此有勾「運送到不同地址」時才從 $_POST 讀（nonce 已由 WC_Checkout::process_checkout 驗證）。
	 *
	 * @param array<mixed> $data 結帳資料
	 * @return string 運送電話（未填為空字串）
	 */
	private static function classic_shipping_phone( array $data ): string {
		if ( \array_key_exists( 'shipping_phone', $data ) ) {
			return self::string_value( $data['shipping_phone'] );
		}
		if ( empty( $data['ship_to_different_address'] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce 已由 WC_Checkout::process_checkout 驗證；以 wc_clean 清理（同 WC get_posted_data）
		$clean = isset( $_POST['shipping_phone'] ) && \is_string( $_POST['shipping_phone'] ) ? \wc_clean( \wp_unslash( $_POST['shipping_phone'] ) ) : '';
		return \is_string( $clean ) ? $clean : '';
	}

	/**
	 * 純量值轉字串（非純量回傳空字串）
	 *
	 * @param mixed $value 值
	 * @return string 字串
	 */
	private static function string_value( mixed $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}
}
