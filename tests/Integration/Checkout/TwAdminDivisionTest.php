<?php
/**
 * 台灣縣市鄉鎮資料（TwAdminDivision）整合測試
 *
 * 對應計劃：specs/open-issue/checkout-enhancement-implementation-plan.md 第 3 階段第 1、7 點
 * 被測類別：J7\PowerCheckout\Domains\Checkout\Shared\Helpers\TwAdminDivision
 *
 * 預期公開 API（測試依賴的最小介面）：
 *   TwAdminDivision::get_all(): array<string, array<string, string>>
 *   縣市名（中文全名，正式用字「臺」）=> [ 鄉鎮名 => 3 碼郵遞區號字串 ]
 *
 * 執行指令：
 *   bash "<scratchpad>/gate.sh" ut tests/Integration/Checkout/TwAdminDivisionTest.php
 *
 * @group checkout
 */

declare( strict_types=1 );

namespace Tests\Integration\Checkout;

use J7\PowerCheckout\Domains\Checkout\Shared\Helpers\TwAdminDivision;
use Tests\Integration\TestCase;

/**
 * Class TwAdminDivisionTest
 */
final class TwAdminDivisionTest extends TestCase {

	/** 22 縣市（內政部正式用字「臺」） */
	private const EXPECTED_COUNTIES = [
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

	/**
	 * 22 縣市完整且名稱精確
	 *
	 * @group edge
	 */
	public function test_共22個縣市且名稱精確(): void {
		$all = TwAdminDivision::get_all();

		$this->assertCount( 22, $all );
		$this->assertEqualsCanonicalizing( self::EXPECTED_COUNTIES, array_keys( $all ) );
	}

	/**
	 * 縣市名不含「台」（一律用正式字「臺」，與 woomp 既存訂單資料相容）
	 *
	 * @group edge
	 */
	public function test_縣市名不含簡寫台字(): void {
		foreach ( array_keys( TwAdminDivision::get_all() ) as $county ) {
			$this->assertStringNotContainsString( '台', (string) $county, "縣市「{$county}」不應含「台」" );
		}
	}

	/**
	 * 鄉鎮總數 371，且每個縣市至少一個鄉鎮、鄉鎮名為非空字串
	 *
	 * @group edge
	 */
	public function test_鄉鎮總數371且每個縣市至少一個鄉鎮(): void {
		$total = 0;
		foreach ( TwAdminDivision::get_all() as $county => $districts ) {
			$this->assertIsArray( $districts );
			$this->assertNotEmpty( $districts, "縣市「{$county}」應至少有一個鄉鎮" );
			foreach ( array_keys( $districts ) as $name ) {
				$this->assertNotSame( '', trim( (string) $name ), "縣市「{$county}」有空白鄉鎮名" );
			}
			$total += count( $districts );
		}

		$this->assertSame( 371, $total, '鄉鎮市區總數應為 371（對齊 woomp twzipcode v1.7.14）' );
	}

	/**
	 * 郵遞區號皆為 3 碼數字字串
	 *
	 * @group edge
	 */
	public function test_郵遞區號皆為3碼數字(): void {
		foreach ( TwAdminDivision::get_all() as $county => $districts ) {
			foreach ( $districts as $name => $zip ) {
				$this->assertIsString( $zip, "{$county}{$name} 的郵遞區號應為字串（保留前導 0 的可能性與 JSON 一致）" );
				$this->assertMatchesRegularExpression( '/^\d{3}$/', $zip, "{$county}{$name} 的郵遞區號應為 3 碼數字，實際：{$zip}" );
			}
		}
	}

	/**
	 * 抽樣：已知鄉鎮與郵遞區號正確
	 *
	 * @group edge
	 * @dataProvider provide_known_districts
	 *
	 * @param string $county   縣市
	 * @param string $district 鄉鎮
	 * @param string $zip      郵遞區號
	 */
	public function test_抽樣鄉鎮郵遞區號正確( string $county, string $district, string $zip ): void {
		$all = TwAdminDivision::get_all();

		$this->assertArrayHasKey( $county, $all );
		$this->assertArrayHasKey( $district, $all[ $county ] );
		$this->assertSame( $zip, (string) $all[ $county ][ $district ] );
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function provide_known_districts(): array {
		return [
			'臺北市中正區' => [ '臺北市', '中正區', '100' ],
			'臺北市信義區' => [ '臺北市', '信義區', '110' ],
			'連江縣南竿鄉' => [ '連江縣', '南竿鄉', '209' ],
			'澎湖縣馬公市' => [ '澎湖縣', '馬公市', '880' ],
			'金門縣金城鎮' => [ '金門縣', '金城鎮', '893' ],
		];
	}
}
