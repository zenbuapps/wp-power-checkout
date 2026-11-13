<?php
/**
 * 結帳送出時的台灣手機格式檢查 / 虛擬商品地址免填 整合測試（第 4 階段 Red）
 *
 * 對應 feature：specs/features/checkout/checkout-field-validation.feature 全部 Example
 * 對應計劃：specs/open-issue/checkout-enhancement-implementation-plan.md 第 4 階段、DF3
 * 相關決策：specs/clarify/2026-11-13-1232.md D15（區塊結帳 country 不在 locale 隱藏，改 CSS；country 值仍為 TW）
 *
 * 預期實作（測試依賴的行為）：
 *   - PhoneValidationService：傳統 woocommerce_after_checkout_validation 加 WP_Error（訊息含 <strong>欄位名</strong>）；
 *     區塊 woocommerce_store_api_checkout_update_order_from_request 丟 RouteException（HTTP 400、純文字訊息）
 *   - 國家判定：運送國家優先，未填用帳單國家；全虛擬且國家被隱藏時用商店基本國家（背景：TW）
 *   - 不改寫顧客輸入（訂單保存原值）
 *
 * 判讀方式：
 *   - 傳統結帳：classic_validate()（回傳 WP_Error）/ classic_create_order()（真的建單）
 *   - 區塊結帳：store_api_checkout()（rest_do_request 打 POST /wc/store/v1/checkout，見 CheckoutTestCase S1 結論）
 *
 * 執行指令（--filter 要帶路徑，否則 0 收集）：
 *   bash "<scratchpad>/gate.sh" ut tests/Integration/Checkout/CheckoutFieldValidationTest.php
 *   或 npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Checkout/CheckoutFieldValidationTest.php --no-coverage"
 *
 * @group checkout
 */

declare( strict_types=1 );

namespace Tests\Integration\Checkout;

/**
 * Class CheckoutFieldValidationTest
 */
final class CheckoutFieldValidationTest extends CheckoutTestCase {

	/** 傳統結帳錯誤訊息尾段（前面接 <strong>欄位名</strong> + 空白） */
	private const CLASSIC_MSG_SUFFIX = ' 格式有誤，請輸入 09 開頭的 10 碼手機號碼';

	/** 區塊結帳錯誤訊息尾段（前面直接接欄位名，純文字） */
	private const BLOCK_MSG_SUFFIX = '格式有誤，請輸入 09 開頭的 10 碼手機號碼';

	/** 預設商品：實體 */
	private const PHYSICAL = [ '帆布袋C', 300.0, false ];

	/** 預設商品：虛擬 */
	private const VIRTUAL = [ '線上課程A', 1200.0, true ];

	/** 背景：商店基本國家為 "TW" */
	public function set_up(): void {
		parent::set_up();

		\update_option( 'woocommerce_default_country', 'TW' );
		$this->reset_countries_cache();
		$this->reset_checkout_fields_cache();
	}

	// ========== Helper ==========

	/**
	 * 依商品清單建立購物車（含實體商品時自動建立運送區域並選好運費）
	 *
	 * @param array<int, array{0: string, 1: float, 2: bool}> $items 每列 [名稱, 單價, 是否虛擬]
	 */
	private function setup_cart( array $items ): void {
		$has_physical = false;
		foreach ( $items as $item ) {
			if ( ! $item[2] ) {
				$has_physical = true;
				break;
			}
		}

		if ( $has_physical ) {
			$this->create_free_shipping_zone( [ [ 'id' => 'flat_rate', 'settings' => [ 'cost' => '100' ] ] ] );
		}
		$this->enable_bacs_gateway();
		$this->init_cart_session();
		$this->set_customer_country( 'TW' );

		$rows = [];
		foreach ( $items as $item ) {
			$rows[] = [ $this->create_product( $item[0], $item[1], $item[2] ), 1 ];
		}
		$this->add_to_cart( $rows );

		if ( $has_physical ) {
			$this->select_first_shipping_rate();
		}
	}

	/** 讓購物車已選好第一個運費選項（WC 結帳驗證與 Store API 都要求已選運費） */
	private function select_first_shipping_rate(): void {
		\WC()->cart->calculate_totals();

		$chosen = [];
		foreach ( \WC()->shipping()->get_packages() as $index => $package ) {
			$chosen[ $index ] = (string) array_key_first( $package['rates'] ?? [] );
		}
		\WC()->session->set( 'chosen_shipping_methods', $chosen );
		\WC()->cart->calculate_totals();
	}

