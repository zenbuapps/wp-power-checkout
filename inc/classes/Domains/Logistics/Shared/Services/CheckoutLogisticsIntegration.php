<?php
/**
 * 物流共用結帳整合（傳統短碼結帳 + WooCommerce Blocks 結帳）
 *
 * 給實作 {@see ISupportsCheckoutStoreSelection} 的物流 provider 用，一次接上兩種結帳頁：
 *  1. 子類型：運送方式每個子類型一個 rate（rate id 尾碼 / rate meta `sub_type`），顧客選哪個就是哪個。
 *  2. 選店：超商子類型在運送方式下方出現「選擇門市」。下單前沒有訂單，所以用 CartLogisticsSession
 *     的 session 權杖綁定；門市選完由 provider 的選店回呼寫回 session，再回到結帳頁。
 *     - Blocks：`inc/assets/blocks/pc_logistics.tsx`（ExperimentalOrderShippingPackages slot fill），
 *       已選門市經 Store API cart extension（namespace `pc_logistics`）讀回。
 *     - 傳統：`inc/assets/js/classic-pc-logistics.js`。
 *  3. 驗證：選了超商取貨但沒選門市 → 不讓下單（傳統 $errors / Blocks RouteException 400）。
 *  4. 下單：子類型 / 付款情境 / 門市寫進訂單 meta（LogisticsMetaKeys，HPOS 相容），清掉 session 暫存。
 *
 * 綠界有較早的專屬整合（BlocksLogisticsIntegration），不走這裡。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Services;

use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\LogisticsPaymentScenario;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\CartLogisticsSession;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ISupportsCheckoutStoreSelection;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ViteAsset;

/** 物流共用結帳整合 */
final class CheckoutLogisticsIntegration {

	/** @var string Store API cart extension namespace（前端 cart.extensions['pc_logistics']） */
	public const NAMESPACE = 'pc_logistics';

	/** @var string Blocks script handle */
	private const BLOCKS_HANDLE = 'pc-logistics-blocks';

	/** @var string 傳統結帳 script handle */
	private const CLASSIC_HANDLE = 'pc-logistics-classic';

