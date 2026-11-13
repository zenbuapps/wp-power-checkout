<?php
/**
 * 結帳體驗強化（Domains/Checkout）整合測試共用基礎類別
 *
 * 執行指令（只跑本目錄；--filter 要帶路徑，否則 0 收集）：
 *   bash "<scratchpad>/gate.sh" ut tests/Integration/Checkout/
 *   或 npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Checkout/ --no-coverage"
 *
 * 提供：購物車 session、商品、運送區域、傳統結帳驗證 / 建單、區塊結帳（Store API）送單、Plugin::logger 攔截。
 * 對應規格：specs/open-issue/checkout-enhancement-implementation-plan.md 第 0 階段第 2、3 點 + R6。
 *
 * ---------------------------------------------------------------------------
 * 試驗 S1 結論（Store API 能否在 WP_UnitTestCase 下載入購物車並真實送單）
 * ---------------------------------------------------------------------------
 * 結論：【可用】Store API 在 WP_UnitTestCase 下能載入購物車並真實送單 -> 區塊結帳例子一律走真實 rest_do_request
 * （store_api_checkout()），不需要降級為「直接 do_action + Reflection 呼叫 OrderController::validate_order_before_payment」。
 *
 * 實測（WC 10.1.4 / wp-env tests-cli，見 CheckoutHarnessTest::test_S1_*）：
 *  - 全虛擬購物車 + 空地址（country=''）+ payment_method=bacs：HTTP 400，code=woocommerce_rest_invalid_address_country
 *    （"Sorry, we do not allow orders from the provided country ()"）。代表購物車確實載入（否則會是 cart_empty），
 *    並走到 WC 原生地址驗證；F4 開關關閉時空 country 照樣被擋，這就是 F4 要改變的基準行為。
 *  - 全虛擬購物車 + 完整 TW 地址（state 需填，WC 原生 TW 的 state 必填）：HTTP 200，回傳 order_id，訂單真的建立。
 *  - 若 bacs 未啟用：HTTP 400，code=woocommerce_rest_checkout_payment_method_disabled（需先 enable_bacs_gateway()）。
 *
 * harness 使用須知（踩雷）：
 *  - 含實體商品的購物車測試必須先 create_free_shipping_zone()，否則 WC_Cart::needs_shipping() 因「全站無啟用運送方式」回 false。
 *  - 需付款的訂單（價格 > 0）送 Store API 前要 enable_bacs_gateway()。
 *  - WC_Checkout::$fields 為單例快取，harness 於 set_up / set_checkout_settings / classic_* 都會清掉；WC()->countries 同理（R6）。
 *  - capture_logs() 依賴的 woocommerce_logger_log_message 在 WC_Logger::log() 每個 handler 觸發一次。
 * ---------------------------------------------------------------------------
 */

declare( strict_types=1 );

namespace Tests\Integration\Checkout;

use Tests\Integration\TestCase;

/**
 * Class CheckoutTestCase
 * 所有 tests/Integration/Checkout/ 測試的基底；類別請另外標註 @group checkout，方法標註白名單 group。
 */
abstract class CheckoutTestCase extends TestCase {

	/** 設定 option 名稱（虛擬 provider id = checkout_enhancement） */
	protected const SETTINGS_OPTION = 'woocommerce_checkout_enhancement_settings';