	/**
	 * 地址欄位預設值（台灣 / 美國），回傳不含前綴的欄位
	 *
	 * @param string               $country   國家代碼
	 * @param array<string,string> $overrides 覆寫欄位（不含前綴）
	 * @return array<string, string>
	 */
	private function address( string $country, array $overrides = [] ): array {
		$base = 'US' === $country
			? [
				'address_1' => '1 Market St',
				'city'      => 'San Francisco',
				'state'     => 'CA',
				'postcode'  => '94105',
			]
			: [
				'address_1' => '中正路1號',
				'city'      => '臺北市',
				'state'     => '臺北市',
				'postcode'  => '100',
			];

		return array_merge(
			[
				'first_name' => '小明',
				'last_name'  => '王',
				'company'    => '',
				'address_2'  => '',
				'country'    => $country,
				'phone'      => '',
			],
			$base,
			$overrides
		);
	}

	/**
	 * 傳統結帳 $_POST（帳單完整、預設手機合法）
	 *
	 * @param array<string,string>      $billing  帳單覆寫（不含前綴）
	 * @param array<string,string>|null $shipping 運送覆寫（不含前綴；null = 不另填運送地址）
	 * @return array<string, mixed>
	 */
	private function classic_post( array $billing = [], ?array $shipping = null ): array {
		$billing_country = $billing['country'] ?? 'TW';
		$post            = [
			'billing_email'  => 'ming@test.tw',
			'payment_method' => 'bacs',
		];

		foreach ( $this->address( $billing_country, array_merge( [ 'phone' => '0912345678' ], $billing ) ) as $key => $value ) {
			$post[ "billing_{$key}" ] = $value;
		}

		if ( null !== $shipping ) {
			foreach ( $this->address( $shipping['country'] ?? 'TW', $shipping ) as $key => $value ) {
				$post[ "shipping_{$key}" ] = $value;
			}
		}

		return $post;
	}

	/**
	 * 傳統結帳 $_POST（只填姓名 / 電話 / Email，不填任何地址欄位）
	 *
	 * @param string $phone 帳單電話
	 * @return array<string, mixed>
	 */
	private function classic_post_without_address( string $phone = '0912345678' ): array {
		return [
			'billing_first_name' => '小明',
			'billing_last_name'  => '王',
			'billing_phone'      => $phone,
			'billing_email'      => 'ming@test.tw',
			'payment_method'     => 'bacs',
		];
	}

	/**
	 * 區塊結帳（Store API）本文
	 *
	 * @param array<string,string>      $billing  帳單覆寫（不含前綴）
	 * @param array<string,string>|null $shipping 運送覆寫（null = 與帳單相同）
	 * @return array<string, mixed>
	 */
	private function block_body( array $billing = [], ?array $shipping = null ): array {
		$billing_address  = $this->address( $billing['country'] ?? 'TW', array_merge( [ 'phone' => '0912345678' ], $billing ) );
		$shipping_address = $this->address( $shipping['country'] ?? ( $billing['country'] ?? 'TW' ), $shipping ?? array_merge( [ 'phone' => '0912345678' ], $billing ) );

		return [
			'billing_address'  => $billing_address + [ 'email' => 'buyer@example.com' ],
			'shipping_address' => $shipping_address,
			'payment_method'   => 'bacs',
		];
	}

	/**
	 * 區塊結帳（Store API）本文：不填地址欄位（country 仍為 TW，D15）
	 *
	 * @param string $phone 帳單電話
	 * @return array<string, mixed>
	 */
	private function block_body_without_address( string $phone = '0912345678' ): array {
		$empty = [
			'first_name' => '小明',
			'last_name'  => '王',
			'company'    => '',
			'address_1'  => '',
			'address_2'  => '',
			'city'       => '',
			'state'      => '',
			'postcode'   => '',
			'country'    => 'TW',
			'phone'      => $phone,
		];

		return [
			'billing_address'  => $empty + [ 'email' => 'ming@test.tw' ],
			'shipping_address' => $empty,
			'payment_method'   => 'bacs',
		];
	}

