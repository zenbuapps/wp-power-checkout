<?php
/**
 * F6：「差多少免運」提示（FreeShippingHintService / FreeShippingCalculator）整合測試（第 6 階段 Red）
 *
 * 對應規格：
 *   - specs/features/checkout/free-shipping-hint.feature、specs/features/checkout/句型.md（G1 G5-G12 / W5 / T13 T14）
 *   - specs/api.yml components.schemas.FreeShippingHint（achieved / diff / text / bg_color / text_color）
 *   - specs/open-issue/checkout-enhancement-implementation-plan.md 第 6 階段第 4 點、DF5、ASM-2、ASM-3
 *
 * 判讀方式：
 *   - 傳統結帳：FreeShippingHintService::render_row() echo `<tr class="pc-free-shipping-hint"><td ...>文案</td></tr>`；
 *     位置 Outline 以 do_action() 觸發 woocommerce_cart_totals_before_shipping（購物車頁）/ woocommerce_review_order_before_shipping（結帳頁），
 *     同時驗證 hook 接線。
 *   - 區塊結帳：FreeShippingHintService::store_api_data() 回傳 ['free_shipping_hint' => null | array]；
 *     「不顯示」Outline 與結構驗證走真實 rest_do_request(GET /wc/store/v1/cart) 的 extensions['power-checkout']。
 *   - 計算一律走真實 WC cart（CheckoutTestCase harness），不 mock WC。
 *
 * 介面假設（以 api.yml 為準，與派工訊息文字不同處）：
 *   - 區塊資料鍵為 achieved / diff / text / bg_color / text_color（派工訊息曾寫 reached / remaining，已改以 api.yml 為準）。
 *   - FreeShippingCalculator 為內部純計算，本檔不直接呼叫（其簽章規格未載明），一律透過 Service 公開介面驗證。
 *
 * 執行指令（只跑本檔）：
 *   bash "<scratchpad>/gate.sh" ut tests/Integration/Checkout/FreeShippingHintTest.php
 *   或 npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Checkout/FreeShippingHintTest.php --no-coverage"
 *
 * @group checkout
 */

declare( strict_types=1 );

namespace Tests\Integration\Checkout;

use J7\PowerCheckout\Domains\Checkout\Services\FreeShippingHintService;

/**
 * Class FreeShippingHintTest
 */
final class FreeShippingHintTest extends CheckoutTestCase {

	/** 提示列的 CSS class */
	private const ROW_CLASS = 'pc-free-shipping-hint';

	/** Store API cart extension namespace */
	private const EXTENSION_NAMESPACE = 'power-checkout';

	/** 購物車頁運送區上方 hook */
	private const HOOK_CART = 'woocommerce_cart_totals_before_shipping';

	/** 結帳頁運送區上方 hook */
	private const HOOK_CHECKOUT = 'woocommerce_review_order_before_shipping';

	// ========== Helper：背景與購物車 ==========

	/**
	 * 免運方式預設設定（背景：min_amount 1000、忽略折扣 no）
	 *
	 * @param array<string, string> $overrides 覆寫的方式設定（requires / min_amount / ignore_discounts）
	 * @param bool                  $enabled   是否啟用
	 * @return array{id: string, settings: array<string, string>, enabled: bool}
	 */
	private function free_shipping_method( array $overrides = [], bool $enabled = true ): array {
		return [
			'id'       => 'free_shipping',
			'settings' => array_merge(
				[
					'requires'         => 'min_amount',
					'min_amount'       => '1000',
					'ignore_discounts' => 'no',
				],
				$overrides
			),
			'enabled'  => $enabled,
		];
	}