	/**
	 * 設定預設值（對齊 specs/erm.dbml 1.5.0 wp_options_checkout_enhancement_settings）
	 * 刻意寫在 harness，不依賴尚未實作的 CheckoutEnhancementSettingsDTO。
	 *
	 * @var array<string, string>
	 */
	protected const DEFAULT_SETTINGS = [
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
	 * 被攔截的 Plugin::logger / WC_Logger 訊息
	 *
	 * @var array<int, array{message: string, level: string, context: array<string, mixed>}>
	 */
	protected array $captured_logs = [];

	/**
	 * 測試前的 WC 全域狀態（tear_down 還原）
	 *
	 * @var array<string, mixed>
	 */
	private array $original_wc_state = [];

	/** 設定（每個測試前） */
	public function set_up(): void {
		parent::set_up();

		$wc                      = \WC();
		$this->original_wc_state = [
			'session'   => $wc->session,
			'customer'  => $wc->customer,
			'cart'      => $wc->cart,
			'countries' => $wc->countries,
		];

		$this->captured_logs = [];
		\delete_option( self::SETTINGS_OPTION );
		$this->reset_countries_cache();
		$this->reset_checkout_fields_cache();
	}

	/** 清理（每個測試後）：清購物車、session 與設定，還原 WC 全域 */
	public function tear_down(): void {
		$wc = \WC();

		if ( $wc->cart instanceof \WC_Cart ) {
			$wc->cart->empty_cart( false );
		}
		if ( $wc->session instanceof \WC_Session_Handler ) {
			$wc->session->destroy_session();
		}

		\delete_option( self::SETTINGS_OPTION );
		\wp_set_current_user( 0 );
		$_POST    = [];
		$_REQUEST = [];

		// 還原 WC 全域（countries 重建，避免 locale / states 快取殘留到下一個測試）
		$wc->session   = $this->original_wc_state['session'] ?? null;
		$wc->customer  = $this->original_wc_state['customer'] ?? null;
		$wc->cart      = $this->original_wc_state['cart'] ?? null;
		$wc->countries = new \WC_Countries();

		$this->reset_checkout_fields_cache();
		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		\delete_option( 'woocommerce_bacs_settings' );
		\WC()->payment_gateways()->init();

		// 讓下一個測試重新建立 REST server（避免 Store API 路由 / 請求狀態殘留）
		$GLOBALS['wp_rest_server'] = null;

		parent::tear_down();
	}

	// ========== 設定 Helper ==========

	/**
	 * 寫入結帳強化設定，其餘鍵用預設值。
	 * 寫入後必須重建 WC()->countries（R6：WC_Countries 會快取 locale / states）。
	 *
	 * @param array<string, string> $kv 要覆寫的設定鍵值
	 */
	protected function set_checkout_settings( array $kv ): void {
		\update_option( self::SETTINGS_OPTION, array_merge( self::DEFAULT_SETTINGS, $kv ) );
		$this->reset_countries_cache();
		$this->reset_checkout_fields_cache();
	}

	/** 重建 WC()->countries（R6） */
	protected function reset_countries_cache(): void {
		\WC()->countries = new \WC_Countries();
	}

	/** 清掉 WC_Checkout 對欄位定義的快取（WC_Checkout::$fields 為單例內部快取） */
	protected function reset_checkout_fields_cache(): void {
		$checkout = \WC()->checkout();
		$prop     = new \ReflectionProperty( \WC_Checkout::class, 'fields' );
		$prop->setValue( $checkout, null );
	}

	// ========== 購物車 / 商品 Helper ==========

	/** 建立購物車 session：WC_Session_Handler + WC_Customer + WC_Cart */
	protected function init_cart_session(): void {
		$wc          = \WC();
		$wc->session = new \WC_Session_Handler();
		$wc->session->init();
		$wc->customer = new \WC_Customer( 0, true );
		$wc->cart     = new \WC_Cart();
	}

	/**
	 * 設定顧客所在國家（運送與帳單）
	 *
	 * @param string $country 國家代碼
	 */
	protected function set_customer_country( string $country = 'TW' ): void {
		$customer = \WC()->customer;
		$customer->set_billing_country( $country );
		$customer->set_shipping_country( $country );
	}

	/**
	 * 建立簡單商品
	 *
	 * @param string $name         商品名稱
	 * @param float  $price        價格
	 * @param bool   $virtual      是否虛擬商品
	 * @param bool   $downloadable 是否可下載
	 * @return \WC_Product_Simple 已儲存的商品
	 */
	protected function create_product( string $name, float $price, bool $virtual, bool $downloadable = false ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( $name );
		$product->set_status( 'publish' );
		$product->set_regular_price( (string) $price );
		$product->set_virtual( $virtual );
		$product->set_downloadable( $downloadable );
		$product->set_manage_stock( false );
		$product->set_stock_status( 'instock' );
		$product->save();

		return $product;
	}

	/**
	 * 加入購物車
	 *
	 * @param array<int, array{0: \WC_Product|int, 1?: int}|array{product: \WC_Product|int, qty?: int}> $rows 每列為 [商品或ID, 數量] 或 ['product' => 商品或ID, 'qty' => 數量]
	 * @return array<int, string> 購物車項目 key 列表
	 */
	protected function add_to_cart( array $rows ): array {
		$keys = [];
		foreach ( $rows as $row ) {
			$product = $row['product'] ?? $row[0];
			$qty     = (int) ( $row['qty'] ?? $row[1] ?? 1 );
			$id      = $product instanceof \WC_Product ? $product->get_id() : (int) $product;

			$key = \WC()->cart->add_to_cart( $id, $qty );
			$this->assertNotFalse( $key, "加入購物車失敗（商品 #{$id}）" );
			$keys[] = (string) $key;
		}
		return $keys;
	}

	/**
	 * 建立運送區域（涵蓋國家 TW）並加入運送方式
	 *
	 * 注意：WC_Cart::needs_shipping() 要求「全站至少有一個啟用的運送方式」，
	 * 因此含實體商品的購物車測試必須先呼叫本方法，否則實體商品也會被判成不需運送。
	 *
	 * @param array<int, array{id: string, settings?: array<string, mixed>, enabled?: bool}> $methods 運送方式，例如 [['id'=>'flat_rate','settings'=>['cost'=>100]], ['id'=>'free_shipping','settings'=>['requires'=>'min_amount','min_amount'=>1000]]]
	 * @param string $name 區域名稱
	 * @param string $country 區域涵蓋的國家
	 * @return \WC_Shipping_Zone 區域
	 */
	protected function create_free_shipping_zone( array $methods, string $name = '台灣', string $country = 'TW' ): \WC_Shipping_Zone {
		global $wpdb;

		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( $name );
		$zone->set_zone_order( 0 );
		$zone->add_location( $country, 'country' );
		$zone->save();

		foreach ( $methods as $method ) {
			$instance_id = $zone->add_shipping_method( $method['id'] );
			\update_option(
				"woocommerce_{$method['id']}_{$instance_id}_settings",
				array_merge( [ 'title' => $method['id'] ], $method['settings'] ?? [] )
			);

			if ( ( $method['enabled'] ?? true ) === false ) {
				$wpdb->update(
					"{$wpdb->prefix}woocommerce_shipping_zone_methods",
					[ 'is_enabled' => 0 ],
					[ 'instance_id' => $instance_id ]
				);
			}
		}

		\WC_Cache_Helper::get_transient_version( 'shipping', true );
		$this->reset_countries_cache();

		return $zone;
	}

	/**
	 * 啟用 BACS（銀行轉帳）付款方式，讓需付款的訂單能通過 payment_method 驗證。
	 * tear_down 會重新初始化 gateways 以免殘留。
	 */
	protected function enable_bacs_gateway(): void {
		\update_option(
			'woocommerce_bacs_settings',
			[
				'enabled'      => 'yes',
				'title'        => '銀行轉帳',
				'description'  => '',
				'instructions' => '',
			]
		);
		\WC()->payment_gateways()->init();
	}

	// ========== 傳統結帳 Helper ==========

	/**
	 * 傳統結帳驗證：以 ReflectionMethod 呼叫 WC_Checkout protected validate_checkout
	 *
	 * @param array<string, mixed> $post 模擬 $_POST（有 shipping_* 時自動補 ship_to_different_address=1）
	 * @return \WP_Error 驗證錯誤集合（無錯誤時 has_errors() 為 false）
	 */
	protected function classic_validate( array $post ): \WP_Error {
		$data = $this->classic_posted_data( $post );

		$checkout = \WC()->checkout();
		$errors   = new \WP_Error();

		$method = new \ReflectionMethod( $checkout, 'validate_checkout' );
		$method->invokeArgs( $checkout, [ &$data, &$errors ] );

		return $errors;
	}

	/**
	 * 傳統結帳建單：先驗證（有錯誤則丟例外），通過後 create_order
	 *
	 * @param array<string, mixed> $post 模擬 $_POST
	 * @return \WC_Order 已建立的訂單
	 * @throws \RuntimeException 驗證失敗或建單失敗
	 */
	protected function classic_create_order( array $post ): \WC_Order {
		$data = $this->classic_posted_data( $post );

		$checkout = \WC()->checkout();
		$errors   = new \WP_Error();

		$method = new \ReflectionMethod( $checkout, 'validate_checkout' );
		$method->invokeArgs( $checkout, [ &$data, &$errors ] );

		if ( $errors->has_errors() ) {
			throw new \RuntimeException( '傳統結帳驗證失敗：' . implode( ' | ', $errors->get_error_messages() ) );
		}

		$order_id = $checkout->create_order( $data );
		if ( $order_id instanceof \WP_Error ) {
			throw new \RuntimeException( '傳統結帳建單失敗：' . $order_id->get_error_message() );
		}

		$order = \wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			throw new \RuntimeException( "建單後找不到訂單 #{$order_id}" );
		}

		return $order;
	}