	/**
	 * 斷言傳統結帳驗證沒有任何錯誤
	 *
	 * @param \WP_Error $errors 驗證結果
	 */
	private function assert_classic_ok( \WP_Error $errors ): void {
		$this->assertFalse(
			$errors->has_errors(),
			'傳統結帳驗證應通過，實際錯誤：' . implode( ' | ', $errors->get_error_messages() )
		);
	}

	/**
	 * 斷言傳統結帳驗證失敗，且錯誤訊息含 <strong>欄位名</strong> 格式有誤…
	 *
	 * @param \WP_Error $errors 驗證結果
	 * @param string    $label  欄位名（帳單電話 / 收件人電話）
	 */
	private function assert_classic_phone_error( \WP_Error $errors, string $label ): void {
		$this->assertTrue( $errors->has_errors(), '傳統結帳驗證應失敗' );
		$this->assertStringContainsString(
			"<strong>{$label}</strong>" . self::CLASSIC_MSG_SUFFIX,
			implode( "\n", $errors->get_error_messages() ),
			'錯誤訊息應含欄位名與手機格式提示'
		);
	}

	/**
	 * 斷言傳統結帳驗證的錯誤中沒有手機格式錯誤（可有其他 WC 原生錯誤）
	 *
	 * @param \WP_Error $errors 驗證結果
	 */
	private function assert_no_phone_format_error( \WP_Error $errors ): void {
		$this->assertStringNotContainsString(
			'格式有誤',
			implode( "\n", $errors->get_error_messages() ),
			'不應出現手機格式錯誤'
		);
	}

	/**
	 * 斷言區塊結帳送單成功並真的建立訂單
	 *
	 * @param \WP_REST_Response $response 回應
	 */
	private function assert_block_order_created( \WP_REST_Response $response ): void {
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame(
			200,
			$response->get_status(),
			'區塊結帳應送單成功：' . \wp_json_encode( $data, JSON_UNESCAPED_UNICODE )
		);
		$this->assertArrayHasKey( 'order_id', $data );
		$this->assertInstanceOf( \WC_Order::class, \wc_get_order( (int) $data['order_id'] ), '訂單應已建立' );
	}

	/**
	 * 斷言區塊結帳 HTTP 400 且訊息為純文字手機格式提示
	 *
	 * @param \WP_REST_Response $response 回應
	 * @param string            $label    欄位名（帳單電話 / 收件人電話）
	 */
	private function assert_block_phone_error( \WP_REST_Response $response, string $label ): void {
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( 400, $response->get_status(), '區塊結帳應回 400：' . \wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
		$this->assertSame(
			$label . self::BLOCK_MSG_SUFFIX,
			$data['message'] ?? null,
			'區塊結帳錯誤訊息應為純文字（不含 HTML 標籤）'
		);
	}

	// ========== 規則：手機檢查開關為 no 時不做任何電話格式檢查 ==========

	/**
	 * 場景：關閉檢查時市話也能下單
	 *
	 * @group happy
	 */
	public function test_關閉檢查時市話也能下單(): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'no' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$order = $this->classic_create_order( $this->classic_post( [ 'phone' => '02-2345-6789' ] ) );

		$this->assertInstanceOf( \WC_Order::class, $order, '關閉檢查時市話應可下單' );
		$this->assertSame( '02-2345-6789', $order->get_billing_phone() );
	}

	// ========== 規則：台灣地址時，帳單電話必須為 09 開頭的 10 碼手機 ==========

	/**
	 * 場景大綱（傳統、通過）：0912345678 / 0912 345 678
	 *
	 * @group happy
	 * @dataProvider 傳統帳單電話通過提供者
	 *
	 * @param string $phone 帳單電話
	 */
	public function test_傳統結帳帳單電話合法時訂單建立成功(string $phone): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'yes' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$post = $this->classic_post( [ 'phone' => $phone ] );
		$this->assert_classic_ok( $this->classic_validate( $post ) );

		$order = $this->classic_create_order( $post );
		$this->assertInstanceOf( \WC_Order::class, $order, "帳單電話 \"{$phone}\" 應建單成功" );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function 傳統帳單電話通過提供者(): array {
		return [
			'純數字'   => [ '0912345678' ],
			'空白分隔' => [ '0912 345 678' ],
		];
	}

	/**
	 * 場景大綱（傳統、通過）：0912-345-678 且訂單保存原值，不被改寫成純數字
	 *
	 * @group happy
	 */
	public function test_傳統結帳連字號電話通過且訂單保存原值(): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'yes' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$order = $this->classic_create_order( $this->classic_post( [ 'phone' => '0912-345-678' ] ) );