	/**
	 * 建立背景：商店小數位 0、顯示價格含稅、結帳強化設定（預設開啟免運提示）、
	 * 運送區域「台灣」flat_rate 100 + 免運方式、購物車 session（運送國家 TW）
	 *
	 * @param array<string, string>                                                           $settings 要覆寫的結帳強化設定
	 * @param array<int, array{id: string, settings?: array<string, mixed>, enabled?: bool}>|null $fs_methods 免運方式清單（null 時為背景預設的單一 min_amount 1000）
	 */
	private function arrange( array $settings = [], ?array $fs_methods = null ): void {
		\update_option( 'woocommerce_currency', 'TWD' );
		\update_option( 'woocommerce_price_num_decimals', '0' );
		\update_option( 'woocommerce_prices_include_tax', 'yes' );
		\update_option( 'woocommerce_tax_display_cart', 'incl' );
		\update_option( 'woocommerce_calc_taxes', 'no' );

		$this->set_checkout_settings( array_merge( [ 'free_shipping_hint' => 'yes' ], $settings ) );

		$methods = array_merge(
			[
				[
					'id'       => 'flat_rate',
					'settings' => [ 'cost' => '100' ],
				],
			],
			$fs_methods ?? [ $this->free_shipping_method() ]
		);
		$this->create_free_shipping_zone( $methods );

		$this->init_cart_session();
		$this->set_customer_country( 'TW' );
	}

	/**
	 * G7：加入一個實體商品使購物車小計為指定金額
	 *
	 * @param float $subtotal 小計
	 */
	private function add_subtotal( float $subtotal ): void {
		$this->add_to_cart( [ [ $this->create_product( '帆布袋C', $subtotal, false ), 1 ] ] );
		\WC()->cart->calculate_totals();
	}

	/**
	 * G8：建立並套用優惠券（fixed_cart）
	 *
	 * @param string $code          優惠券代碼
	 * @param float  $discount      折扣金額
	 * @param bool   $free_shipping 是否含免運
	 */
	private function apply_coupon( string $code, float $discount, bool $free_shipping ): void {
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( (string) $discount );
		$coupon->set_free_shipping( $free_shipping );
		$coupon->save();

		$this->assertTrue( \WC()->cart->apply_coupon( $code ), "前置失敗：優惠券 {$code} 套用失敗" );
		\WC()->cart->calculate_totals();
		$this->assertEqualsWithDelta( $discount, (float) \WC()->cart->get_discount_total(), 0.01, "前置失敗：折扣總額應為 {$discount}" );
	}

