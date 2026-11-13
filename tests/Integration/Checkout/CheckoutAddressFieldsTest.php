<?php
/**
 * 結帳地址欄位（姓名排序 / 台灣縣市鄉鎮 / 虛擬商品隱藏地址）整合測試
 *
 * 對應 feature：specs/features/checkout/checkout-address-fields.feature（第 3 階段 Red）
 *
 * 執行指令（--filter 要帶路徑，否則 0 收集）：
 *   bash "<scratchpad>/gate.sh" ut tests/Integration/Checkout/CheckoutAddressFieldsTest.php
 *   或 npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Checkout/CheckoutAddressFieldsTest.php --no-coverage"
 *
 * 判讀方式：
 *  - 傳統結帳：讀 WC()->countries->get_address_fields() / WC()->checkout()->get_checkout_fields('billing')
 *  - 區塊結帳：讀 Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils::get_country_data()['TW']['locale' | 'states']
 *    （WC 10.1.4：priority 會被轉成 index，class 被移除，hidden / required 原樣保留）
 *
 * 區塊結帳隱藏地址的 S2 試驗結論（見 specs/clarify/2026-11-13-1232.md D15）：
 *  - state / city / postcode / address_1 / address_2 在「明確的 TW key」設 hidden=true + required=false（default key 無效，不斷言）
 *  - country 的 locale 不可設 hidden，改以 CSS 隱藏；CSS 只在「區塊結帳頁 + 開關 yes + 全虛擬購物車」輸出
 *
 * @group checkout
 */

declare( strict_types=1 );

namespace Tests\Integration\Checkout;

use Automattic\WooCommerce\Blocks\Utils\CartCheckoutUtils;

/**
 * Class CheckoutAddressFieldsTest
 */
final class CheckoutAddressFieldsTest extends CheckoutTestCase {

	/** 區塊結帳隱藏國家欄位的 CSS 選擇器（D15） */
	private const COUNTRY_CSS_SELECTOR = '.wc-block-components-address-form .wc-block-components-country-input';

	/** 區塊結帳以 locale 隱藏的地址欄位（country 不在此列，D15） */
	private const BLOCK_HIDDEN_FIELDS = [ 'state', 'city', 'postcode', 'address_1', 'address_2' ];

	/** 22 縣市（內政部正式用字「臺」） */
	private const TW_COUNTIES = [
		'臺北市',
		'新北市',
		'桃園市',
		'臺中市',
		'臺南市',
		'高雄市',
		'基隆市',
		'新竹市',
		'嘉義市',
		'新竹縣',
		'苗栗縣',
		'彰化縣',
		'南投縣',
		'雲林縣',
		'嘉義縣',
		'屏東縣',
		'宜蘭縣',
		'花蓮縣',
		'臺東縣',
		'澎湖縣',
		'金門縣',
		'連江縣',
	];

	// ========== Helper ==========

	/**
	 * 依商品清單建立購物車（含實體商品時自動建立運送區域）
	 *
	 * @param array<int, array{0: string, 1: float, 2: bool, 3?: bool}> $items 每列 [名稱, 單價, 是否虛擬, 是否可下載]
	 */
	private function setup_cart_with( array $items ): void {
		foreach ( $items as $item ) {
			if ( ! $item[2] ) {
				$this->create_free_shipping_zone(
					[
						[
							'id'       => 'flat_rate',
							'settings' => [ 'cost' => '100' ],
						],
					]
					);
				break;
			}
		}

		$this->init_cart_session();
		$this->set_customer_country( 'TW' );

		$rows = [];
		foreach ( $items as $item ) {
			$rows[] = [ $this->create_product( $item[0], $item[1], $item[2], $item[3] ?? false ), 1 ];
		}
		$this->add_to_cart( $rows );
	}

