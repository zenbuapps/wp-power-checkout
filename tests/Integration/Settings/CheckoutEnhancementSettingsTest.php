<?php
/**
 * 結帳體驗強化設定 REST 整合測試（第 1 階段 Red）
 *
 * 對應規格：specs/features/settings/checkout-enhancement-settings.feature
 * 端點（重用既有，不新增）：GET / POST /wp-json/power-checkout/v1/settings/checkout_enhancement
 *   - 虛擬 provider id = checkout_enhancement，設定存 wp_options `woocommerce_checkout_enhancement_settings`
 *   - 權限沿用 ApiBase 預設（manage_options 或 manage_woocommerce）
 *
 * 執行指令（只跑本檔；--filter 要帶路徑，否則 0 收集）：
 *   bash "<scratchpad>/gate.sh" ut tests/Integration/Settings/CheckoutEnhancementSettingsTest.php
 *   或 npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Settings/CheckoutEnhancementSettingsTest.php --no-coverage"
 *
 * 場景對照（feature -> 測試方法）：
 *   規則「權限」Outline 3 例          -> test_安全_訪客讀取被拒回401 / 訪客儲存被拒回401 / 一般顧客儲存被拒回403
 *   規則「預設值」首次讀取             -> test_快樂_從未儲存過設定時讀取回傳全部預設值
 *   規則「合併儲存」部分更新           -> test_快樂_部分更新只改送出的鍵且回應儲存成功
 *   規則「鍵級驗證」Outline 4 例       -> test_錯誤_送出不合法值時維持原值（dataProvider 4 例）
 *   規則「鍵級驗證」（其餘合法鍵照存） -> test_錯誤_不合法鍵與合法鍵同時送出時合法鍵照常儲存
 *   規則「文案消毒」script 移除        -> test_安全_文案中的script標籤被移除後儲存
 *   計畫第 1 階段 edge / ASM-1         -> test_邊緣_未知鍵與provider_id不寫入option / 文案為空時保留原值（dataProvider）
 *   計畫錯誤處理登記表                 -> test_邊緣_option損毀為非陣列時讀取回傳預設值
 */

declare( strict_types=1 );

namespace Tests\Integration\Settings;

use Tests\Integration\TestCase;

/**
 * 結帳強化設定 REST 測試類別
 *
 * @group checkout
 * @group settings
 */
final class CheckoutEnhancementSettingsTest extends TestCase {

	/** 設定 option 名稱 */
	private const OPTION = 'woocommerce_checkout_enhancement_settings';

	/** 端點 */
	private const ROUTE = '/power-checkout/v1/settings/checkout_enhancement';

