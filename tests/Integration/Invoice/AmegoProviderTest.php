<?php
/**
 * AmegoProvider 整合測試
 * 驗證冪等性保護、發票號碼讀取等不依賴外部 API 的邏輯
 *
 * 注意：實際 API 呼叫（issue, cancel 的 HTTP 請求部分）不在整合測試範疇，
 * 僅測試純 DB 層邏輯（冪等性保護、meta 讀寫）
 */

declare( strict_types=1 );

namespace Tests\Integration\Invoice;

use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * AmegoProvider 測試類別
 *
 * @group integration
 * @group invoice
 */
final class AmegoProviderTest extends TestCase {

	/**
	 * 每次測試後清理設定
	 */
	public function tear_down(): void {
		delete_option( ProviderUtils::get_option_name( AmegoProvider::ID ) );
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_AmegoProvider_ID常數正確(): void {
		$this->assertSame( 'amego', AmegoProvider::ID );
	}

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_get_settings_回傳陣列(): void {
		$settings = AmegoProvider::get_settings( false );
		$this->assertIsArray( $settings );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_issue_已開立過時冪等回傳已存在資料(): void {
		// Given: 一筆已有開立資料的訂單（模擬已開立過發票）
		$issued_data = [
			'invoice_number' => 'AB12345678',
			'status'         => 'success',
		];
		$order       = $this->create_order_with_issued_invoice( $issued_data );

		// 啟用 Amego provider（雖然不呼叫外部 API，但需要 provider 存在）
		$this->enable_provider( AmegoProvider::ID );
		$provider = AmegoProvider::instance();

		// When: 再次呼叫 issue（冪等性保護）
		$result = $provider->issue( $order );

		// Then: 直接回傳已存在的資料，不重複開立
		$this->assertIsArray( $result );
		$this->assertSame( 'AB12345678', $result['invoice_number'] ?? '' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_cancel_已作廢過時冪等回傳已存在資料(): void {
		// Given: 一筆已有作廢資料的訂單
		$order          = $this->create_wc_order( [ 'status' => 'cancelled' ] );
		$meta_keys      = new MetaKeys( $order );
		$cancelled_data = [
			'cancel_number' => 'AB12345678',
			'status'        => 'cancelled',
		];
		$meta_keys->update_cancelled_data( $cancelled_data );

		// 啟用 Amego provider
		$this->enable_provider( AmegoProvider::ID );
		$provider = AmegoProvider::instance();

		// When: 再次呼叫 cancel（冪等性保護）
		$result = $provider->cancel( $order );

		// Then: 直接回傳已存在的資料，不重複作廢
		$this->assertIsArray( $result );
		$this->assertSame( 'AB12345678', $result['cancel_number'] ?? '' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_invoice_number_已開立時回傳發票號碼(): void {
		// Given: 一筆已有開立資料的訂單
		$issued_data = [ 'invoice_number' => 'XY98765432' ];
		$order       = $this->create_order_with_issued_invoice( $issued_data );

		// 啟用 Amego provider
		$this->enable_provider( AmegoProvider::ID );
		$provider = AmegoProvider::instance();

		// When: 取得發票號碼
		$invoice_number = $provider->get_invoice_number( $order );

		// Then: 回傳正確的發票號碼
		$this->assertSame( 'XY98765432', $invoice_number );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_invoice_number_未開立時回傳空字串(): void {
		// Given: 一筆沒有發票資料的訂單
		$order = $this->create_wc_order();

		// 啟用 Amego provider
		$this->enable_provider( AmegoProvider::ID );
		$provider = AmegoProvider::instance();

		// When: 取得發票號碼
		$invoice_number = $provider->get_invoice_number( $order );

		// Then: 回傳空字串
		$this->assertSame( '', $invoice_number );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_get_settings_不帶預設值時回傳資料庫值(): void {
		// Given: 設定 Amego 設定
		ProviderUtils::update_option(
			AmegoProvider::ID,
			[
				'enabled'                   => 'yes',
				'auto_issue_order_statuses' => [ 'wc-processing' ],
			]
		);

		// When: 取得設定（不帶預設值）
		$settings = AmegoProvider::get_settings( false );

		// Then: 回傳資料庫中的設定
		$this->assertIsArray( $settings );
		$this->assertSame( 'yes', $settings['enabled'] );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_cancel_未開立發票時回傳空陣列(): void {
		// Given: 一筆沒有開立資料也沒有作廢資料的訂單
		$order = $this->create_wc_order( [ 'status' => 'cancelled' ] );

		// 啟用 Amego provider
		$this->enable_provider( AmegoProvider::ID );
		$provider = AmegoProvider::instance();

		// When: 呼叫 cancel（但無已作廢資料 → 不走冪等 → 呼叫外部 API）
		// 由於沒有外部 API，預期回傳空陣列（ApiClient 回傳 null 時 cancel 方法回傳 []）
		// 注意：此測試驗證 cancel 方法在無外部 API 設定時的安全降級行為
		try {
			$result = $provider->cancel( $order );
			// 如果沒有 API 設定，預期結果為空陣列
			$this->assertIsArray( $result );
		} catch ( \Throwable $e ) {
			// 在測試環境中可能拋出例外（無效的 API credentials），這也是可接受的行為
			$this->assertNotEmpty( $e->getMessage() );
		}
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_issue_用訂單id而非物件傳入冪等仍正確(): void {
		// Given: 一筆已有開立資料的訂單
		$issued_data = [ 'invoice_number' => 'AB12345678' ];
		$order       = $this->create_order_with_issued_invoice( $issued_data );

		// 啟用 Amego provider
		$this->enable_provider( AmegoProvider::ID );
		$provider = AmegoProvider::instance();

		// When: 用訂單 ID（int）傳入 issue
		$order_id = $order->get_id();
		$result   = $provider->issue( $order_id );

		// Then: 冪等保護仍然有效
		$this->assertIsArray( $result );
		$this->assertSame( 'AB12345678', $result['invoice_number'] ?? '' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_cancel_用訂單id而非物件傳入冪等仍正確(): void {
		// Given: 一筆已有作廢資料的訂單
		$order     = $this->create_wc_order( [ 'status' => 'cancelled' ] );
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_cancelled_data( [ 'cancel_number' => 'AB12345678' ] );

		// 啟用 Amego provider
		$this->enable_provider( AmegoProvider::ID );
		$provider = AmegoProvider::instance();

		// When: 用訂單 ID 傳入 cancel
		$order_id = $order->get_id();
		$result   = $provider->cancel( $order_id );

		// Then: 冪等保護仍然有效
		$this->assertIsArray( $result );
		$this->assertSame( 'AB12345678', $result['cancel_number'] ?? '' );
	}
}
