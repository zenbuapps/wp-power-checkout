<?php
/**
 * CheckoutEnhancementSettingsDTO 純邏輯測試（第 1 階段 Red）
 *
 * 對應規格：specs/open-issue/checkout-enhancement-implementation-plan.md 第 1 階段 + 「新增（正式碼）」DTO 列
 *   PROVIDER_ID='checkout_enhancement'、DEFAULTS（10 鍵）、get_all()、is_on(string $key)、get_string(string $key)、
 *   merge_valid(array $input, array $stored): array（鍵級驗證，不合法鍵保留原值，未知鍵丟棄）
 *
 * 注意：計畫的 merge_valid 簽名為 (array $input, array $stored)，任務描述寫 merge_valid(array $input)；
 * 依指示以計畫為準，故本檔呼叫 merge_valid($input, $stored)。
 *
 * 規格未明訂 merge_valid 回傳是否已補預設值，故斷言一律以「DEFAULTS 疊上回傳值」的有效值為準，
 * 不鎖定回傳陣列是否含未送出的鍵（避免過度規格化）。
 *
 * 執行指令（只跑本檔）：
 *   bash "<scratchpad>/gate.sh" ut tests/Integration/Settings/CheckoutEnhancementSettingsDTOTest.php
 *   或 npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Settings/CheckoutEnhancementSettingsDTOTest.php --no-coverage"
 */

declare( strict_types=1 );

namespace Tests\Integration\Settings;

use J7\PowerCheckout\Domains\Checkout\DTOs\CheckoutEnhancementSettingsDTO;
use Tests\Integration\TestCase;

/**
 * 結帳強化設定 DTO 測試類別
 *
 * @group checkout
 * @group settings
 */
final class CheckoutEnhancementSettingsDTOTest extends TestCase {

	/** 設定 option 名稱 */
	private const OPTION = 'woocommerce_checkout_enhancement_settings';

	public function set_up(): void {
		parent::set_up();
		\delete_option( self::OPTION );
	}

	public function tear_down(): void {
		\delete_option( self::OPTION );
		parent::tear_down();
	}