	/**
	 * 傳統：直接呼叫 render_row() 並收集 echo 輸出
	 */
	private function render_row_html(): string {
		ob_start();
		try {
			FreeShippingHintService::render_row();
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * 傳統：觸發 hook 並收集輸出（驗證 hook 接線）
	 *
	 * @param string $hook hook 名稱
	 */
	private function hook_html( string $hook ): string {
		ob_start();
		try {
			\do_action( $hook );
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * 取出提示列顯示的純文字（去標籤、收斂空白）
	 *
	 * @param string $html 提示列 HTML
	 */
	private function hint_text( string $html ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', \wp_strip_all_tags( $html ) ) );
	}

	/**
	 * T13：免運提示應顯示指定文字（有提示列 class、純文字完全相等）
	 *
	 * @param string $expected 期望文字
	 * @param string $html     提示列 HTML
	 */
	private function assert_hint_shows( string $expected, string $html ): void {
		$this->assertStringContainsString( self::ROW_CLASS, $html, '應輸出免運提示列' );
		$this->assertSame( $expected, $this->hint_text( $html ) );
	}

	/**
	 * T13：不應顯示免運提示
	 *
	 * @param string $html 提示列 HTML
	 */
	private function assert_no_hint( string $html ): void {
		$this->assertStringNotContainsString( self::ROW_CLASS, $html, '不應輸出免運提示列' );
	}

	/**
	 * 區塊：Service 提供給 Store API 的資料中的 free_shipping_hint
	 *
	 * @return array<string, mixed>|null
	 */
	private function block_hint(): ?array {
		$data = FreeShippingHintService::store_api_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'free_shipping_hint', $data );
		$hint = $data['free_shipping_hint'];
		$this->assertTrue( null === $hint || is_array( $hint ), 'free_shipping_hint 應為 null 或 array' );
		return $hint;
	}

	/**
	 * 真實 rest_do_request(GET /wc/store/v1/cart)，回傳 extensions['power-checkout']
	 *
	 * @return array<string, mixed>
	 */
	private function store_api_extension(): array {
		$response = \rest_do_request( new \WP_REST_Request( 'GET', '/wc/store/v1/cart' ) );
		$this->assertSame( 200, $response->get_status(), 'Store API 讀取購物車應為 200：' . wp_json_encode( $response->get_data(), JSON_UNESCAPED_UNICODE ) );

		// Store API 的 extensions 為 stdClass；經 JSON 往返轉成關聯陣列（等同前端實際收到的內容）
		$data = json_decode( (string) \wp_json_encode( $response->get_data() ), true );
		$this->assertIsArray( $data );
		$extensions = $data['extensions'] ?? [];
		$this->assertIsArray( $extensions );
		$this->assertArrayHasKey( self::EXTENSION_NAMESPACE, $extensions, 'Store API cart extensions 應包含 namespace power-checkout' );

		$extension = $extensions[ self::EXTENSION_NAMESPACE ];
		$this->assertIsArray( $extension );
		return $extension;
	}

	// ========== smoke ==========

	/**
	 * Scenario：關閉時不顯示提示（開關 no；購物車小計 850 本應顯示「再買 150 元」）
	 * 走 hook 而非直接呼叫 Service，實作前即為綠（無任何 callback 輸出）；實作後驗證開關在執行期被判斷。
	 *
	 * @group smoke
	 */
	public function test_關閉時不顯示提示(): void {
		$this->arrange( [ 'free_shipping_hint' => 'no' ] );
		$this->add_subtotal( 850 );

		$this->assert_no_hint( $this->hook_html( self::HOOK_CART ) );
		$this->assert_no_hint( $this->hook_html( self::HOOK_CHECKOUT ) );
	}

	// ========== happy：位置 Outline ==========

	/**
	 * Outline：<結帳型態><頁面>顯示差額「再買 150 元即可享免運」
	 * 傳統：對應頁面的 hook 輸出提示列；區塊：store_api_data() 的 text
	 *
	 * @group happy
	 * @dataProvider provide_position
	 *
	 * @param string $type 結帳型態（傳統 / 區塊）
	 * @param string $page 頁面（購物車頁 / 結帳頁）
	 */
	public function test_顯示位置_各結帳型態與頁面顯示差額( string $type, string $page ): void {
		$this->arrange();
		$this->add_subtotal( 850 );

		if ( '傳統' === $type ) {
			$hook = '購物車頁' === $page ? self::HOOK_CART : self::HOOK_CHECKOUT;
			$this->assert_hint_shows( '再買 150 元即可享免運', $this->hook_html( $hook ) );
			return;
		}

		$hint = $this->block_hint();
		$this->assertNotNull( $hint, "區塊{$page}應帶出免運提示資料" );
		$this->assertSame( '再買 150 元即可享免運', $hint['text'] ?? null );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_position(): array {
		return [
			'傳統 購物車頁' => [ '傳統', '購物車頁' ],
			'傳統 結帳頁'  => [ '傳統', '結帳頁' ],
			'區塊 購物車頁' => [ '區塊', '購物車頁' ],
			'區塊 結帳頁'  => [ '區塊', '結帳頁' ],
		];
	}

	/**
	 * 區塊：真實 Store API GET /wc/store/v1/cart 帶出 free_shipping_hint，結構符合 api.yml FreeShippingHint
	 *
	 * @group happy
	 */
	public function test_區塊_StoreAPI_購物車回應帶出免運提示結構(): void {
		$this->arrange();
		$this->add_subtotal( 850 );

		$extension = $this->store_api_extension();
		$this->assertArrayHasKey( 'free_shipping_hint', $extension );

		$hint = $extension['free_shipping_hint'];
		$this->assertIsArray( $hint, '未達門檻時 free_shipping_hint 應為物件' );
		foreach ( [ 'achieved', 'diff', 'text', 'bg_color', 'text_color' ] as $key ) {
			$this->assertArrayHasKey( $key, $hint, "FreeShippingHint 缺少必填欄位 {$key}" );
		}
		$this->assertFalse( $hint['achieved'] );
		$this->assertEqualsWithDelta( 150, (float) $hint['diff'], 0.001 );
		$this->assertSame( '再買 150 元即可享免運', $hint['text'] );
		$this->assertSame( '#d36f6f', $hint['bg_color'], '預設底色' );
		$this->assertSame( '#ffffff', $hint['text_color'], '預設文字色' );
	}

	/**
	 * 區塊資料：已達門檻時 achieved=true、diff=0、text 為已達文案
	 *
	 * @group happy
	 */
	public function test_區塊資料_已達門檻結構(): void {
		$this->arrange();
		$this->add_subtotal( 1000 );

		$hint = $this->block_hint();
		$this->assertNotNull( $hint );
		$this->assertTrue( $hint['achieved'] ?? null );
		$this->assertEqualsWithDelta( 0, (float) ( $hint['diff'] ?? -1 ), 0.001, '已達時 diff 為 0' );
		$this->assertSame( '已享免運', $hint['text'] ?? null );
	}

	/**
	 * 自訂色碼與文案：傳統提示列帶入底色 / 文字色，區塊資料回傳相同值
	 *
	 * @group happy
	 */
	public function test_自訂文案與色碼套用於傳統與區塊(): void {
		$this->arrange(
			[
				'free_shipping_text_left'  => '還差 {price} 塊免運',
				'free_shipping_bg_color'   => '#112233',
				'free_shipping_text_color' => '#aabbcc',
			]
		);
		$this->add_subtotal( 850 );

		$html = $this->render_row_html();
		$this->assert_hint_shows( '還差 150 塊免運', $html );
		$this->assertStringContainsString( '#112233', $html, '底色應輸出於提示列' );
		$this->assertStringContainsString( '#aabbcc', $html, '文字色應輸出於提示列' );

		$hint = $this->block_hint();
		$this->assertNotNull( $hint );
		$this->assertSame( '#112233', $hint['bg_color'] ?? null );
		$this->assertSame( '#aabbcc', $hint['text_color'] ?? null );
		$this->assertSame( '還差 150 塊免運', $hint['text'] ?? null );
	}

	// ========== happy / edge：差額 Outline ==========

	/**
	 * Outline：小計 <小計>、折扣 <折扣>、忽略折扣 <忽略折扣> 時顯示 "<提示>"（含差額 = 0、負數邊界）
	 *
	 * @group edge
	 * @dataProvider provide_diff_cases
	 *
	 * @param int    $subtotal        小計
	 * @param int    $discount        折扣（0 代表未套用優惠券）
	 * @param string $ignore_discount 免運方式的「忽略折扣」設定（yes / no）
	 * @param string $expected        期望的提示文字
	 */
	public function test_差額計算_小計折扣與忽略折扣( int $subtotal, int $discount, string $ignore_discount, string $expected ): void {
		$this->arrange( [], [ $this->free_shipping_method( [ 'ignore_discounts' => $ignore_discount ] ) ] );
		$this->add_subtotal( (float) $subtotal );
		if ( $discount > 0 ) {
			$this->apply_coupon( 'SAVE', (float) $discount, false );
		}

		$this->assert_hint_shows( $expected, $this->render_row_html() );
	}

	/**
	 * @return array<string, array{0: int, 1: int, 2: string, 3: string}>
	 */
	public static function provide_diff_cases(): array {
		return [
			'850 無折扣 no'         => [ 850, 0, 'no', '再買 150 元即可享免運' ],
			'850 折扣100 no'       => [ 850, 100, 'no', '再買 250 元即可享免運' ],
			'850 折扣100 yes'      => [ 850, 100, 'yes', '再買 150 元即可享免運' ],
			'1000 無折扣 no（差額0）'   => [ 1000, 0, 'no', '已享免運' ],
			'1100 折扣200 no'      => [ 1100, 200, 'no', '再買 100 元即可享免運' ],
			'1100 折扣200 yes（負數）' => [ 1100, 200, 'yes', '已享免運' ],
			'250 無折扣 no'         => [ 250, 0, 'no', '再買 750 元即可享免運' ],
		];
	}

	/**
	 * Scenario：差額超過千元時以千分位顯示「再買 1,250 元即可享免運」
	 *
	 * @group edge
	 */
	public function test_差額超過千元時以千分位顯示(): void {
		$this->arrange( [], [ $this->free_shipping_method( [ 'min_amount' => '3000' ] ) ] );
		$this->add_subtotal( 1750 );

		$this->assert_hint_shows( '再買 1,250 元即可享免運', $this->render_row_html() );
	}

	// ========== edge：requires Outline ==========

	/**
	 * Outline：免運條件 <免運條件>、<優惠券情境> 時的結果（小計 850、門檻 1000）
	 *
	 * @group edge
	 * @dataProvider provide_requires_cases
	 *
	 * @param string      $requires        免運條件（both / coupon / either）
	 * @param bool        $has_free_coupon 是否已套用含免運的優惠券 FREESHIP（折扣 0 元）
	 * @param string|null $expected        期望提示文字；null 代表不應顯示
	 */
	public function test_免運條件_需優惠券的各種組合( string $requires, bool $has_free_coupon, ?string $expected ): void {
		$this->arrange( [], [ $this->free_shipping_method( [ 'requires' => $requires ] ) ] );
		$this->add_subtotal( 850 );
		if ( $has_free_coupon ) {
			$this->apply_coupon( 'FREESHIP', 0.0, true );
		}

		$html = $this->render_row_html();
		if ( null === $expected ) {
			$this->assert_no_hint( $html );
			$this->assertNull( $this->block_hint(), '區塊資料同樣不應帶出提示' );
			return;
		}
		$this->assert_hint_shows( $expected, $html );
	}

	/**
	 * @return array<string, array{0: string, 1: bool, 2: string|null}>
	 */
	public static function provide_requires_cases(): array {
		return [
			'both 未套用優惠券'   => [ 'both', false, null ],
			'both 已套用免運券'   => [ 'both', true, '再買 150 元即可享免運' ],
			'coupon 未套用優惠券' => [ 'coupon', false, null ],
			'either 已套用免運券' => [ 'either', true, '已享免運' ],
			'either 未套用優惠券' => [ 'either', false, '再買 150 元即可享免運' ],
		];
	}

	// ========== edge：多門檻 ==========

	/**
	 * Scenario：兩個免運方式（門檻 1000、900）取差額最小者 -> 再買 50 元
	 *
	 * @group edge
	 */
	public function test_多個免運門檻取差額最小者(): void {
		$this->arrange(
			[],
			[
				$this->free_shipping_method(),
				$this->free_shipping_method( [ 'min_amount' => '900' ] ),
			]
		);
		$this->add_subtotal( 850 );

		$this->assert_hint_shows( '再買 50 元即可享免運', $this->render_row_html() );
		$hint = $this->block_hint();
		$this->assertEqualsWithDelta( 50, (float) ( $hint['diff'] ?? -1 ), 0.001, '區塊資料 diff 也取最小者' );
	}

	/**
	 * Scenario：多個免運方式中已有達標者（門檻 800 已達、1000 未達）-> 已達者優先顯示「已享免運」
	 *
	 * @group edge
	 */
	public function test_多個免運門檻已達者優先顯示已達(): void {
		$this->arrange(
			[],
			[
				$this->free_shipping_method(),
				$this->free_shipping_method( [ 'min_amount' => '800' ] ),
			]
		);
		$this->add_subtotal( 850 );

		$this->assert_hint_shows( '已享免運', $this->render_row_html() );
		$hint = $this->block_hint();
		$this->assertTrue( $hint['achieved'] ?? null );
	}

	/**
	 * ASM-2：min_amount 為空字串或非數字（requires=min_amount）的免運方式不列入候選 -> 不顯示提示
	 *
	 * @group edge
	 * @dataProvider provide_invalid_min_amount
	 *
	 * @param string $min_amount 不合法的最低金額設定
	 */
	public function test_ASM2_最低金額不合法的免運方式不列入候選( string $min_amount ): void {
		$this->arrange( [], [ $this->free_shipping_method( [ 'min_amount' => $min_amount ] ) ] );
		$this->add_subtotal( 850 );

		$this->assert_no_hint( $this->render_row_html() );
		$this->assertNull( $this->block_hint() );
	}

	/**
	 * ASM-2：不合法的方式不影響同區其他合法門檻（1000 仍計算 -> 再買 150 元）
	 *
	 * @group edge
	 * @dataProvider provide_invalid_min_amount
	 *
	 * @param string $min_amount 不合法的最低金額設定
	 */
	public function test_ASM2_不合法方式不影響其他合法門檻( string $min_amount ): void {
		$this->arrange(
			[],
			[
				$this->free_shipping_method( [ 'min_amount' => $min_amount ] ),
				$this->free_shipping_method(),
			]
		);
		$this->add_subtotal( 850 );

		$this->assert_hint_shows( '再買 150 元即可享免運', $this->render_row_html() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_invalid_min_amount(): array {
		return [
			'空字串' => [ '' ],
			'非數字' => [ 'abc' ],
		];
	}

	// ========== edge：不顯示 Outline（Store API 真實回應） ==========

	/**
	 * Outline：<情境>時不顯示提示；區塊購物車 Store API 的 extensions['power-checkout']['free_shipping_hint'] 應為 null
	 * 傳統提示列同樣不輸出
	 *
	 * @group edge
	 * @dataProvider provide_hidden_scenarios
	 *
	 * @param string $scenario 情境名稱（全虛擬購物車 / 無免運費方式 / 購物車為空）
	 */
	public function test_不顯示提示的情境_StoreAPI為null( string $scenario ): void {
		match ( $scenario ) {
			'全虛擬購物車' => $this->arrange_virtual_only_cart(),
			'無免運費方式' => $this->arrange_disabled_free_shipping_cart(),
			default        => $this->arrange_empty_cart(),
		};

		$extension = $this->store_api_extension();
		$this->assertArrayHasKey( 'free_shipping_hint', $extension, 'extension 應固定帶出 free_shipping_hint 鍵' );
		$this->assertNull( $extension['free_shipping_hint'], "{$scenario}時 free_shipping_hint 應為 null" );

		$this->assert_no_hint( $this->render_row_html() );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_hidden_scenarios(): array {
		return [
			'全虛擬購物車' => [ '全虛擬購物車' ],
			'無免運費方式' => [ '無免運費方式' ],
			'購物車為空'  => [ '購物車為空' ],
		];
	}

	/** G6：購物車只有虛擬商品「線上課程A」單價 1200 */
	private function arrange_virtual_only_cart(): void {
		$this->arrange();
		$this->add_to_cart( [ [ $this->create_product( '線上課程A', 1200, true ), 1 ] ] );
		\WC()->cart->calculate_totals();
	}

	/** G12：「滿額免運」已停用，購物車小計為 850 元 */
	private function arrange_disabled_free_shipping_cart(): void {
		$this->arrange( [], [ $this->free_shipping_method( [], false ) ] );
		$this->add_subtotal( 850 );
	}

	/** G9：購物車為空 */
	private function arrange_empty_cart(): void {
		$this->arrange();
		\WC()->cart->calculate_totals();
	}

	/**
	 * 開關關閉：區塊資料與 Store API 同樣為 null（購物車小計 850 本應顯示）
	 *
	 * @group edge
	 */
	public function test_開關關閉時區塊資料為null(): void {
		$this->arrange( [ 'free_shipping_hint' => 'no' ] );
		$this->add_subtotal( 850 );

		$extension = $this->store_api_extension();
		$this->assertArrayHasKey( 'free_shipping_hint', $extension );
		$this->assertNull( $extension['free_shipping_hint'] );
		$this->assertNull( $this->block_hint() );
		$this->assert_no_hint( $this->render_row_html() );
	}

	// ========== error：never-throw ==========

	/**
	 * DF5 never-throw：購物車物件不存在時，傳統不輸出、區塊資料為 null，且不拋例外
	 *
	 * @group error
	 */
	public function test_購物車不存在時不拋例外且不輸出(): void {
		$this->arrange();
		\WC()->cart = null;

		$this->assert_no_hint( $this->render_row_html() );
		$this->assertNull( $this->block_hint() );
	}

	// ========== security ==========

	/**
	 * Scenario：文案含 HTML 實體（&lt;b&gt;）時以純文字輸出，HTML 不應包含 "<b>" 標籤
	 *
	 * @group security
	 */
	public function test_文案含HTML實體時以純文字輸出(): void {
		$this->arrange( [ 'free_shipping_text_left' => '差 {price} 元 &lt;b&gt;免運&lt;/b&gt;' ] );
		$this->add_subtotal( 850 );

		$html = $this->render_row_html();
		$this->assertStringContainsString( self::ROW_CLASS, $html );
		$this->assertStringContainsString( '差 150 元', $html, '文案仍應正常顯示並替換 {price}' );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '</b>', $html );
	}

	/**
	 * 設定值被繞過消毒直接寫入原始 HTML / script（未達與已達文案）時，傳統與區塊輸出皆已跳脫
	 *
	 * @group security
	 * @dataProvider provide_malicious_text
	 *
	 * @param string $payload 惡意文案片段（原始 HTML）
	 * @param string $needle  輸出中不應出現的原始標籤字串
	 */
	public function test_文案含原始HTML時輸出已跳脫( string $payload, string $needle ): void {
		$this->arrange(
			[
				'free_shipping_text_left'     => "差 {price} 元 {$payload}",
				'free_shipping_text_achieved' => "已享免運 {$payload}",
			]
		);
		$this->add_subtotal( 850 );

		// 未達
		$html = $this->render_row_html();
		$this->assertStringContainsString( '差 150 元', $html );
		$this->assertStringNotContainsString( $needle, $html, '傳統（未達）文案應已 esc_html' );
		$hint = $this->block_hint();
		$this->assertNotNull( $hint );
		$this->assertStringNotContainsString( $needle, (string) ( $hint['text'] ?? '' ), '區塊（未達）text 應已 esc_html' );

		// 已達
		\WC()->cart->empty_cart( false );
		$this->add_subtotal( 1000 );
		$html = $this->render_row_html();
		$this->assertStringContainsString( '已享免運', $html );
		$this->assertStringNotContainsString( $needle, $html, '傳統（已達）文案應已 esc_html' );
		$hint = $this->block_hint();
		$this->assertNotNull( $hint );
		$this->assertStringNotContainsString( $needle, (string) ( $hint['text'] ?? '' ), '區塊（已達）text 應已 esc_html' );
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function provide_malicious_text(): array {
		return [
			'b 標籤'        => [ '<b>免運</b>', '<b>' ],
			'script 標籤'   => [ '<script>alert(1)</script>', '<script' ],
			'img onerror' => [ '<img src=x onerror=alert(1)>', '<img' ],
		];
	}

	/**
	 * 色碼經 esc_attr：嘗試跳出 style 屬性 / 注入標籤的色碼值，輸出不得出現未跳脫的引號、標籤或事件屬性
	 *
	 * @group security
	 * @dataProvider provide_malicious_color
	 *
	 * @param string $payload 惡意色碼
	 */
	public function test_色碼輸出前經esc_attr( string $payload ): void {
		$this->arrange(
			[
				'free_shipping_bg_color'   => $payload,
				'free_shipping_text_color' => $payload,
			]
		);
		$this->add_subtotal( 850 );

		$html = $this->render_row_html();
		$this->assertStringContainsString( self::ROW_CLASS, $html );
		$this->assertStringNotContainsString( '<script', $html, '色碼不得注入 script 標籤' );
		$this->assertStringNotContainsString( '" onmouseover=', $html, '色碼不得跳出 style 屬性注入事件' );
		$this->assertStringNotContainsString( "' onmouseover=", $html, '色碼不得以單引號跳出 style 屬性' );
		$this->assertStringNotContainsString( '"><', $html, '色碼不得關閉屬性後注入標籤' );

		// 區塊資料的色碼會被前端寫入 style，不得含未跳脫的 < > "
		$hint = $this->block_hint();
		$this->assertNotNull( $hint );
		foreach ( [ 'bg_color', 'text_color' ] as $key ) {
			$this->assertDoesNotMatchRegularExpression( '/[<>"]/', (string) ( $hint[ $key ] ?? '' ), "區塊 {$key} 不得含未跳脫字元" );
		}
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function provide_malicious_color(): array {
		return [
			'跳出屬性注入事件' => [ 'red" onmouseover="alert(1)' ],
			'關閉標籤注入'   => [ '#fff"></td><script>alert(1)</script>' ],
			'單引號跳出'    => [ "red' onmouseover='alert(1)" ],
		];
	}
}