	/**
	 * 預設值（對齊 specs/erm.dbml 1.5.0 wp_options_checkout_enhancement_settings）
	 * 刻意寫在測試內，不依賴尚未實作的 DTO。
	 *
	 * @var array<string, string>
	 */
	private const DEFAULTS = [
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

	public function set_up(): void {
		parent::set_up();
		\delete_option( self::OPTION );
		// 讓每個測試重新建立 REST server，避免路由 / 請求狀態殘留
		$GLOBALS['wp_rest_server'] = null;
	}

	public function tear_down(): void {
		\delete_option( self::OPTION );
		\wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	// ========== Helper ==========

	/** 背景：管理員已登入（rest_do_request 不經 cookie nonce 檢查，權限由 permission_callback 判定） */
	private function login_as_admin(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		\wp_set_current_user( $admin_id );
	}

	/** 目前身分為「一般顧客」 */
	private function login_as_customer(): void {
		$customer_id = self::factory()->user->create( [ 'role' => 'customer' ] );
		\wp_set_current_user( $customer_id );
	}

	/**
	 * 發送請求至結帳強化設定端點
	 *
	 * @param string               $method GET|POST
	 * @param array<string, mixed> $body   POST 本文（JSON）
	 */
	private function send( string $method, array $body = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, self::ROUTE );
		if ( 'POST' === $method ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) \wp_json_encode( $body ) );
		}
		return \rest_do_request( $request );
	}

	/**
	 * 取出回應中的 data 欄位
	 *
	 * @return array<string, mixed>
	 */
	private function response_data( \WP_REST_Response $response ): array {
		$payload = $response->get_data();
		$this->assertIsArray( $payload, '回應本文應為陣列' );
		$this->assertArrayHasKey( 'data', $payload, '回應應含 data' );
		$this->assertIsArray( $payload['data'], '回應 data 應為陣列' );
		return $payload['data'];
	}

	/**
	 * 取得目前寫入的 option（原始值）
	 *
	 * @return array<string, mixed>
	 */
	private function stored(): array {
		$stored = \get_option( self::OPTION, [] );
		return \is_array( $stored ) ? $stored : [];
	}

	// ========== 安全性（Security）：權限 ==========

	/**
	 * Rule 前置（狀態）：訪客 GET -> 401（此例在實作前就會綠：ApiBase 預設權限已拒絕）
	 *
	 * @test
	 * @group security
	 */
	public function test_安全_訪客讀取被拒回401(): void {
		\wp_set_current_user( 0 );

		$response = $this->send( 'GET' );

		$this->assertSame( 401, $response->get_status() );
	}

	/**
	 * Rule 前置（狀態）：訪客 POST -> 401（此例在實作前就會綠）
	 *
	 * @test
	 * @group security
	 */
	public function test_安全_訪客儲存被拒回401(): void {
		\wp_set_current_user( 0 );

		$response = $this->send( 'POST', [ 'tw_address' => 'yes' ] );

		$this->assertSame( 401, $response->get_status() );
		$this->assertArrayNotHasKey( 'tw_address', $this->stored(), '被拒後不得寫入 option' );
	}

	/**
	 * Rule 前置（狀態）：一般顧客 POST -> 403（此例在實作前就會綠）
	 *
	 * @test
	 * @group security
	 */
	public function test_安全_一般顧客儲存被拒回403(): void {
		$this->login_as_customer();

		$response = $this->send( 'POST', [ 'tw_address' => 'yes' ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertArrayNotHasKey( 'tw_address', $this->stored(), '被拒後不得寫入 option' );
	}

	// ========== 快樂路徑（Happy） ==========

	/**
	 * Rule 後置（回應）：從未儲存過設定時，讀取回傳全部預設值（開關皆為 no）
	 *
	 * @test
	 * @group happy
	 */
	public function test_快樂_從未儲存過設定時讀取回傳全部預設值(): void {
		$this->login_as_admin();
		$this->assertFalse( \get_option( self::OPTION, false ), '前置：option 不存在' );

		$response = $this->send( 'GET' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals( self::DEFAULTS, $this->response_data( $response ), 'data 應為 10 鍵預設值' );
	}

	/**
	 * Rule 後置（狀態）：只開啟手機檢查與免運提示，其餘維持原值；message 為「儲存成功」
	 *
	 * @test
	 * @group happy
	 */
	public function test_快樂_部分更新只改送出的鍵且回應儲存成功(): void {
		$this->login_as_admin();
		\update_option(
			self::OPTION,
			[
				'last_name_first'     => 'yes',
				'tw_phone_validation' => 'no',
				'free_shipping_hint'  => 'no',
			]
		);

		$response = $this->send(
			'POST',
			[
				'tw_phone_validation' => 'yes',
				'free_shipping_hint'  => 'yes',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertIsArray( $payload );
		$this->assertSame( '儲存成功', $payload['message'] ?? null );

		$stored = $this->stored();
		$this->assertSame( 'yes', $stored['last_name_first'] ?? null, '未送出的鍵保留原值' );
		$this->assertSame( 'yes', $stored['tw_phone_validation'] ?? null );
		$this->assertSame( 'yes', $stored['free_shipping_hint'] ?? null );

		// api.yml：回應 data 為更新後的完整設定（10 鍵，未儲存的鍵以預設值補齊）
		$expected = \array_merge(
			self::DEFAULTS,
			[
				'last_name_first'     => 'yes',
				'tw_phone_validation' => 'yes',
				'free_shipping_hint'  => 'yes',
			]
		);
		$this->assertEquals( $expected, $this->response_data( $response ), '回應 data 應為完整 10 鍵' );
	}

	// ========== 錯誤處理（Error） ==========

	/**
	 * 不合法鍵的 Scenario Outline 例子
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function 不合法值例子(): array {
		return [
			'開關值為 true（非 yes/no）'  => [ 'tw_address', 'no', 'true' ],
			'背景色碼為顏色名稱 red'       => [ 'free_shipping_bg_color', '#d36f6f', 'red' ],
			'文字色碼為 3 碼 #fff'         => [ 'free_shipping_text_color', '#ffffff', '#fff' ],
			'未達門檻文案缺少 {price}'     => [ 'free_shipping_text_left', '再買 {price} 元即可享免運', '快達免運了' ],
		];
	}

	/**
	 * Rule 前置（參數）Scenario Outline：送出不合法的 <key> 時維持原值（仍回 200）
	 *
	 * @test
	 * @group error
	 * @dataProvider 不合法值例子
	 */
	public function test_錯誤_送出不合法值時維持原值( string $key, string $original, string $invalid ): void {
		$this->login_as_admin();
		\update_option( self::OPTION, [ $key => $original ] );

		$response = $this->send( 'POST', [ $key => $invalid ] );

		$this->assertSame( 200, $response->get_status(), '不合法鍵被忽略，仍回 200' );
		$this->assertSame( $original, $this->stored()[ $key ] ?? null, "option 的 {$key} 應維持原值" );
		$this->assertSame( $original, $this->response_data( $response )[ $key ] ?? null, "回應 data 的 {$key} 應為原值" );
	}

	/**
	 * Rule 前置（參數）：不合法的鍵忽略並維持原值，其餘合法鍵照常儲存
	 *
	 * @test
	 * @group error
	 */
	public function test_錯誤_不合法鍵與合法鍵同時送出時合法鍵照常儲存(): void {
		$this->login_as_admin();

		$response = $this->send(
			'POST',
			[
				'tw_address'          => 'true',
				'tw_phone_validation' => 'yes',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$stored = $this->stored();
		$this->assertSame( 'yes', $stored['tw_phone_validation'] ?? null, '合法鍵照常儲存' );
		$this->assertNotSame( 'true', $stored['tw_address'] ?? null, '不合法值不得寫入' );

		$data = $this->response_data( $response );
		$this->assertSame( 'no', $data['tw_address'] ?? null, '不合法鍵維持預設原值' );
		$this->assertSame( 'yes', $data['tw_phone_validation'] ?? null );
	}

	// ========== 安全性（Security）：消毒 ==========

	/**
	 * Rule 前置（參數）：文案中的 HTML 標籤於儲存時移除
	 * 另斷言回應 data 為完整 10 鍵（api.yml），確保不是僅靠既有 sanitize 就綠。
	 *
	 * @test
	 * @group security
	 */
	public function test_安全_文案中的script標籤被移除後儲存(): void {
		$this->login_as_admin();

		$response = $this->send(
			'POST',
			[ 'free_shipping_text_achieved' => '<script>alert(1)</script>免運啦' ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '免運啦', $this->stored()['free_shipping_text_achieved'] ?? null );

		$data = $this->response_data( $response );
		$this->assertSame( '免運啦', $data['free_shipping_text_achieved'] ?? null );
		$this->assertEquals(
			\array_merge( self::DEFAULTS, [ 'free_shipping_text_achieved' => '免運啦' ] ),
			$data,
			'回應 data 應為完整 10 鍵'
		);
	}

	// ========== 邊緣案例（Edge） ==========

	/**
	 * 計畫 DF1：未知鍵與 URL 路徑參數 provider_id 不寫入 option
	 *
	 * @test
	 * @group edge
	 */
	public function test_邊緣_未知鍵與provider_id不寫入option(): void {
		$this->login_as_admin();

		$response = $this->send(
			'POST',
			[
				'tw_address'  => 'yes',
				'unknown_key' => 'x',
				// 本文夾帶與路徑同值的 provider_id（JSON 本文參數優先於 URL 參數，故不可改成別的值，否則會改變路由目標）
				'provider_id' => 'checkout_enhancement',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$stored = $this->stored();
		$this->assertSame( 'yes', $stored['tw_address'] ?? null, '合法鍵照常儲存' );
		$this->assertArrayNotHasKey( 'unknown_key', $stored, '未知鍵不得寫入' );
		$this->assertArrayNotHasKey( 'provider_id', $stored, 'provider_id 不得寫入' );

		$data = $this->response_data( $response );
		$this->assertArrayNotHasKey( 'unknown_key', $data );
		$this->assertArrayNotHasKey( 'provider_id', $data );
	}

	/**
	 * ASM-1 的文案空字串例子（sanitize 後為空字串者視為不合法）
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function 文案空字串例子(): array {
		return [
			'已達門檻文案為空字串'             => [ 'free_shipping_text_achieved', '已享免運', '' ],
			'已達門檻文案僅空白'               => [ 'free_shipping_text_achieved', '已享免運', '   ' ],
			'已達門檻文案僅 HTML 標籤（消毒後為空）' => [ 'free_shipping_text_achieved', '已享免運', '<b></b>' ],
			'未達門檻文案為空字串'             => [ 'free_shipping_text_left', '再買 {price} 元即可享免運', '' ],
		];
	}

	/**
	 * ASM-1：文案 sanitize 後為空字串時視為不合法，保留原值
	 *
	 * @test
	 * @group edge
	 * @dataProvider 文案空字串例子
	 */
	public function test_邊緣_文案為空時保留原值( string $key, string $original, string $empty_like ): void {
		$this->login_as_admin();
		\update_option( self::OPTION, [ $key => $original ] );

		$response = $this->send( 'POST', [ $key => $empty_like ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $original, $this->stored()[ $key ] ?? null, "option 的 {$key} 應保留原值" );
		$this->assertSame( $original, $this->response_data( $response )[ $key ] ?? null );
	}

	/**
	 * 計畫錯誤處理登記表：option 損毀（非 array）時視為空，讀取回傳 DEFAULTS
	 *
	 * @test
	 * @group edge
	 */
	public function test_邊緣_option損毀為非陣列時讀取回傳預設值(): void {
		$this->login_as_admin();
		\update_option( self::OPTION, 'garbage' );

		$response = $this->send( 'GET' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertEquals( self::DEFAULTS, $this->response_data( $response ) );
	}
}