		$this->assertSame( '0912-345-678', $order->get_billing_phone(), '訂單帳單電話應為顧客輸入的原值' );
		$this->assertSame(
			'0912-345-678',
			\wc_get_order( $order->get_id() )->get_billing_phone(),
			'重新載入訂單後帳單電話仍應為原值'
		);
	}

	/**
	 * 場景大綱（區塊、通過）：0912345678
	 *
	 * @group happy
	 */
	public function test_區塊結帳帳單電話合法時訂單建立成功(): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'yes' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$response = $this->store_api_checkout( $this->block_body( [ 'phone' => '0912345678' ] ) );

		$this->assert_block_order_created( $response );
	}

	/**
	 * 場景大綱（傳統、失敗）：市話 / 9 碼 / 國碼
	 *
	 * @group error
	 * @dataProvider 傳統帳單電話失敗提供者
	 *
	 * @param string $phone 帳單電話
	 */
	public function test_傳統結帳帳單電話不合法時訂單建立失敗(string $phone): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'yes' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$errors = $this->classic_validate( $this->classic_post( [ 'phone' => $phone ] ) );

		$this->assert_classic_phone_error( $errors, '帳單電話' );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function 傳統帳單電話失敗提供者(): array {
		return [
			'市話'        => [ '0223456789' ],
			'9 碼'        => [ '091234567' ],
			'國碼 +886'   => [ '+886912345678' ],
		];
	}

	/**
	 * 場景大綱（區塊、失敗）：回應狀態碼 400，純文字訊息
	 *
	 * @group error
	 */
	public function test_區塊結帳帳單電話不合法時回應400(): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'yes' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$response = $this->store_api_checkout( $this->block_body( [ 'phone' => '0223456789' ] ) );

		$this->assert_block_phone_error( $response, '帳單電話' );
		$this->assertStringNotContainsString( '<', (string) ( $response->get_data()['message'] ?? '' ), '區塊結帳訊息不可含 HTML 標籤' );
	}

	// ========== 規則：運送電話有填時也須符合手機格式；未填不檢查 ==========

	/**
	 * 場景大綱：運送電話為空 / 合法手機 → 訂單建立成功
	 *
	 * @group happy
	 * @dataProvider 運送電話通過提供者
	 *
	 * @param string $shipping_phone 運送電話
	 */
	public function test_運送電話未填或合法時訂單建立成功(string $shipping_phone): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'yes' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$post = $this->classic_post( [ 'phone' => '0912345678' ], [ 'phone' => $shipping_phone ] );
		$this->assert_classic_ok( $this->classic_validate( $post ) );

		$order = $this->classic_create_order( $post );
		$this->assertInstanceOf( \WC_Order::class, $order );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function 運送電話通過提供者(): array {
		return [
			'未填'       => [ '' ],
			'合法手機'   => [ '0987654321' ],
		];
	}

	/**
	 * 場景大綱：運送電話為市話 → 錯誤訊息為「收件人電話」
	 *
	 * @group error
	 */
	public function test_運送電話為市話時驗證失敗(): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'yes' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$errors = $this->classic_validate( $this->classic_post( [ 'phone' => '0912345678' ], [ 'phone' => '0423456789' ] ) );

		$this->assert_classic_phone_error( $errors, '收件人電話' );
	}

	// ========== 規則：非台灣地址不檢查（運送國家優先，未填用帳單國家） ==========

	/**
	 * 場景大綱：運送 US / 帳單 TW；運送未填 / 帳單 US → 訂單建立成功（電話 "+1 415 555 0100"）
	 *
	 * @group edge
	 * @dataProvider 非台灣地址通過提供者
	 *
	 * @param string $shipping_country 運送國家（空字串 = 未填運送地址）
	 * @param string $billing_country  帳單國家
	 */
	public function test_非台灣地址不檢查電話(string $shipping_country, string $billing_country): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'yes' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$shipping = '' === $shipping_country ? null : [ 'country' => $shipping_country ];
		$post     = $this->classic_post( [ 'country' => $billing_country, 'phone' => '+1 415 555 0100' ], $shipping );

		$errors = $this->classic_validate( $post );

		$this->assert_no_phone_format_error( $errors );
		$this->assert_classic_ok( $errors );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function 非台灣地址通過提供者(): array {
		return [
			'運送 US、帳單 TW' => [ 'US', 'TW' ],
			'運送未填、帳單 US' => [ '', 'US' ],
		];
	}

	/**
	 * 場景大綱：運送 TW、帳單 US → 運送國家優先，仍檢查帳單電話
	 *
	 * @group edge
	 */
	public function test_運送國家為台灣時即使帳單國家非台灣仍檢查帳單電話(): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'yes' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$errors = $this->classic_validate(
			$this->classic_post( [ 'country' => 'US', 'phone' => '+1 415 555 0100' ], [ 'country' => 'TW' ] )
		);

		$this->assert_classic_phone_error( $errors, '帳單電話' );
	}

	// ========== 規則：帳單國家被虛擬隱藏地址隱藏時，以商店基本國家判定是否檢查 ==========

	/**
	 * 場景：全虛擬購物車隱藏國家後仍檢查手機（沒有 billing_country 欄位，改用商店國家 TW）
	 *
	 * @group edge
	 */
	public function test_全虛擬購物車國家被隱藏時以商店國家判斷並檢查手機(): void {
		$this->set_checkout_settings(
			[
				'tw_phone_validation'  => 'yes',
				'virtual_hide_address' => 'yes',
			]
		);
		$this->setup_cart( [ self::VIRTUAL ] );

		$errors = $this->classic_validate( $this->classic_post_without_address( '0223456789' ) );

		$this->assert_classic_phone_error( $errors, '帳單電話' );
	}

	// ========== 規則：虛擬隱藏地址開啟且全虛擬時，未填帳單地址仍可建立訂單 ==========

	/**
	 * 場景大綱（傳統 / 區塊、全虛擬）：未填地址送出 → 訂單建立成功
	 *
	 * @group happy
	 * @dataProvider 全虛擬免填地址提供者
	 *
	 * @param string $checkout_type 結帳型態（傳統 / 區塊）
	 */
	public function test_全虛擬購物車未填地址仍可建立訂單(string $checkout_type): void {
		$this->set_checkout_settings( [ 'virtual_hide_address' => 'yes' ] );
		$this->setup_cart( [ self::VIRTUAL, [ '電子書B', 300.0, true ] ] );

		if ( '傳統' === $checkout_type ) {
			$post = $this->classic_post_without_address();
			$this->assert_classic_ok( $this->classic_validate( $post ) );
			$this->assertInstanceOf( \WC_Order::class, $this->classic_create_order( $post ) );
			return;
		}

		$this->assert_block_order_created( $this->store_api_checkout( $this->block_body_without_address() ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function 全虛擬免填地址提供者(): array {
		return [
			'傳統' => [ '傳統' ],
			'區塊' => [ '區塊' ],
		];
	}

	/**
	 * 場景大綱（傳統、含實體）：未填地址送出 → 維持 WC 原必填驗證，提示帳單地址為必填
	 *
	 * @group error
	 */
	public function test_含實體商品未填地址時維持必填驗證(): void {
		$this->set_checkout_settings( [ 'virtual_hide_address' => 'yes' ] );
		$this->setup_cart( [ self::VIRTUAL, self::PHYSICAL ] );

		$errors = $this->classic_validate( $this->classic_post_without_address() );

		$this->assertTrue( $errors->has_errors(), '含實體商品未填地址應失敗' );
		$this->assertContains(
			'billing_address_1_required',
			$errors->get_error_codes(),
			'應提示帳單地址為必填，實際錯誤碼：' . implode( ',', $errors->get_error_codes() )
		);
	}

	// ========== edge：locale 快取先建好（計劃 R3 坑 2） ==========

	/**
	 * 在購物車「空的」時先建立 WC()->countries 的 locale 快取（此時 CartInspector 判為非全虛擬，
	 * 快取內容是「顯示地址」的版本），之後才把虛擬商品加入購物車。
	 * 模擬真實請求中 locale 快取先於購物車載入完成的情境。
	 *
	 * @param array<int, array{0: string, 1: float, 2: bool}> $items 之後才加入購物車的商品
	 */
	private function setup_cart_after_locale_cached( array $items ): void {
		$this->enable_bacs_gateway();
		$this->init_cart_session();
		$this->set_customer_country( 'TW' );

		// 購物車空 -> 非全虛擬 -> 快取成「顯示地址」版本
		$this->reset_countries_cache();
		\WC()->countries->get_country_locale();

		$rows = [];
		foreach ( $items as $item ) {
			$rows[] = [ $this->create_product( $item[0], $item[1], $item[2] ), 1 ];
		}
		$this->add_to_cart( $rows );
	}

	/**
	 * 區塊結帳、全虛擬購物車、不填地址：locale 快取先建好（非全虛擬版本）後才加入虛擬商品，仍應送單成功
	 *
	 * 需要在 Store API 驗證前重算 locale（第 4 階段 VirtualCartAddressService 的 reset hook，priority 1）。
	 *
	 * @group edge
	 */
	public function test_區塊結帳locale快取先建好後加入虛擬商品仍可免填地址送單(): void {
		$this->set_checkout_settings( [ 'virtual_hide_address' => 'yes' ] );
		$this->setup_cart_after_locale_cached( [ self::VIRTUAL ] );

		$this->assert_block_order_created( $this->store_api_checkout( $this->block_body_without_address() ) );
	}

	/**
	 * 傳統結帳、全虛擬購物車、不填地址：locale 快取先建好後才加入虛擬商品，不應有地址必填錯誤
	 *
	 * 傳統結帳的欄位由 woocommerce_checkout_fields 直接 unset，不吃 locale 快取，預期事先就綠（防回歸）。
	 *
	 * @group edge
	 */
	public function test_傳統結帳locale快取先建好後加入虛擬商品仍無地址必填錯誤(): void {
		$this->set_checkout_settings( [ 'virtual_hide_address' => 'yes' ] );
		$this->setup_cart_after_locale_cached( [ self::VIRTUAL ] );

		$errors = $this->classic_validate( $this->classic_post_without_address() );

		$this->assert_classic_ok( $errors );
		$address_codes = array_filter(
			$errors->get_error_codes(),
			static fn( $code ): bool => (bool) preg_match( '/^billing_(address_1|city|state|postcode|country)_required$/', (string) $code )
		);
		$this->assertSame( [], array_values( $address_codes ), '不應有帳單地址必填錯誤' );
	}

	// ========== edge / security ==========

	/**
	 * edge：billing_phone 為空字串時本服務不報錯（必填交給 WC 原生驗證）
	 *
	 * 註：這是「不報錯」的負向斷言，實作前後都會綠；用來防止日後服務把空字串誤判成格式錯誤。
	 *
	 * @group edge
	 */
	public function test_帳單電話為空字串時本服務不報格式錯誤(): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'yes' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$errors = $this->classic_validate( $this->classic_post( [ 'phone' => '' ] ) );

		$this->assert_no_phone_format_error( $errors );
		$this->assertContains(
			'billing_phone_required',
			$errors->get_error_codes(),
			'空字串應由 WC 原生必填驗證處理，實際錯誤碼：' . implode( ',', $errors->get_error_codes() )
		);
	}

	/**
	 * security：電話夾帶 <script> 驗證失敗，錯誤訊息不回顯輸入、輸入值不被改寫
	 *
	 * 直接觸發 woocommerce_after_checkout_validation（繞過 WC 對電話欄位的 sanitize），確認服務本身不因去除字元而放行。
	 *
	 * @group security
	 */
	public function test_電話夾帶script驗證失敗且原值不被改寫(): void {
		$this->set_checkout_settings( [ 'tw_phone_validation' => 'yes' ] );
		$this->setup_cart( [ self::PHYSICAL ] );

		$malicious = '0912345678<script>';
		$data      = [
			'billing_country'           => 'TW',
			'billing_phone'             => $malicious,
			'ship_to_different_address' => false,
		];
		$errors    = new \WP_Error();

		\do_action( 'woocommerce_after_checkout_validation', $data, $errors );

		$this->assertTrue( $errors->has_errors(), '夾帶 <script> 的電話應驗證失敗' );
		$messages = implode( "\n", $errors->get_error_messages() );
		$this->assertStringContainsString( '<strong>帳單電話</strong>' . self::CLASSIC_MSG_SUFFIX, $messages );
		$this->assertStringNotContainsString( '<script', $messages, '錯誤訊息不可回顯使用者輸入' );
		$this->assertSame( $malicious, $data['billing_phone'], '輸入值不可被改寫' );
	}
}