	/**
	 * 傳統結帳的帳單欄位（購物車已載入、設定已寫入之後呼叫）
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function classic_billing_fields(): array {
		$this->reset_countries_cache();
		$this->reset_checkout_fields_cache();
		return \WC()->checkout()->get_checkout_fields( 'billing' );
	}

	/**
	 * 區塊結帳前端拿到的資料（模擬進入結帳頁當下：購物車已載入，locale 快取為空）
	 *
	 * @param string $country 國家代碼
	 * @return array{allowBilling?: bool, allowShipping?: bool, states?: array<string, string>, locale?: array<string, array<string, mixed>>}
	 */
	private function block_country_data( string $country = 'TW' ): array {
		$this->reset_countries_cache();
		$data = CartCheckoutUtils::get_country_data();
		return $data[ $country ] ?? [];
	}

	/**
	 * 區塊結帳 locale 的單一欄位定義
	 *
	 * @param string $field 欄位（不含 billing_ 前綴）
	 * @return array<string, mixed>
	 */
	private function block_locale_field( string $field, string $country = 'TW' ): array {
		return $this->block_country_data( $country )['locale'][ $field ] ?? [];
	}

	/**
	 * 建立結帳頁並導向（含 $post 全域）
	 *
	 * @param string $content 頁面內容（傳統 [woocommerce_checkout] / 區塊 <!-- wp:woocommerce/checkout /-->）
	 */
	private function go_to_checkout_page( string $content ): void {
		$page_id = (int) self::factory()->post->create(
			[
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => '結帳',
				'post_content' => $content,
			]
		);
		\update_option( 'woocommerce_checkout_page_id', $page_id );

		$this->go_to( (string) \get_permalink( $page_id ) );

		$GLOBALS['post'] = \get_post( $page_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- 測試模擬目前頁面
		\setup_postdata( $GLOBALS['post'] );
	}

	/**
	 * 丟棄「非本外掛」造成的 wp_enqueue_script incorrect usage 通知。
	 * powerhouse 外掛（kucrut/vite-for-wp）以 `in-footer` 鍵 enqueue，在 WP 7.0 會觸發
	 * `Unrecognized key(s) in the $args param: in-footer`，與本功能無關，且會讓 WP_UnitTestCase 判失敗。
	 * 只丟棄訊息含 `in-footer` 的那一筆，其他 wp_enqueue_script 誤用仍會讓測試失敗。
	 */
	private function discard_vendor_enqueue_notice(): void {
		$message = $this->caught_doing_it_wrong['wp_enqueue_script'] ?? '';
		if ( is_string( $message ) && str_contains( $message, 'in-footer' ) ) {
			unset( $this->caught_doing_it_wrong['wp_enqueue_script'] );
		}
	}

	/** 觸發前台 wp_enqueue_scripts（並丟棄第三方外掛的已知通知） */
	private function run_wp_enqueue_scripts(): void {
		\do_action( 'wp_enqueue_scripts' );
		$this->discard_vendor_enqueue_notice();
	}

	/** 清空前台 scripts / styles 佇列 */
	private function reset_frontend_assets(): void {
		// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- 測試重置前台資源佇列
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;
		// phpcs:enable WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * 取得傳統結帳頁 localize 的 pc_tw_address_data（解碼後）
	 *
	 * @return array<string, mixed>|null 未 localize 時回傳 null
	 */
	private function localized_tw_address_data(): ?array {
		$data = \wp_scripts()->get_data( 'pc-classic-tw-address', 'data' );
		if ( ! is_string( $data ) || '' === $data ) {
			return null;
		}

		$start = strpos( $data, '{' );
		$end   = strrpos( $data, '}' );
		if ( false === $start || false === $end ) {
			return null;
		}

		$decoded = json_decode( substr( $data, $start, $end - $start + 1 ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * 前台輸出 wp_head + wp_footer 的 HTML（正規化空白）
	 */
	private function render_frontend_output(): string {
		ob_start();
		try {
			\do_action( 'wp_head' );
			\do_action( 'wp_footer' );
		} finally {
			$html = (string) ob_get_clean();
			$this->discard_vendor_enqueue_notice();
		}
		return (string) preg_replace( '/\s+/', ' ', $html );
	}

	/**
	 * 斷言傳統結帳欄位「隱藏且非必填」（key 不存在，或型態 hidden 且非必填）
	 *
	 * @param array<string, array<string, mixed>> $fields 帳單欄位
	 * @param string                              $key    完整 key（billing_xxx）
	 */
	private function assert_classic_hidden( array $fields, string $key ): void {
		if ( ! isset( $fields[ $key ] ) ) {
			$this->assertArrayNotHasKey( $key, $fields );
			return;
		}
		$field = $fields[ $key ];
		$this->assertTrue( 'hidden' === ( $field['type'] ?? '' ) || true === ( $field['hidden'] ?? false ), "{$key} 應隱藏" );
		$this->assertFalse( (bool) ( $field['required'] ?? false ), "{$key} 應非必填" );
	}

	/**
	 * 斷言傳統結帳欄位「顯示且必填」
	 *
	 * @param array<string, array<string, mixed>> $fields 帳單欄位
	 * @param string                              $key    完整 key
	 */
	private function assert_classic_visible_required( array $fields, string $key ): void {
		$this->assertArrayHasKey( $key, $fields, "{$key} 應顯示" );
		$this->assertNotSame( 'hidden', $fields[ $key ]['type'] ?? '', "{$key} 不應是 hidden 型態" );
		$this->assertTrue( (bool) ( $fields[ $key ]['required'] ?? false ), "{$key} 應必填" );
	}

	/**
	 * 斷言區塊結帳 TW locale 欄位「隱藏且非必填」
	 *
	 * @param string $field 欄位（不含前綴）
	 */
	private function assert_block_hidden( string $field ): void {
		$def = $this->block_locale_field( $field );
		$this->assertTrue( $def['hidden'] ?? false, "區塊 TW locale 的 {$field} 應 hidden=true" );
		$this->assertFalse( $def['required'] ?? true, "區塊 TW locale 的 {$field} 應 required=false" );
	}

	/**
	 * 斷言區塊結帳 TW locale 欄位未被隱藏（顯示；必填性維持 WC 原樣，不被設為 false）
	 *
	 * @param string $field         欄位
	 * @param bool   $must_required 是否要求仍為必填
	 */
	private function assert_block_visible( string $field, bool $must_required ): void {
		$def = $this->block_locale_field( $field );
		$this->assertNotSame( true, $def['hidden'] ?? null, "區塊 TW locale 的 {$field} 不應 hidden" );
		if ( $must_required ) {
			$this->assertNotSame( false, $def['required'] ?? null, "區塊 TW locale 的 {$field} 不應被設為非必填" );
		}
	}

	/**
	 * 斷言兩種結帳型態皆「地址未被隱藏」
	 */
	private function assert_address_not_hidden_in_both(): void {
		$fields = $this->classic_billing_fields();
		foreach ( [ 'country', 'state', 'city', 'postcode', 'address_1' ] as $f ) {
			$this->assert_classic_visible_required( $fields, "billing_{$f}" );
		}
		$this->assertArrayHasKey( 'billing_address_2', $fields );

		foreach ( self::BLOCK_HIDDEN_FIELDS as $f ) {
			$this->assert_block_visible( $f, 'address_2' !== $f );
		}
		$this->assert_block_visible( 'country', true );
	}

	// ========== smoke ==========

	/**
	 * 場景：三個開關皆關閉時欄位不變（原生 WC 行為特徵例子，事先即為綠）
	 *
	 * @group smoke
	 */
	public function test_三個開關皆關閉時欄位不變(): void {
		$this->setup_cart_with( [ [ '線上課程A', 1200.0, true, false ] ] );
		$this->set_checkout_settings(
			[
				'last_name_first'      => 'no',
				'tw_address'           => 'no',
				'virtual_hide_address' => 'no',
			]
		);

		$fields = $this->classic_billing_fields();

		$this->assertSame( 10, (int) $fields['billing_first_name']['priority'] );
		$this->assertTrue( (bool) $fields['billing_first_name']['required'] );
		$this->assertSame( 20, (int) $fields['billing_last_name']['priority'] );
		$this->assertTrue( (bool) $fields['billing_last_name']['required'] );
		$this->assertSame( 50, (int) $fields['billing_address_1']['priority'] );
		$this->assertTrue( (bool) $fields['billing_address_1']['required'] );

		// state 為文字輸入：TW 沒有縣市清單
		$this->assertFalse( \WC()->countries->get_states( 'TW' ), '關閉時 TW 的 state 應為文字輸入（無縣市清單）' );

		// 區塊結帳 TW locale 也沒有被覆寫姓名排序 / 隱藏任何欄位
		$locale = $this->block_country_data()['locale'] ?? [];
		$this->assertNotSame( 10, $locale['last_name']['index'] ?? null, '關閉時區塊 TW 的 last_name 不應被調成 10' );
		$this->assertNotSame( true, $locale['address_1']['hidden'] ?? null );
	}

	// ========== happy：姓名排序 ==========

	/**
	 * Outline：<結帳型態>結帳、國家 <國家> 的姓名欄位順序（傳統 US 為原生特徵例子，事先即為綠）
	 *
	 * @group happy
	 * @dataProvider provide_name_order
	 *
	 * @param string $type          結帳型態（傳統 / 區塊）
	 * @param string $country       國家
	 * @param int    $last_priority 姓排序
	 * @param int    $first_priority 名排序
	 */
	public function test_姓在前名在後_姓名欄位順序( string $type, string $country, int $last_priority, int $first_priority ): void {
		$this->set_checkout_settings( [ 'last_name_first' => 'yes' ] );

		if ( '傳統' === $type ) {
			$fields = \WC()->countries->get_address_fields( $country, 'billing_' );
			$this->assertSame( $last_priority, (int) ( $fields['billing_last_name']['priority'] ?? -1 ), '傳統 last_name 排序' );
			$this->assertSame( $first_priority, (int) ( $fields['billing_first_name']['priority'] ?? -1 ), '傳統 first_name 排序' );
			return;
		}

		$this->assertSame( $last_priority, $this->block_locale_field( 'last_name', $country )['index'] ?? null, '區塊 last_name index' );
		$this->assertSame( $first_priority, $this->block_locale_field( 'first_name', $country )['index'] ?? null, '區塊 first_name index' );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: int, 3: int}>
	 */
	public static function provide_name_order(): array {
		return [
			'傳統 TW' => [ '傳統', 'TW', 10, 20 ],
			'區塊 TW' => [ '區塊', 'TW', 10, 20 ],
			'傳統 US' => [ '傳統', 'US', 20, 10 ],
		];
	}

	// ========== happy：縣市下拉 ==========

	/**
	 * Outline：<結帳型態>結帳的台灣縣市選項（22 個，key = label = 中文全名）
	 *
	 * @group happy
	 * @dataProvider provide_checkout_types
	 *
	 * @param string $type 結帳型態
	 */
	public function test_台灣縣市下拉_22個選項( string $type ): void {
		$this->set_checkout_settings( [ 'tw_address' => 'yes' ] );

		if ( '傳統' === $type ) {
			$states = \WC()->countries->get_states( 'TW' );
		} else {
			$states = $this->block_country_data()['states'] ?? false;
		}

		$this->assertIsArray( $states, "{$type}結帳的 TW 應提供縣市下拉選項" );
		$this->assertCount( 22, $states );

		foreach ( [ '臺北市', '新北市', '臺中市', '高雄市', '連江縣' ] as $county ) {
			$this->assertArrayHasKey( $county, $states, "縣市 key 應為中文全名：{$county}" );
			$this->assertSame( $county, $states[ $county ], "縣市 label 應等於 key：{$county}" );
		}
		foreach ( array_keys( $states ) as $name ) {
			$this->assertStringNotContainsString( '台', (string) $name, '縣市名稱應使用「臺」' );
		}
		$this->assertEqualsCanonicalizing( self::TW_COUNTIES, array_keys( $states ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_checkout_types(): array {
		return [
			'傳統' => [ '傳統' ],
			'區塊' => [ '區塊' ],
		];
	}

	// ========== happy：鄉鎮對照資料 ==========

	/**
	 * 場景：傳統結帳頁取得鄉鎮郵遞區號對照
	 *
	 * @group happy
	 */
	public function test_傳統結帳頁取得鄉鎮郵遞區號對照(): void {
		$this->set_checkout_settings( [ 'tw_address' => 'yes' ] );
		$this->go_to_checkout_page( '[woocommerce_checkout]' );
		$this->reset_frontend_assets();

		$this->run_wp_enqueue_scripts();

		$this->assertTrue( \wp_script_is( 'pc-classic-tw-address', 'enqueued' ), '傳統結帳頁應 enqueue pc-classic-tw-address' );

		$data = $this->localized_tw_address_data();
		$this->assertNotNull( $data, '應 localize pc_tw_address_data' );
		$counties = $data['counties'] ?? [];

		$this->assertSame( '100', (string) ( $counties['臺北市']['中正區'] ?? '' ) );
		$this->assertSame( '110', (string) ( $counties['臺北市']['信義區'] ?? '' ) );
		$this->assertSame( '209', (string) ( $counties['連江縣']['南竿鄉'] ?? '' ) );
		$this->assertCount( 22, $counties, '對照資料應涵蓋 22 縣市' );
	}

	/**
	 * 傳統結帳頁 enqueue 條件：開關 yes 才載入（no 為負向例子，事先即為綠）
	 *
	 * @group edge
	 * @dataProvider provide_classic_enqueue_switch
	 *
	 * @param string $switch   tw_address 開關
	 * @param bool   $expected 是否 enqueue
	 */
	public function test_傳統結帳頁依開關決定是否載入鄉鎮對照( string $switch, bool $expected ): void {
		$this->set_checkout_settings( [ 'tw_address' => $switch ] );
		$this->go_to_checkout_page( '[woocommerce_checkout]' );
		$this->reset_frontend_assets();

		$this->run_wp_enqueue_scripts();

		$this->assertSame( $expected, \wp_script_is( 'pc-classic-tw-address', 'enqueued' ) );
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function provide_classic_enqueue_switch(): array {
		return [
			'開關 yes 載入' => [ 'yes', true ],
			'開關 no 不載入' => [ 'no', false ],
		];
	}

	/**
	 * 場景：區塊結帳頁不載入鄉鎮對照資料，city 為文字輸入（縣市下拉仍生效）
	 *
	 * @group happy
	 */
	public function test_區塊結帳頁不載入鄉鎮對照資料(): void {
		$this->set_checkout_settings( [ 'tw_address' => 'yes' ] );
		$this->go_to_checkout_page( '<!-- wp:woocommerce/checkout /-->' );
		$this->reset_frontend_assets();

		$this->run_wp_enqueue_scripts();

		$this->assertFalse( \wp_script_is( 'pc-classic-tw-address', 'enqueued' ), '區塊結帳頁不應 enqueue 傳統鄉鎮腳本' );
		$this->assertNull( $this->localized_tw_address_data(), '區塊結帳頁不應有鄉鎮對照資料' );

		// 同時確認 tw_address 確實已生效（縣市下拉 22 個），否則本例不具判別力
		$data = $this->block_country_data();
		$this->assertCount( 22, $data['states'] ?? [], '區塊結帳應有 22 縣市下拉' );
		// city 維持文字輸入：locale 不應提供 options
		$this->assertArrayNotHasKey( 'options', $data['locale']['city'] ?? [], '區塊 city 應維持文字輸入' );
	}

	// ========== happy：虛擬商品隱藏地址 ==========

	/**
	 * Outline：<結帳型態>結帳、購物車<購物車>時的帳單地址欄位（「含實體 / 可下載實體」為原生特徵例子，事先即為綠）
	 *
	 * @group happy
	 * @dataProvider provide_virtual_hide_rows
	 *
	 * @param string $type          結帳型態
	 * @param string $name2         商品 2 名稱
	 * @param bool   $virtual2      商品 2 是否虛擬
	 * @param bool   $downloadable2 商品 2 是否可下載
	 * @param bool   $expect_hidden 是否預期隱藏地址
	 */
	public function test_虛擬隱藏地址_結帳型態與購物車( string $type, string $name2, bool $virtual2, bool $downloadable2, bool $expect_hidden ): void {
		$this->setup_cart_with(
			[
				[ '線上課程A', 1200.0, true, false ],
				[ $name2, 300.0, $virtual2, $downloadable2 ],
			]
		);
		$this->set_checkout_settings( [ 'virtual_hide_address' => 'yes' ] );

		if ( '傳統' === $type ) {
			$fields = $this->classic_billing_fields();

			if ( $expect_hidden ) {
				foreach ( [ 'country', 'state', 'city', 'postcode', 'address_1', 'address_2' ] as $f ) {
					$this->assert_classic_hidden( $fields, "billing_{$f}" );
				}
				// 保留欄位
				foreach ( [ 'first_name', 'last_name', 'email', 'company' ] as $f ) {
					$this->assertArrayHasKey( "billing_{$f}", $fields, "billing_{$f} 應保留" );
				}
			} else {
				foreach ( [ 'country', 'state', 'city', 'postcode', 'address_1' ] as $f ) {
					$this->assert_classic_visible_required( $fields, "billing_{$f}" );
				}
				$this->assertArrayHasKey( 'billing_address_2', $fields, 'address_2 應顯示' );
			}

			$this->assert_classic_visible_required( $fields, 'billing_phone' );
			return;
		}

		// 區塊
		if ( $expect_hidden ) {
			foreach ( self::BLOCK_HIDDEN_FIELDS as $f ) {
				$this->assert_block_hidden( $f );
			}
		} else {
			foreach ( self::BLOCK_HIDDEN_FIELDS as $f ) {
				$this->assert_block_visible( $f, 'address_2' !== $f );
			}
		}
		// country 的 locale 一律不可設 hidden（D15：改以 CSS 隱藏）
		$this->assert_block_visible( 'country', true );

		// phone 仍顯示且必填
		$this->assertNotSame( true, $this->block_locale_field( 'phone' )['hidden'] ?? null, '區塊 phone 不應 hidden' );
		$this->assertSame( 'required', CartCheckoutUtils::get_phone_field_visibility(), 'phone 應為必填' );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: bool, 4: bool}>
	 */
	public static function provide_virtual_hide_rows(): array {
		return [
			'傳統 全虛擬'   => [ '傳統', '電子書B', true, true, true ],
			'區塊 全虛擬'   => [ '區塊', '電子書B', true, true, true ],
			'傳統 含實體'   => [ '傳統', '帆布袋C', false, false, false ],
			'區塊 含實體'   => [ '區塊', '帆布袋C', false, false, false ],
			'傳統 可下載實體' => [ '傳統', '原聲帶CD', false, true, false ],
		];
	}

	/**
	 * Outline：區塊結帳隱藏國家欄位的 CSS 只在「區塊結帳頁 + 開關 yes + 全虛擬」輸出（不輸出的列為負向例子，事先即為綠）
	 *
	 * @group happy
	 * @dataProvider provide_country_css_rows
	 *
	 * @param string $type      結帳型態
	 * @param string $switch    virtual_hide_address 開關
	 * @param bool   $virtual2  商品 2 是否虛擬（可下載）
	 * @param bool   $expected  是否應輸出 CSS
	 */
	public function test_區塊結帳國家欄位CSS輸出條件( string $type, string $switch, bool $virtual2, bool $expected ): void {
		$this->setup_cart_with(
			[
				[ '線上課程A', 1200.0, true, false ],
				[ '電子書B', 300.0, $virtual2, true ],
			]
		);
		$this->set_checkout_settings( [ 'virtual_hide_address' => $switch ] );

		$this->go_to_checkout_page( '傳統' === $type ? '[woocommerce_checkout]' : '<!-- wp:woocommerce/checkout /-->' );
		$this->reset_frontend_assets();

		$html = $this->render_frontend_output();

		$has_css = 1 === preg_match(
			'/' . preg_quote( self::COUNTRY_CSS_SELECTOR, '/' ) . '\s*\{[^}]*display\s*:\s*none\s*!important/',
			$html
		);
		$this->assertSame( $expected, $has_css, $expected ? '應輸出隱藏 country 的 CSS' : '不應輸出隱藏 country 的 CSS' );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: bool, 3: bool}>
	 */
	public static function provide_country_css_rows(): array {
		return [
			'區塊 yes 全虛擬 輸出'  => [ '區塊', 'yes', true, true ],
			'區塊 yes 含實體 不輸出' => [ '區塊', 'yes', false, false ],
			'區塊 no 全虛擬 不輸出'  => [ '區塊', 'no', true, false ],
			'傳統 yes 全虛擬 不輸出' => [ '傳統', 'yes', true, false ],
		];
	}

	// ========== edge ==========

	/**
	 * F2 縣市下拉 + F4 虛擬隱藏同時開、全虛擬：state 隱藏且非必填（隱藏最後套用）
	 *
	 * @group edge
	 * @dataProvider provide_checkout_types
	 *
	 * @param string $type 結帳型態
	 */
	public function test_縣市下拉與虛擬隱藏同時開_全虛擬時state隱藏且非必填( string $type ): void {
		$this->setup_cart_with( [ [ '線上課程A', 1200.0, true, false ] ] );
		$this->set_checkout_settings(
			[
				'tw_address'           => 'yes',
				'virtual_hide_address' => 'yes',
			]
		);

		if ( '傳統' === $type ) {
			$this->assert_classic_hidden( $this->classic_billing_fields(), 'billing_state' );
			return;
		}

		$this->assert_block_hidden( 'state' );
		// 縣市下拉資料仍存在（F2 生效），但被 F4 隱藏且非必填
		$this->assertCount( 22, $this->block_country_data()['states'] ?? [] );
	}

	/**
	 * F2 + F4 同時開、含實體商品：state 為必填下拉（F4 不誤殺）
	 *
	 * @group edge
	 */
	public function test_縣市下拉與虛擬隱藏同時開_含實體時state維持必填(): void {
		$this->setup_cart_with( [ [ '帆布袋C', 300.0, false, false ] ] );
		$this->set_checkout_settings(
			[
				'tw_address'           => 'yes',
				'virtual_hide_address' => 'yes',
			]
		);

		$this->assertCount( 22, \WC()->countries->get_states( 'TW' ) ?: [] );
		$this->assert_classic_visible_required( $this->classic_billing_fields(), 'billing_state' );
		$this->assert_block_visible( 'state', true );
	}

	/**
	 * 空購物車視為非全虛擬：地址欄位不隱藏（原生特徵例子，事先即為綠）
	 *
	 * @group edge
	 */
	public function test_空購物車視為非全虛擬(): void {
		$this->init_cart_session();
		$this->set_customer_country( 'TW' );
		$this->assertTrue( \WC()->cart->is_empty() );
		$this->set_checkout_settings( [ 'virtual_hide_address' => 'yes' ] );

		$this->assert_address_not_hidden_in_both();
	}

	/**
	 * 商品已刪除視為非全虛擬：購物車內有品項但商品已不存在（data 為 false），不可被判成全虛擬
	 * （原生特徵例子，事先即為綠）
	 *
	 * @group edge
	 * @dataProvider provide_deleted_product_carts
	 *
	 * @param bool $with_virtual 是否同時有一個正常的虛擬商品
	 */
	public function test_商品已刪除視為非全虛擬( bool $with_virtual ): void {
		$this->init_cart_session();
		$this->set_customer_country( 'TW' );

		$contents = [];
		if ( $with_virtual ) {
			$product = $this->create_product( '線上課程A', 1200.0, true );
			$this->add_to_cart( [ [ $product, 1 ] ] );
			$contents = \WC()->cart->get_cart_contents();
		}
		$contents['deleteditem0000000000000000000000'] = [
			'key'          => 'deleteditem0000000000000000000000',
			'product_id'   => 99999999,
			'variation_id' => 0,
			'variation'    => [],
			'quantity'     => 1,
			'data'         => false,
		];
		\WC()->cart->set_cart_contents( $contents );

		$this->set_checkout_settings( [ 'virtual_hide_address' => 'yes' ] );

		$this->assert_address_not_hidden_in_both();
	}

	/**
	 * @return array<string, array{0: bool}>
	 */
	public static function provide_deleted_product_carts(): array {
		return [
			'只有已刪除商品'      => [ false ],
			'虛擬商品 + 已刪除商品' => [ true ],
		];
	}
}