	/**
	 * 把模擬 $_POST 寫入全域並取得 WC_Checkout::get_posted_data()
	 *
	 * @param array<string, mixed> $post 模擬 $_POST
	 * @return array<string, mixed> 解析後的結帳資料
	 */
	private function classic_posted_data( array $post ): array {
		$has_shipping = false;
		foreach ( array_keys( $post ) as $key ) {
			if ( str_starts_with( (string) $key, 'shipping_' ) ) {
				$has_shipping = true;
				break;
			}
		}

		$defaults = [
			'woocommerce-process-checkout-nonce' => \wp_create_nonce( 'woocommerce-process_checkout' ),
		];
		if ( $has_shipping ) {
			$defaults['ship_to_different_address'] = '1';
		}

		$_POST    = array_merge( $defaults, $post );
		$_REQUEST = $_POST;

		$this->reset_checkout_fields_cache();

		return \WC()->checkout()->get_posted_data();
	}

	// ========== 區塊結帳（Store API）Helper ==========

	/**
	 * 區塊結帳送單：rest_do_request 打 POST /wc/store/v1/checkout
	 *
	 * @param array<string, mixed> $body 請求本文（billing_address / shipping_address / payment_method ...）
	 * @return \WP_REST_Response 回應（錯誤也會轉成 WP_REST_Response，用 get_status() / get_data()['code'] 判斷）
	 */
	protected function store_api_checkout( array $body ): \WP_REST_Response {
		\add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );

		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) \wp_json_encode( $body ) );

		return \rest_do_request( $request );
	}

	// ========== Plugin::logger 攔截 Helper ==========

	/**
	 * 開始攔截 Plugin::logger（底層是 new \WC_Logger()->log()）。
	 * 透過 woocommerce_logger_log_message filter 收集，不改變訊息（仍照常寫入 handler）。
	 * 注意：該 filter 在 WC_Logger::log() 內「每個 handler 呼叫一次」，預設單一 handler 時每則訊息收集一次。
	 */
	protected function capture_logs(): void {
		$this->captured_logs = [];

		\add_filter(
			'woocommerce_logger_log_message',
			function ( $message, $level = '', $context = [] ) {
				$this->captured_logs[] = [
					'message' => (string) $message,
					'level'   => (string) $level,
					'context' => is_array( $context ) ? $context : [],
				];
				return $message;
			},
			10,
			3
		);
	}

	/**
	 * 取得已攔截的 log 訊息（純文字）
	 *
	 * @return array<int, string>
	 */
	protected function captured_log_messages(): array {
		return array_column( $this->captured_logs, 'message' );
	}

	/**
	 * 斷言已攔截的 log 中有包含指定文字的訊息
	 *
	 * @param string      $needle 期望包含的文字
	 * @param string|null $level  限定等級（例如 'error'），null 不限
	 */
	protected function assert_log_contains( string $needle, ?string $level = null ): void {
		foreach ( $this->captured_logs as $log ) {
			if ( null !== $level && $log['level'] !== $level ) {
				continue;
			}
			if ( str_contains( $log['message'], $needle ) ) {
				$this->assertTrue( true );
				return;
			}
		}
		$this->fail(
			sprintf(
				'找不到包含 "%s"%s 的 log，已攔截：%s',
				$needle,
				null !== $level ? "（level={$level}）" : '',
				wp_json_encode( $this->captured_log_messages(), JSON_UNESCAPED_UNICODE )
			)
		);
	}
}
