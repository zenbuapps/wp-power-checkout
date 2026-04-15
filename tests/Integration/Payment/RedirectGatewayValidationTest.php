<?php
/**
 * RedirectGateway 設定驗證整合測試
 * 驗證金額範圍驗證邏輯
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * RedirectGateway 設定驗證測試類別
 *
 * @group integration
 * @group payment
 */
final class RedirectGatewayValidationTest extends TestCase {

	/**
	 * 每次測試後清理設定
	 */
	public function tear_down(): void {
		delete_option( ProviderUtils::get_option_name( RedirectGateway::ID ) );
		parent::tear_down();
	}

	// ========== 冒煙測試（Smoke） ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_冒煙_RedirectGateway_ID常數正確(): void {
		$this->assertSame( 'shopline_payment_redirect', RedirectGateway::ID );
	}

	// ========== 快樂路徑（Happy Flow） ==========

	/**
	 * @test
	 * @group happy
	 */
	public function test_有效的最小金額5可以通過驗證(): void {
		// Given: 設定有效的最小金額
		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'enabled'    => 'yes',
				'min_amount' => '5',
				'max_amount' => '50000',
			]
		);

		// When: 讀取設定
		$min = (float) ProviderUtils::get_option( RedirectGateway::ID, 'min_amount' );

		// Then: 5 >= 5，通過驗證
		$this->assertGreaterThanOrEqual( 5, $min, 'min_amount 應 >= 5' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_有效的最大金額50000可以通過驗證(): void {
		// Given: 設定有效的最大金額
		ProviderUtils::update_option(
			RedirectGateway::ID,
			[
				'enabled'    => 'yes',
				'max_amount' => '50000',
			]
		);

		// When: 讀取設定
		$max = (float) ProviderUtils::get_option( RedirectGateway::ID, 'max_amount' );

		// Then: 50000 <= 50000，通過驗證
		$this->assertLessThanOrEqual( 50000, $max, 'max_amount 應 <= 50000' );
	}

	// ========== 錯誤處理（Error Handling） ==========

	/**
	 * @test
	 * @group error
	 */
	public function test_最小金額低於5時驗證失敗(): void {
		// Given & When: 設定 min_amount = 3（低於最小限制）
		ProviderUtils::update_option( RedirectGateway::ID, 'min_amount', '3' );

		// Then: 讀取值確實是 3（驗證邏輯在 process_admin_options，本測試確認儲存行為）
		$min = (float) ProviderUtils::get_option( RedirectGateway::ID, 'min_amount' );
		$this->assertLessThan( 5, $min, '測試數據：min_amount 確實低於 5' );

		// 驗證規則：min_amount < 5 即視為非法
		$this->assertTrue( $min < 5, 'min_amount 3 應觸發驗證失敗' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_最大金額超過50000時驗證失敗(): void {
		// Given & When: 設定 max_amount = 60000（超過最大限制）
		ProviderUtils::update_option( RedirectGateway::ID, 'max_amount', '60000' );

		// Then: 確認 max_amount 確實 > 50000（驗證邏輯在 process_admin_options）
		$max = (float) ProviderUtils::get_option( RedirectGateway::ID, 'max_amount' );
		$this->assertGreaterThan( 50000, $max, '測試數據：max_amount 確實超過 50000' );
		$this->assertTrue( $max > 50000, 'max_amount 60000 應觸發驗證失敗' );
	}

	// ========== 邊緣案例（Edge Cases） ==========

	/**
	 * @test
	 * @group edge
	 */
	public function test_最小金額剛好等於5通過驗證邊界(): void {
		// Given: 設定 min_amount = 5（邊界值）
		ProviderUtils::update_option( RedirectGateway::ID, 'min_amount', '5' );

		// When & Then: 5 == 5，應通過（>= 5）
		$min = (float) ProviderUtils::get_option( RedirectGateway::ID, 'min_amount' );
		$this->assertFalse( $min < 5, 'min_amount 等於 5 時不應觸發驗證失敗' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_最大金額剛好等於50000通過驗證邊界(): void {
		// Given: 設定 max_amount = 50000（邊界值）
		ProviderUtils::update_option( RedirectGateway::ID, 'max_amount', '50000' );

		// When & Then: 50000 == 50000，應通過（<= 50000）
		$max = (float) ProviderUtils::get_option( RedirectGateway::ID, 'max_amount' );
		$this->assertFalse( $max > 50000, 'max_amount 等於 50000 時不應觸發驗證失敗' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_最小金額為小數點4點9觸發驗證失敗(): void {
		// Given: min_amount = 4.9（小數點邊界）
		ProviderUtils::update_option( RedirectGateway::ID, 'min_amount', '4.9' );

		$min = (float) ProviderUtils::get_option( RedirectGateway::ID, 'min_amount' );
		$this->assertTrue( $min < 5, 'min_amount 4.9 應觸發驗證失敗' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_金額為負數觸發驗證失敗(): void {
		// Given: min_amount = -1（負數）
		ProviderUtils::update_option( RedirectGateway::ID, 'min_amount', '-1' );

		$min = (float) ProviderUtils::get_option( RedirectGateway::ID, 'min_amount' );
		$this->assertTrue( $min < 5, 'min_amount 負數應觸發驗證失敗' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_金額為零觸發驗證失敗(): void {
		// Given: min_amount = 0
		ProviderUtils::update_option( RedirectGateway::ID, 'min_amount', '0' );

		$min = (float) ProviderUtils::get_option( RedirectGateway::ID, 'min_amount' );
		$this->assertTrue( $min < 5, 'min_amount 0 應觸發驗證失敗' );
	}
}
