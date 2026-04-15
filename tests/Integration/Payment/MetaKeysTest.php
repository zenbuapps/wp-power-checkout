<?php
/**
 * Payment MetaKeys 整合測試
 * 驗證訂單付款相關 meta 的讀寫操作
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use Tests\Integration\TestCase;

/**
 * Payment MetaKeys 測試類別
 *
 * @group integration
 * @group payment
 */
final class MetaKeysTest extends TestCase {

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_MetaKeys_可以被實例化(): void {
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		$this->assertInstanceOf( MetaKeys::class, $meta_keys );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取付款識別碼(): void {
		// Given: 一筆訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		// When: 儲存 tradeOrderId
		$meta_keys->update_payment_identity( 'TRADE_001' );

		// Then: 可以正確讀取
		$this->assertSame( 'TRADE_001', $meta_keys->get_payment_identity() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取付款詳情(): void {
		// Given: 一筆訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		$payment_data = [
			'tradeOrderId' => 'TRADE_001',
			'status'       => 'SUCCEEDED',
			'amount'       => 1000,
		];

		// When: 儲存付款詳情
		$meta_keys->update_payment_detail( $payment_data );

		// Then: 可以正確讀取
		$result = $meta_keys->get_payment_detail();
		$this->assertSame( 'TRADE_001', $result['tradeOrderId'] );
		$this->assertSame( 'SUCCEEDED', $result['status'] );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_依付款識別碼查詢訂單(): void {
		// Given: 一筆有付款識別碼的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_payment_identity( 'TRADE_SEARCH_001' );

		// When: 用 tradeOrderId 查詢
		$found_order = MetaKeys::get_order_by_identity_payment_key( 'TRADE_SEARCH_001' );

		// Then: 找到正確的訂單
		$this->assertInstanceOf( \WC_Order::class, $found_order );
		$this->assertSame( $order->get_id(), $found_order->get_id() );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_儲存並讀取退款詳情(): void {
		// Given: 一筆訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		$refund_data = [
			'refundId' => 'REFUND_001',
			'amount'   => 500,
			'status'   => 'SUCCEEDED',
		];

		// When: 儲存退款詳情
		$meta_keys->update_refund_detail( $refund_data );

		// Then: 可以正確讀取
		$result = $meta_keys->get_refund_detail();
		$this->assertSame( 'REFUND_001', $result['refundId'] );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_找不到訂單時回傳null(): void {
		// When: 查詢一個不存在的 tradeOrderId
		$found_order = MetaKeys::get_order_by_identity_payment_key( 'NONEXISTENT_TRADE_ID' );

		// Then: 回傳 null
		$this->assertNull( $found_order );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_未設定付款識別碼時回傳空字串(): void {
		// Given: 一筆沒有付款識別碼的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		// When & Then: 讀取應回傳空字串
		$this->assertSame( '', $meta_keys->get_payment_identity() );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_未設定付款詳情時回傳空陣列(): void {
		// Given: 一筆沒有付款詳情的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		// When & Then: 讀取應回傳空陣列
		$this->assertSame( [], $meta_keys->get_payment_detail() );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_覆寫付款識別碼只保留最新值(): void {
		// Given: 一筆已有付款識別碼的訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );
		$meta_keys->update_payment_identity( 'OLD_TRADE_ID' );

		// When: 覆寫為新的識別碼
		$meta_keys->update_payment_identity( 'NEW_TRADE_ID' );

		// Then: 只保留最新值
		$this->assertSame( 'NEW_TRADE_ID', $meta_keys->get_payment_identity() );

		// 且用舊識別碼查不到
		$old_result = MetaKeys::get_order_by_identity_payment_key( 'OLD_TRADE_ID' );
		$this->assertNull( $old_result );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_極長的tradeOrderId也能正確儲存(): void {
		// Given: 256 字元的超長 tradeOrderId
		$long_id   = str_repeat( 'A', 256 );
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		// When: 儲存超長 ID
		$meta_keys->update_payment_identity( $long_id );

		// Then: 可以正確讀取
		$this->assertSame( $long_id, $meta_keys->get_payment_identity() );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_空字串tradeOrderId儲存後回傳空字串(): void {
		// Given: 一筆訂單
		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		// When: 儲存空字串
		$meta_keys->update_payment_identity( '' );

		// Then: 讀取為空字串
		$this->assertSame( '', $meta_keys->get_payment_identity() );
	}

	// ========== 安全性（Security） ==========

	/**
	 * @test
	 * @group security
	 */
	public function test_SQL_injection字串作為tradeOrderId不造成異常(): void {
		// Given: 含有 SQL injection 嘗試的識別碼
		$sql_injection = "'; DROP TABLE wp_posts; --";
		$order         = $this->create_wc_order();
		$meta_keys     = new MetaKeys( $order );

		// When: 儲存
		$meta_keys->update_payment_identity( $sql_injection );

		// Then: 儲存與讀取正確，不造成 SQL 錯誤
		$this->assertSame( $sql_injection, $meta_keys->get_payment_identity() );
	}

	/**
	 * @test
	 * @group security
	 */
	public function test_XSS字串作為付款詳情不造成異常(): void {
		// Given: 含有 XSS 嘗試的付款詳情
		$xss_data = [
			'tradeOrderId' => '<script>alert("xss")</script>',
			'status'       => '"><img src=x onerror=alert(1)>',
		];

		$order     = $this->create_wc_order();
		$meta_keys = new MetaKeys( $order );

		// When: 儲存
		$meta_keys->update_payment_detail( $xss_data );

		// Then: 原始資料被正確儲存（輸出時由 WordPress 的 esc_* 函式處理）
		$result = $meta_keys->get_payment_detail();
		$this->assertSame( '<script>alert("xss")</script>', $result['tradeOrderId'] );
	}
}