	/**
	 * 取得 merge_valid 結果的「有效值」：DEFAULTS 疊上回傳值
	 *
	 * @param array<string, mixed> $merged merge_valid 回傳
	 * @return array<string, mixed>
	 */
	private function effective( array $merged ): array {
		return \array_merge( CheckoutEnhancementSettingsDTO::DEFAULTS, $merged );
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_PROVIDER_ID與DEFAULTS符合規格(): void {
		$this->assertSame( 'checkout_enhancement', CheckoutEnhancementSettingsDTO::PROVIDER_ID );
		$this->assertSame(
			[
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
			],
			CheckoutEnhancementSettingsDTO::DEFAULTS,
			'DEFAULTS 應為 erm.dbml 1.5.0 的 10 鍵預設值（鍵順序亦一致）'
		);
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_option不存在時get_all回傳全部預設值(): void {
		$this->assertSame( CheckoutEnhancementSettingsDTO::DEFAULTS, CheckoutEnhancementSettingsDTO::get_all() );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_未儲存時所有開關皆為關閉(): void {
		foreach ( [ 'last_name_first', 'tw_address', 'tw_phone_validation', 'virtual_hide_address', 'virtual_auto_complete', 'free_shipping_hint' ] as $key ) {
			$this->assertFalse( CheckoutEnhancementSettingsDTO::is_on( $key ), "{$key} 預設應為關閉" );
		}
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_is_on依option值判斷且get_string取得文案(): void {
		\update_option(
			self::OPTION,
			[
				'tw_address'                  => 'yes',
				'tw_phone_validation'         => 'no',
				'free_shipping_text_achieved' => '免運啦',
			]
		);

		$this->assertTrue( CheckoutEnhancementSettingsDTO::is_on( 'tw_address' ) );
		$this->assertFalse( CheckoutEnhancementSettingsDTO::is_on( 'tw_phone_validation' ) );
		$this->assertFalse( CheckoutEnhancementSettingsDTO::is_on( 'last_name_first' ), '未儲存的開關用預設值 no' );
		$this->assertSame( '免運啦', CheckoutEnhancementSettingsDTO::get_string( 'free_shipping_text_achieved' ) );
		$this->assertSame( '再買 {price} 元即可享免運', CheckoutEnhancementSettingsDTO::get_string( 'free_shipping_text_left' ), '未儲存的文案用預設值' );
	}

	// ========== 邊緣案例（Edge）：get_all ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_邊緣_get_all用預設值補齊未儲存的鍵並保留已儲存值(): void {
		\update_option( self::OPTION, [ 'last_name_first' => 'yes' ] );

		$all = CheckoutEnhancementSettingsDTO::get_all();

		$this->assertCount( 10, $all );
		$this->assertSame( 'yes', $all['last_name_first'] );
		$this->assertSame( 'no', $all['tw_address'] );
		$this->assertSame( '#d36f6f', $all['free_shipping_bg_color'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_邊緣_option損毀為非陣列時視為空並回傳預設值(): void {
		\update_option( self::OPTION, 'garbage' );

		$this->assertSame( CheckoutEnhancementSettingsDTO::DEFAULTS, CheckoutEnhancementSettingsDTO::get_all() );
		$this->assertFalse( CheckoutEnhancementSettingsDTO::is_on( 'tw_address' ) );
	}

	// ========== 邊緣案例（Edge）：merge_valid 開關 ==========

	/**
	 * 合法開關值
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function 合法開關值(): array {
		$cases = [];
		foreach ( [ 'last_name_first', 'tw_address', 'tw_phone_validation', 'virtual_hide_address', 'virtual_auto_complete', 'free_shipping_hint' ] as $key ) {
			foreach ( [ 'yes', 'no' ] as $value ) {
				$cases[ "{$key}={$value}" ] = [ $key, $value ];
			}
		}
		return $cases;
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider 合法開關值
	 */
	public function test_邊緣_merge_valid接受yes或no開關值( string $key, string $value ): void {
		// 儲存值取相反，確認是新值被寫入而非保留原值
		$stored = [ $key => 'yes' === $value ? 'no' : 'yes' ];

		$merged = CheckoutEnhancementSettingsDTO::merge_valid( [ $key => $value ], $stored );

		$this->assertSame( $value, $this->effective( $merged )[ $key ] );
	}

	/**
	 * 不合法開關值（嚴格 in_array(['yes','no'], true)；非 string 一律不合法）
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function 不合法開關值(): array {
		return [
			'字串 true' => [ 'true' ],
			'字串 1'    => [ '1' ],
			'大寫 YES'  => [ 'YES' ],
			'大寫 No'   => [ 'No' ],
			'前後空白'    => [ ' yes ' ],
			'空字串'     => [ '' ],
			'布林 true' => [ true ],
			'整數 1'    => [ 1 ],
			'null'    => [ null ],
			'陣列'      => [ [ 'yes' ] ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider 不合法開關值
	 */
	public function test_邊緣_merge_valid忽略不合法開關值並保留原值( mixed $invalid ): void {
		$merged = CheckoutEnhancementSettingsDTO::merge_valid( [ 'tw_address' => $invalid ], [ 'tw_address' => 'yes' ] );

		$this->assertSame( 'yes', $this->effective( $merged )['tw_address'], '儲存值為 yes，不合法輸入應保留 yes' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_邊緣_merge_valid不合法開關且無儲存值時回到預設值(): void {
		$merged = CheckoutEnhancementSettingsDTO::merge_valid( [ 'tw_address' => 'true' ], [] );

		$this->assertSame( 'no', $this->effective( $merged )['tw_address'] );
	}

	// ========== 邊緣案例（Edge）：merge_valid 色碼 ==========

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function 合法色碼(): array {
		return [
			'bg 小寫'      => [ 'free_shipping_bg_color', '#aabbcc' ],
			'bg 大寫'      => [ 'free_shipping_bg_color', '#AABBCC' ],
			'text 數字混字母' => [ 'free_shipping_text_color', '#012abc' ],
			'text 全黑'    => [ 'free_shipping_text_color', '#000000' ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider 合法色碼
	 */
	public function test_邊緣_merge_valid接受RRGGBB色碼( string $key, string $color ): void {
		$merged = CheckoutEnhancementSettingsDTO::merge_valid( [ $key => $color ], [] );

		$this->assertSame( $color, $this->effective( $merged )[ $key ] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function 不合法色碼(): array {
		return [
			'顏色名稱'         => [ 'red' ],
			'3 碼簡寫'        => [ '#fff' ],
			'缺少井字號'        => [ 'aabbcc' ],
			'非十六進位字元'      => [ '#gggggg' ],
			'8 碼（含 alpha）' => [ '#aabbccdd' ],
			'前後空白'         => [ ' #aabbcc' ],
			'空字串'          => [ '' ],
			'整數'           => [ 123456 ],
			'null'         => [ null ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider 不合法色碼
	 */
	public function test_邊緣_merge_valid忽略不合法色碼並保留原值( mixed $invalid ): void {
		$stored = [
			'free_shipping_bg_color'   => '#112233',
			'free_shipping_text_color' => '#445566',
		];

		$merged    = CheckoutEnhancementSettingsDTO::merge_valid(
			[
				'free_shipping_bg_color'   => $invalid,
				'free_shipping_text_color' => $invalid,
			],
			$stored
		);
		$effective = $this->effective( $merged );

		$this->assertSame( '#112233', $effective['free_shipping_bg_color'] );
		$this->assertSame( '#445566', $effective['free_shipping_text_color'] );
	}

	// ========== 邊緣案例（Edge）：merge_valid 文案 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_邊緣_merge_valid未達門檻文案含price佔位符時接受(): void {
		$merged = CheckoutEnhancementSettingsDTO::merge_valid( [ 'free_shipping_text_left' => '差 {price} 就免運' ], [] );

		$this->assertSame( '差 {price} 就免運', $this->effective( $merged )['free_shipping_text_left'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_邊緣_merge_valid未達門檻文案僅有price佔位符時接受(): void {
		$merged = CheckoutEnhancementSettingsDTO::merge_valid( [ 'free_shipping_text_left' => '{price}' ], [] );

		$this->assertSame( '{price}', $this->effective( $merged )['free_shipping_text_left'] );
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function 不合法未達門檻文案(): array {
		return [
			'缺少 {price}'  => [ '快達免運了' ],
			'price 少了大括號' => [ '再買 price 元' ],
			'大寫 {PRICE}'  => [ '再買 {PRICE} 元' ],
			'單層括號不完整'     => [ '再買 {price 元' ],
			'非字串（整數）'     => [ 123 ],
			'非字串（陣列）'     => [ [ '{price}' ] ],
			'null'        => [ null ],
		];
	}

	/**
	 * @test
	 * @group edge
	 * @dataProvider 不合法未達門檻文案
	 */
	public function test_邊緣_merge_valid忽略不合法的未達門檻文案並保留原值( mixed $invalid ): void {
		$stored = [ 'free_shipping_text_left' => '還差 {price} 免運' ];

		$merged = CheckoutEnhancementSettingsDTO::merge_valid( [ 'free_shipping_text_left' => $invalid ], $stored );

		$this->assertSame( '還差 {price} 免運', $this->effective( $merged )['free_shipping_text_left'] );
	}

	/**
	 * ASM-1：文案空字串視為不合法並保留原值
	 *
	 * @test
	 * @group edge
	 */
	public function test_邊緣_merge_valid文案為空字串時保留原值(): void {
		$stored = [
			'free_shipping_text_left'     => '還差 {price} 免運',
			'free_shipping_text_achieved' => '免運囉',
		];

		$merged    = CheckoutEnhancementSettingsDTO::merge_valid(
			[
				'free_shipping_text_left'     => '',
				'free_shipping_text_achieved' => '',
			],
			$stored
		);
		$effective = $this->effective( $merged );

		$this->assertSame( '還差 {price} 免運', $effective['free_shipping_text_left'] );
		$this->assertSame( '免運囉', $effective['free_shipping_text_achieved'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_邊緣_merge_valid已達門檻文案非字串時保留原值(): void {
		foreach ( [ 123, true, null, [ '免運' ] ] as $invalid ) {
			$merged = CheckoutEnhancementSettingsDTO::merge_valid( [ 'free_shipping_text_achieved' => $invalid ], [ 'free_shipping_text_achieved' => '免運囉' ] );

			$this->assertSame( '免運囉', $this->effective( $merged )['free_shipping_text_achieved'] );
		}
	}

	/**
	 * 已達門檻文案可含 Unicode / Emoji，且不要求 {price}
	 *
	 * @test
	 * @group edge
	 */
	public function test_邊緣_merge_valid已達門檻文案接受Unicode與Emoji(): void {
		$merged = CheckoutEnhancementSettingsDTO::merge_valid( [ 'free_shipping_text_achieved' => '已免運 🎉 ✔' ], [] );

		$this->assertSame( '已免運 🎉 ✔', $this->effective( $merged )['free_shipping_text_achieved'] );
	}

	// ========== 邊緣案例（Edge）：merge_valid 合併行為 ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_邊緣_merge_valid未知鍵被丟棄(): void {
		$merged = CheckoutEnhancementSettingsDTO::merge_valid(
			[
				'tw_address'  => 'yes',
				'unknown_key' => 'x',
				'provider_id' => 'evil',
			],
			[]
		);

		$this->assertArrayNotHasKey( 'unknown_key', $merged );
		$this->assertArrayNotHasKey( 'provider_id', $merged );
		$this->assertSame( 'yes', $this->effective( $merged )['tw_address'] );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_邊緣_merge_valid未送出的鍵保留儲存值且合法與不合法鍵可並存(): void {
		$stored = [
			'last_name_first'     => 'yes',
			'tw_phone_validation' => 'no',
			'free_shipping_hint'  => 'no',
		];

		$merged    = CheckoutEnhancementSettingsDTO::merge_valid(
			[
				'tw_phone_validation'    => 'yes',
				'free_shipping_hint'     => 'yes',
				'tw_address'             => 'true',
				'free_shipping_bg_color' => 'red',
			],
			$stored
		);
		$effective = $this->effective( $merged );

		$this->assertSame( 'yes', $effective['last_name_first'], '未送出的鍵保留原值' );
		$this->assertSame( 'yes', $effective['tw_phone_validation'] );
		$this->assertSame( 'yes', $effective['free_shipping_hint'] );
		$this->assertSame( 'no', $effective['tw_address'], '不合法鍵維持預設原值' );
		$this->assertSame( '#d36f6f', $effective['free_shipping_bg_color'], '不合法色碼維持預設原值' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_邊緣_merge_valid全部鍵不合法時結果等同原值(): void {
		$stored = [ 'tw_address' => 'yes' ];

		$merged = CheckoutEnhancementSettingsDTO::merge_valid(
			[
				'tw_address'              => 'maybe',
				'free_shipping_bg_color'  => 'red',
				'free_shipping_text_left' => '沒有佔位符',
				'unknown_key'             => 'x',
			],
			$stored
		);

		$this->assertSame(
			$this->effective( $stored ),
			$this->effective( $merged ),
			'全部不合法時有效值應與儲存值（補預設）一致'
		);
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_邊緣_merge_valid空輸入時結果等同原值(): void {
		$stored = [
			'last_name_first'         => 'yes',
			'free_shipping_text_left' => '還差 {price} 免運',
		];

		$merged = CheckoutEnhancementSettingsDTO::merge_valid( [], $stored );

		$this->assertSame( $this->effective( $stored ), $this->effective( $merged ) );
	}
}
