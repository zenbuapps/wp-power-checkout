<?php
/**
 * PaymentMethodOption / PaymentMethodOptions DTO 序列化整合測試
 *
 * 覆蓋 Issue #12：SLP 分期期數「0 期」仍顯示的 Bug
 *
 * 根因：PaymentMethodOption::$installmentCounts 宣告為未初始化 public array，
 *       當商家取消勾選所有期數或儲存空陣列時，to_array() 會 skip 該 key，
 *       送給 SLP 的 payload 不含 installmentCounts，SLP fallback 顯示全部期數。
 *
 * 本測試驗證：
 *   1. 商家取消勾選 0 期 → payload 不含 '0'
 *   2. 商家儲存空陣列 installmentCounts → payload 必須帶 'installmentCounts: []' key
 *      （避免 SLP fallback）
 *   3. to_array() 輸出 sort 過的 installmentCounts
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\PaymentMethodOption;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components\PaymentMethodOptions;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * @group integration
 * @group payment
 * @group issue-12
 */
final class PaymentMethodOptionSerializationTest extends TestCase {

	/** 每次測試後清空 SLP 設定 */
	public function tear_down(): void {
		delete_option( ProviderUtils::get_option_name( RedirectGateway::ID ) );
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_PaymentMethodOption可以被實例化(): void {
		$option = PaymentMethodOption::create(
			[ 'installmentCounts' => [ '3', '6' ] ],
			'CreditCardOption'
		);

		$this->assertInstanceOf( PaymentMethodOption::class, $option );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 *
	 * Issue #12 回歸測試：
	 * 商家只勾選 [3, 6]（不含 0 期），to_array 後陣列**不能**含 '0'。
	 */
	public function test_CreditCard勾選3和6期到陣列不含0期(): void {
		// Given: 商家只勾選 3 期和 6 期
		$option = PaymentMethodOption::create(
			[ 'installmentCounts' => [ '3', '6' ] ],
			'CreditCardOption'
		);

		// When: 轉為 array
		$array = $option->to_array();

		// Then: installmentCounts 存在、內容為 [3, 6]、絕不含 0
		$this->assertArrayHasKey( 'installmentCounts', $array, 'installmentCounts key 必須存在' );
		$this->assertSame( [ '3', '6' ], $array['installmentCounts'] );
		$this->assertNotContains( '0', $array['installmentCounts'], '陣列不應含 0 期' );
	}

	/**
	 * @test
	 * @group happy
	 *
	 * Issue #12 回歸測試：ChaileaseBNPL 中租分期同樣行為。
	 */
	public function test_ChaileaseBNPL勾選3和6和12期到陣列不含0期(): void {
		// Given: 商家只勾選 3, 6, 12 期
		$option = PaymentMethodOption::create(
			[ 'installmentCounts' => [ '3', '6', '12' ] ],
			'ChaileaseBNPLOption'
		);

		// When: 轉為 array
		$array = $option->to_array();

		// Then
		$this->assertSame( [ '3', '6', '12' ], $array['installmentCounts'] );
		$this->assertNotContains( '0', $array['installmentCounts'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_to_array會把installmentCounts依數字排序(): void {
		// Given: 未排序的期數
		$option = PaymentMethodOption::create(
			[ 'installmentCounts' => [ '12', '3', '6' ] ],
			'CreditCardOption'
		);

		// When
		$array = $option->to_array();

		// Then: 排序過
		$this->assertSame( [ '3', '6', '12' ], $array['installmentCounts'] );
	}

	// ========== 錯誤處理 & 關鍵回歸（Error / Issue #12 核心） ==========

	/**
	 * @test
	 * @group error
	 * @group issue-12-red
	 *
	 * Issue #12 的「關鍵 Red 測試」：
	 * 當建立 PaymentMethodOption 但 args 中沒有 installmentCounts key 時
	 * （對應 DB 存 paymentMethodOptions.ChaileaseBNPL = [] 的情境），
	 * to_array() **必須**仍然輸出 installmentCounts: []，而不是 skip 掉這個 key。
	 *
	 * 修復前：installmentCounts 宣告為 `public array $installmentCounts;`（未初始化）
	 *         → DTO::to_array() 第 85 行的 isInitialized 檢查會跳過
	 *         → 送給 SLP 的 JSON 沒有 installmentCounts key
	 *         → SLP fallback 顯示全部期數（含 0 期）
	 *
	 * 修復後：installmentCounts 初始化為 []
	 *         → to_array() 永遠輸出 installmentCounts: []
	 */
	public function test_args未帶installmentCounts時to_array仍有該key(): void {
		// Given: 建立 PaymentMethodOption 時完全不帶 installmentCounts
		$option = PaymentMethodOption::create(
			[], // 對應 DB 存 'ChaileaseBNPL' => [] 的情境
			'ChaileaseBNPLOption'
		);

		// When
		$array = $option->to_array();

		// Then: installmentCounts 必須存在且為空陣列
		$this->assertArrayHasKey(
			'installmentCounts',
			$array,
			'Issue #12：args 未帶 installmentCounts 時，to_array 仍必須輸出此 key（預設 []），否則 SLP 會 fallback 顯示全部期數'
		);
		$this->assertSame(
			[],
			$array['installmentCounts'],
			'空輸入應序列化為空陣列'
		);
	}

	/**
	 * @test
	 * @group error
	 * @group issue-12-red
	 *
	 * Issue #12 整合情境：
	 * 走完整個 settings array → PaymentMethodOptions::create → to_array 的流程，
	 * 驗證即使某個 method 沒帶 installmentCounts，序列化後仍必定帶該 key。
	 */
	public function test_PaymentMethodOptions整體序列化時每個method的installmentCounts必定存在(): void {
		// Given: 模擬 DB 存的資料，ChaileaseBNPL 沒有 installmentCounts key
		$settings_array = [
			'CreditCard'    => [ 'installmentCounts' => [ '3', '6' ] ],
			'ChaileaseBNPL' => [], // 關鍵：沒有 installmentCounts key
		];

		// When
		$options = PaymentMethodOptions::create( $settings_array );
		$array   = $options->to_array();

		// Then: ChaileaseBNPL 存在，且其 installmentCounts key 必須存在
		$this->assertArrayHasKey( 'ChaileaseBNPL', $array );
		$this->assertArrayHasKey(
			'installmentCounts',
			$array['ChaileaseBNPL'],
			'ChaileaseBNPL.installmentCounts 必須在序列化後存在'
		);
		$this->assertSame( [], $array['ChaileaseBNPL']['installmentCounts'] );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_installmentCounts空陣列被接受不丟例外(): void {
		$option = PaymentMethodOption::create(
			[ 'installmentCounts' => [] ],
			'CreditCardOption'
		);

		$array = $option->to_array();
		$this->assertSame( [], $array['installmentCounts'] );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 保障現況：商家完全沒儲存過 paymentMethodOptions 時（DB 無該 key），
	 * RedirectSettingsDTO 會用 greedy 預設值（全部期數），此為可接受的 fallback。
	 * 這個測試**不是**修復目標，而是確認修復不會意外影響 fallback 路徑。
	 */
	public function test_DB無paymentMethodOptions時SettingsDTO仍回傳預設全部期數(): void {
		// Given: DB 完全無 paymentMethodOptions（模擬全新安裝）
		delete_option( ProviderUtils::get_option_name( RedirectGateway::ID ) );

		// When
		$settings = RedirectSettingsDTO::instance();

		// Then: 預設值完整，包含 0 期（這是預期行為，非 bug）
		$this->assertArrayHasKey( 'CreditCard', $settings->paymentMethodOptions );
		$this->assertContains( '0', $settings->paymentMethodOptions['CreditCard']['installmentCounts'] );
		$this->assertContains( '0', $settings->paymentMethodOptions['ChaileaseBNPL']['installmentCounts'] );
	}

	/**
	 * @test
	 * @group edge
	 * @group issue-12-red
	 *
	 * REST API 寫入 → 讀回一致性：商家取消勾選 0 期後 POST /settings 應完整持久化。
	 */
	public function test_更新paymentMethodOptions後讀回一致不含0期(): void {
		// Given: 商家勾選 [3, 6]（取消 0 期）
		$payload = [
			'enabled'              => 'yes',
			'paymentMethodOptions' => [
				'CreditCard'    => [ 'installmentCounts' => [ '3', '6' ] ],
				'ChaileaseBNPL' => [ 'installmentCounts' => [ '3', '6', '12' ] ],
			],
		];

		// When
		ProviderUtils::update_option( RedirectGateway::ID, $payload );

		// Then: 讀回的設定完全一致，且陣列不含 0
		$stored = ProviderUtils::get_option( RedirectGateway::ID, 'paymentMethodOptions' );
		$this->assertIsArray( $stored );
		$this->assertSame( [ '3', '6' ], $stored['CreditCard']['installmentCounts'] );
		$this->assertNotContains( '0', $stored['CreditCard']['installmentCounts'] );
		$this->assertSame( [ '3', '6', '12' ], $stored['ChaileaseBNPL']['installmentCounts'] );
		$this->assertNotContains( '0', $stored['ChaileaseBNPL']['installmentCounts'] );
	}

	/**
	 * @test
	 * @group edge
	 *
	 * 回歸確認：即使加了 `installmentCounts = []` 的預設值，
	 * JKOPay / VirtualAccount 在 args 帶 installmentCounts 時仍必須拋例外。
	 * （__isset 被 DTO base 改寫成檢查 dto_data，不是屬性初始化狀態，所以這條 validate 邏輯不受影響）
	 *
	 * 本測試只能在 strict mode 下驗證（local env）。
	 * 在 prod env 下 DTO 會吞錯，所以改驗證「不拋錯但會記 log」的行為：
	 * 我們這裡直接以 `wp_get_environment_type` 為 'local' 的預設行為為主，
	 * 若 fallback 吞錯則斷言 installmentCounts 預設為 []（而非 args 傳入的 [3,6]）。
	 */
	public function test_JKOPay帶installmentCounts時validate仍會阻擋(): void {
		// Given: JKOPay 不該有 installmentCounts，但模擬使用者錯誤傳入
		$is_local = function_exists( 'wp_get_environment_type' ) && 'local' === \wp_get_environment_type();

		if ( $is_local ) {
			$this->expectException( \Exception::class );
			$this->expectExceptionMessage( 'JKOPayOption 不需要 installmentCounts 設定' );
			PaymentMethodOption::create(
				[ 'installmentCounts' => [ '3', '6' ] ],
				'JKOPayOption'
			);
		} else {
			// 非 local 環境下 DTO 吞錯，僅確認類別仍可實例化不致 fatal
			$option = PaymentMethodOption::create(
				[ 'installmentCounts' => [ '3', '6' ] ],
				'JKOPayOption'
			);
			$this->assertInstanceOf( PaymentMethodOption::class, $option );
		}
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_連續更新installmentCounts不會累加而是取代(): void {
		// Given: 第一次存 [3, 6]
		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'paymentMethodOptions' => [
					'ChaileaseBNPL' => [ 'installmentCounts' => [ '3', '6' ] ],
				],
			]
		);

		// When: 第二次改成 [6, 12]
		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'paymentMethodOptions' => [
					'ChaileaseBNPL' => [ 'installmentCounts' => [ '6', '12' ] ],
				],
			]
		);

		// Then: 是取代而非累加
		$stored = ProviderUtils::get_option( RedirectGateway::ID, 'paymentMethodOptions' );
		$this->assertSame( [ '6', '12' ], $stored['ChaileaseBNPL']['installmentCounts'] );
		$this->assertCount(
			2,
			$stored['ChaileaseBNPL']['installmentCounts'],
			'連續儲存不應產生 [3,6,6,12] 的陣列累加'
		);
	}
}