	/** Register hooks @return void */
	public static function register_hooks(): void {
		\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_store_api' ] );
		\add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ], 20 );

		// 下單寫 meta：傳統（在各 provider 的 save_checkout_meta p10 之後）/ Blocks
		\add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'save_order_meta' ], 20, 1 );
		\add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'save_order_meta' ], 10, 1 );

		// 超商取貨未選門市不讓下單
		\add_action( 'woocommerce_after_checkout_validation', [ __CLASS__, 'validate_classic' ], 10, 2 );
		\add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'validate_blocks' ], 20, 1 );
	}

	// region providers

	/**
	 * 已啟用且支援結帳選店的 provider（key = 運送方式 id）
	 *
	 * @return array<string, ISupportsCheckoutStoreSelection&ILogisticsProvider>
	 */
	public static function providers(): array {
		$providers = [];
		foreach ( ProviderRegister::get_provider_ids() as $id ) {
			$provider = ProviderRegister::resolve_enabled_provider( $id );
			if ($provider instanceof ISupportsCheckoutStoreSelection) {
				$providers[ $provider->get_checkout_shipping_method_id() ] = $provider;
			}
		}
		return $providers;
	}

	/**
	 * 以 provider id 取得支援結帳選店的 provider
	 *
	 * @param string $provider_id provider id
	 * @return (ISupportsCheckoutStoreSelection&ILogisticsProvider)|null
	 */
	public static function find_provider( string $provider_id ): ?ISupportsCheckoutStoreSelection {
		foreach ( self::providers() as $provider ) {
			if ($provider->get_provider_meta()->id === $provider_id) {
				return $provider;
			}
		}
		return null;
	}

	/**
	 * 解析 rate id（`{method_id}[:{instance_id}]:{sub_type}`）
	 *
	 * @param string $rate_id 運送方式 rate id
	 * @return array{0: string, 1: string}|null [method_id, sub_type]；不是本整合負責的運送方式回 null
	 */
	public static function parse_rate_id( string $rate_id ): ?array {
		$parts = \explode( ':', $rate_id );
		if (\count( $parts ) < 2) {
			return null;
		}
		$method_id = $parts[0];
		if (!isset( self::providers()[ $method_id ] )) {
			return null;
		}
		return [ $method_id, (string) \end( $parts ) ];
	}

	/**
	 * 目前購物車選定、需要選門市的運送方式（[provider, sub_type]）
	 *
	 * @return array{0: ISupportsCheckoutStoreSelection, 1: string}|null
	 */
	public static function chosen_cvs_selection(): ?array {
		if (!\function_exists( 'WC' ) || null === \WC()->session) {
			return null;
		}
		$chosen = \WC()->session->get( 'chosen_shipping_methods' );
		if (!\is_array( $chosen )) {
			return null;
		}
		$providers = self::providers();
		foreach ( $chosen as $rate_id ) {
			$parsed = self::parse_rate_id( (string) $rate_id );
			if (null === $parsed) {
				continue;
			}
			[ $method_id, $sub_type ] = $parsed;
			$provider                 = $providers[ $method_id ];
			if (\in_array( $sub_type, $provider->get_cvs_sub_types(), true )) {
				return [ $provider, $sub_type ];
			}
		}
		return null;
	}

	/**
	 * 目前 session 已選門市是否屬於指定子類型
	 *
	 * @param string $sub_type 子類型
	 * @return array<string, string>|null
	 */
	private static function selected_store_for( string $sub_type ): ?array {
		$store = CartLogisticsSession::get_selected_store();
		if (null === $store || '' === $store['store_id'] || $store['sub_type'] !== $sub_type) {
			return null;
		}
		return $store;
	}

	// endregion

	// region 驗證

	/**
	 * 傳統結帳驗證：超商取貨須先選門市
	 *
	 * @param array<string, mixed> $data   結帳資料
	 * @param \WP_Error            $errors 錯誤集合
	 * @return void
	 */
	public static function validate_classic( array $data, \WP_Error $errors ): void {
		unset( $data );
		$message = self::missing_store_message();
		if (null !== $message) {
			$errors->add( 'pc_logistics_store_required', $message );
		}
	}

	/**
	 * Blocks 結帳驗證：超商取貨須先選門市
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException 未選門市
	 */
	public static function validate_blocks( \WC_Order $order ): void {
		unset( $order );
		$message = self::missing_store_message();
		if (null === $message) {
			return;
		}
		if (\class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' )) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'power_checkout_store_required', $message, 400 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
	}

	/**
	 * 未選門市時的提示（已選或不需選門市回 null）
	 *
	 * @return string|null
	 */
	public static function missing_store_message(): ?string {
		$selection = self::chosen_cvs_selection();
		if (null === $selection) {
			return null;
		}
		if (null !== self::selected_store_for( $selection[1] )) {
			return null;
		}
		return (string) \__( '請先按「選擇門市」挑選取貨的超商門市，再送出訂單。', 'power_checkout' );
	}

	// endregion

	// region 下單寫 meta

	/**
	 * 下單：子類型 / 付款情境 / 門市寫進訂單 meta，搬完清掉 session 暫存
	 *
	 * 傳統與 Blocks 共用：子類型一律從訂單運送項目的 `sub_type` meta 取（WC 會把 rate meta 搬進去），
	 * 只接受 provider 支援的子類型。永不拋例外。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function save_order_meta( \WC_Order $order ): void {
		try {
			$providers = self::providers();
			foreach ( $order->get_shipping_methods() as $item ) {
				$provider = $providers[ $item->get_method_id() ] ?? null;
				if (null === $provider) {
					continue;
				}

				$sub_type = (string) $item->get_meta( 'sub_type' );
				if (!\in_array( $sub_type, $provider->get_supported_methods(), true )) {
					continue;
				}

				$meta = new LogisticsMetaKeys( $order );
				$meta->update_sub_type( $sub_type );
				$meta->update_provider_id( $provider->get_provider_meta()->id );
				$meta->update_payment_scenario(
					'cod' === $order->get_payment_method()
						? LogisticsPaymentScenario::COD->value
						: LogisticsPaymentScenario::ONLINE->value
				);

				if (\in_array( $sub_type, $provider->get_cvs_sub_types(), true )) {
					$store = self::selected_store_for( $sub_type );
					if (null !== $store) {
						$meta->update_store_id( $store['store_id'] );
						$meta->update_store_name( $store['store_name'] );
						$meta->update_store_addr( $store['store_addr'] );
						CartLogisticsSession::clear();
					}
				}
				return;
			}
		} catch ( \Throwable $e ) {
			Plugin::logger( '物流結帳寫入訂單 meta 失敗', 'error', [ 'error' => $e->getMessage() ] );
		}
	}

	// endregion

	// region Store API / 前端資源

	/** 註冊 Store API cart extension + update callback @return void */
	public static function register_store_api(): void {
		if (\function_exists( 'woocommerce_store_api_register_endpoint_data' )) {
			\woocommerce_store_api_register_endpoint_data(
				[
					'endpoint'        => 'cart',
					'namespace'       => self::NAMESPACE,
					'data_callback'   => [ __CLASS__, 'cart_extension_data' ],
					'schema_callback' => [ __CLASS__, 'cart_extension_schema' ],
				]
			);
		}
		if (\function_exists( 'woocommerce_store_api_register_update_callback' )) {
			// 只當作「重新整理購物車」觸發點；門市只能由選店回呼經權杖驗證寫入，前端不能直接寫
			\woocommerce_store_api_register_update_callback(
				[
					'namespace' => self::NAMESPACE,
					'callback'  => static function (): void {},
				]
			);
		}
	}

	/**
	 * Cart extension 資料：目前 session 已選門市
	 *
	 * @return array<string, string>
	 */
	public static function cart_extension_data(): array {
		$store = CartLogisticsSession::get_selected_store();
		return [
			'store_id'   => $store['store_id'] ?? '',
			'store_name' => $store['store_name'] ?? '',
			'store_addr' => $store['store_addr'] ?? '',
			'sub_type'   => $store['sub_type'] ?? '',
		];
	}

	/**
	 * Cart extension schema
	 *
	 * @return array<string, mixed>
	 */
	public static function cart_extension_schema(): array {
		$field = static fn( string $desc ): array => [
			'description' => $desc,
			'type'        => 'string',
			'readonly'    => true,
		];
		return [
			'store_id'   => $field( '已選門市代碼' ),
			'store_name' => $field( '已選門市名稱' ),
			'store_addr' => $field( '已選門市地址' ),
			'sub_type'   => $field( '物流子類型' ),
		];
	}

	/**
	 * 前端設定（Blocks / 傳統結帳都經 wp_localize_script 給 window.pcLogistics）
	 *
	 * @return array<string, mixed>
	 */
	public static function get_frontend_data(): array {
		$providers = [];
		foreach ( self::providers() as $method_id => $provider ) {
			$providers[] = [
				'provider'      => $provider->get_provider_meta()->id,
				'method_id'     => $method_id,
				'cvs_sub_types' => \array_values( $provider->get_cvs_sub_types() ),
			];
		}
		return [
			'store_selection_url' => \rest_url( 'power-checkout/v1/logistics/store-selection' ),
			'nonce'               => \wp_create_nonce( 'wp_rest' ),
			'providers'           => $providers,
			'selected_store'      => self::cart_extension_data(),
			'i18n'                => [
				'select'   => \__( '選擇門市', 'power_checkout' ),
				'reselect' => \__( '重新選擇門市', 'power_checkout' ),
				'loading'  => \__( '處理中…', 'power_checkout' ),
				'error'    => \__( '開不了門市地圖，請稍後再試或聯絡商家。', 'power_checkout' ),
				'pickup'   => \__( '取貨門市', 'power_checkout' ),
			],
		];
	}

	/** 結帳頁載入前端資源（Blocks / 傳統二擇一） @return void */
	public static function enqueue_assets(): void {
		if (!\function_exists( 'is_checkout' ) || !\is_checkout() || ( \function_exists( 'is_wc_endpoint_url' ) && \is_wc_endpoint_url() )) {
			return;
		}
		if (!self::providers()) {
			return;
		}

		$post     = \get_post();
		$is_block = $post instanceof \WP_Post && \function_exists( 'has_block' ) && \has_block( 'woocommerce/checkout', $post );

		if ($is_block) {
			if (!ViteAsset::block_script_exists( 'pc_logistics' )) {
				return;
			}
			\wp_enqueue_script(
				self::BLOCKS_HANDLE,
				ViteAsset::block_script_url( 'pc_logistics' ),
				[ 'wc-blocks-checkout', 'wp-plugins', 'wp-data', 'wp-element', 'wp-html-entities', 'wc-settings' ],
				ViteAsset::block_version( 'pc_logistics' ),
				true
			);
			Plugin::instance()->add_module_handle( self::BLOCKS_HANDLE );
			// 前端設定在這裡才算（此時已確定登入者，nonce 才正確）；Blocks 端讀 window.pcLogistics
			\wp_localize_script( self::BLOCKS_HANDLE, 'pcLogistics', self::get_frontend_data() );
			return;
		}

		\wp_enqueue_script(
			self::CLASSIC_HANDLE,
			Plugin::$url . '/inc/assets/js/classic-pc-logistics.js',
			[ 'jquery' ],
			Plugin::$version,
			true
		);
		\wp_localize_script( self::CLASSIC_HANDLE, 'pcLogistics', self::get_frontend_data() );
	}

	// endregion
}
