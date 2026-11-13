<?php
/**
 * 台灣手機格式判斷（PhoneValidationService::is_valid_tw_mobile）純邏輯測試
 *
 * 對應規格：specs/features/checkout/checkout-field-validation.feature
 *   「格式（Q2=A）：去除空白與連字號後須符合 ^09\d{8}$；不改寫顧客輸入」
 * 對應計劃：specs/open-issue/checkout-enhancement-implementation-plan.md 第 4 階段第 1 點
 * 被測類別：J7\PowerCheckout\Domains\Checkout\Services\PhoneValidationService
 *
 * 預期公開 API（測試依賴的最小介面）：
 *   PhoneValidationService::is_valid_tw_mobile( string $phone ): bool
 *   - 先去除所有空白與連字號 "-"，再判斷是否為 09 開頭的 10 碼數字
 *   - 只回傳判斷結果，不改寫輸入（字串以值傳遞）
 *
 * 執行指令（--filter 要帶路徑，否則 0 收集）：
 *   bash "<scratchpad>/gate.sh" ut tests/Integration/Checkout/PhoneValidationServiceTest.php
 *   或 npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Checkout/PhoneValidationServiceTest.php --no-coverage"
 *
 * @group checkout
 */

declare( strict_types=1 );

namespace Tests\Integration\Checkout;

use J7\PowerCheckout\Domains\Checkout\Services\PhoneValidationService;
use Tests\Integration\TestCase;

/**
 * Class PhoneValidationServiceTest
 */
final class PhoneValidationServiceTest extends TestCase {

	/**
	 * 冒煙：最標準的手機號碼合法、市話不合法
	 *
	 * @group smoke
	 */
	public function test_標準手機號碼合法且市話不合法(): void {
		$this->assertTrue( PhoneValidationService::is_valid_tw_mobile( '0912345678' ), '0912345678 為標準手機格式，應合法' );
		$this->assertFalse( PhoneValidationService::is_valid_tw_mobile( '0223456789' ), '0223456789 為市話，應不合法' );
	}

	/**
	 * 合法手機：去除空白與連字號後為 09 開頭 10 碼
	 *
	 * @group edge
	 * @dataProvider 合法手機提供者
	 *
	 * @param string $phone 輸入電話
	 */
	public function test_合法手機格式(string $phone): void {
		$this->assertTrue(
			PhoneValidationService::is_valid_tw_mobile( $phone ),
			sprintf( '"%s" 去除空白與連字號後應為 09 開頭 10 碼，應合法', $phone )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function 合法手機提供者(): array {
		return [
			'純數字'           => [ '0912345678' ],
			'連字號分隔'       => [ '0912-345-678' ],
			'空白分隔'         => [ '0912 345 678' ],
			'連字號與空白混用' => [ '0912-345 678' ],
			'連字號位置不同'   => [ '09-12345678' ],
			'前後有空白'       => [ '  0912345678  ' ],
			'09 開頭最小值'    => [ '0900000000' ],
			'09 開頭最大值'    => [ '0999999999' ],
		];
	}

	/**
	 * 不合法電話：市話、位數不足/過多、國碼、非 09 開頭
	 *
	 * @group edge
	 * @dataProvider 不合法電話提供者
	 *
	 * @param string $phone 輸入電話
	 */
	public function test_不合法電話格式(string $phone): void {
		$this->assertFalse(
			PhoneValidationService::is_valid_tw_mobile( $phone ),
			sprintf( '"%s" 不是 09 開頭 10 碼手機，應不合法', $phone )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function 不合法電話提供者(): array {
		return [
			'空字串'               => [ '' ],
			'只有空白'             => [ '   ' ],
			'只有連字號'           => [ '---' ],
			'市話 02'              => [ '0223456789' ],
			'市話含連字號'         => [ '02-2345-6789' ],
			'9 碼（位數不足）'     => [ '091234567' ],
			'11 碼（位數過多）'    => [ '09123456789' ],
			'連字號後 11 碼'       => [ '0912-345-6789' ],
			'國碼 +886'            => [ '+886912345678' ],
			'國碼 886 無加號'      => [ '886912345678' ],
			'去 0 的手機 9 開頭'   => [ '912345678' ],
			'08 開頭 10 碼'        => [ '0812345678' ],
			'10 開頭'              => [ '1012345678' ],
			'含英文字母'           => [ '09123abcde' ],
			'含括號'               => [ '(0912)345678' ],
			'含小數點'             => [ '0912.345.678' ],
			'含 emoji'             => [ '0912345678😀' ],
			'中文數字'             => [ '零九一二三四五六七八' ],
		];
	}

	/**
	 * 安全：夾帶 HTML / SQL 片段的輸入一律不合法（不因去除空白連字號後碰巧符合而放行）
	 *
	 * @group security
	 * @dataProvider 惡意輸入提供者
	 *
	 * @param string $phone 輸入電話
	 */
	public function test_夾帶惡意字串一律不合法(string $phone): void {
		$this->assertFalse(
			PhoneValidationService::is_valid_tw_mobile( $phone ),
			sprintf( '夾帶惡意字串的 "%s" 應不合法', $phone )
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function 惡意輸入提供者(): array {
		return [
			'script 標籤尾隨'  => [ '0912345678<script>' ],
			'完整 script 標籤' => [ '0912345678<script>alert(1)</script>' ],
			'SQL 注入'         => [ "0912345678' OR '1'='1" ],
			'NUL 位元組'       => [ "0912345678\0" ],
		];
	}
}
