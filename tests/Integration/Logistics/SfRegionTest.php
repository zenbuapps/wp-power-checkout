<?php
/**
 * 順豐區劃換算測試（P6：sf_express）
 *
 * 台灣郵遞區號 / 中文縣市 + 鄉鎮市區 → 順豐 regionFirst（縣市英文）/ regionSecond（區英文）。
 * 資料 = zenbu `tw-admin-divisions.ts`（中華郵政中英對照）一次性轉出；`Dist.` 展開為 `District`、
 * 彎撇號換 ASCII（順豐 ICMS_QUERY_REGION_BY_POST_CODE 實回 `Zhongzheng District` / `Xitun District`）。
 * 3 碼 300（新竹市三區）/ 600（嘉義市兩區）共用 → 僅郵遞區號時不猜（null），需配合縣市區名。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter SfRegion --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfRegion;
use Tests\Integration\TestCase;

/**
 * 順豐區劃換算
 *
 * @group integration
 * @group logistics
 * @group sf_express
 */
final class SfRegionTest extends TestCase {

	/**
	 * Data：郵遞區號 → 英文縣市 / 區
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public function postcode_cases(): array {
		return [
			'臺北市中正區 3 碼' => [ '100', 'Taipei City', 'Zhongzheng District' ],
			'臺北市中正區 5 碼' => [ '10048', 'Taipei City', 'Zhongzheng District' ],
			'臺中市西屯區 5 碼' => [ '40743', 'Taichung City', 'Xitun District' ],
			'臺中市大雅區'     => [ '428', 'Taichung City', 'Daya District' ],
			'高雄市前鎮區 6 碼' => [ '806001', 'Kaohsiung City', 'Qianzhen District' ],
			'臺北市大安區（撇號）' => [ '106', 'Taipei City', "Da'an District" ],
			'花蓮縣吉安鄉'     => [ '973', 'Hualien County', "Ji'an Township" ],
		];
	}

	/**
	 * Happy：郵遞區號（取前 3 碼）→ 英文區劃
	 *
	 * @dataProvider postcode_cases
	 * @group happy
	 *
	 * @param string $postcode 郵遞區號
	 * @param string $first    regionFirst
	 * @param string $second   regionSecond
	 */
	public function test_郵遞區號換算( string $postcode, string $first, string $second ): void {
		$region = SfRegion::from_postcode( $postcode );

		$this->assertNotNull( $region );
		$this->assertSame( $first, $region['region_first'] );
		$this->assertSame( $second, $region['region_second'] );
	}

	/**
	 * Happy：中文縣市 + 區（台 → 臺 正規化、去空白）
	 *
	 * @group happy
	 */
	public function test_中文縣市區換算(): void {
		$region = SfRegion::from_names( ' 台北市 ', '大安區' );

		$this->assertNotNull( $region );
		$this->assertSame( 'Taipei City', $region['region_first'] );
		$this->assertSame( "Da'an District", $region['region_second'] );
		$this->assertSame( '106', $region['zip3'] );
	}

	/**
	 * Edge：共用郵遞區號（300 / 600）只給郵遞區號 → null（不猜）；配合區名即可分辨
	 *
	 * @group edge
	 */
	public function test_共用郵遞區號不猜(): void {
		$this->assertNull( SfRegion::from_postcode( '300' ) );
		$this->assertNull( SfRegion::from_postcode( '60041' ) );

		$region = SfRegion::resolve( '300', '新竹市', '香山區' );
		$this->assertNotNull( $region );
		$this->assertSame( 'Hsinchu City', $region['region_first'] );
		$this->assertSame( 'Xiangshan District', $region['region_second'] );
	}

	/**
	 * Happy：resolve 從自由文字（WC 未啟用縣市下拉時 city 常填「台中市大雅區」）解析縣市 + 區
	 *
	 * @group happy
	 */
	public function test_自由文字解析(): void {
		$region = SfRegion::resolve( '', '', '台中市大雅區', '中清路三段513號' );

		$this->assertNotNull( $region );
		$this->assertSame( 'Taichung City', $region['region_first'] );
		$this->assertSame( 'Daya District', $region['region_second'] );
		$this->assertSame( '428', $region['zip3'] );

		$from_address = SfRegion::resolve( '', '', '', '臺北市中正區重慶南路一段1號' );
		$this->assertNotNull( $from_address );
		$this->assertSame( 'Zhongzheng District', $from_address['region_second'] );
	}

	/**
	 * Edge：區名優先於郵遞區號（兩者不一致時以區名為準）
	 *
	 * @group edge
	 */
	public function test_區名優先於郵遞區號(): void {
		$region = SfRegion::resolve( '100', '臺中市', '大雅區' );

		$this->assertNotNull( $region );
		$this->assertSame( 'Daya District', $region['region_second'] );
	}

	/**
	 * Edge：查無 / 縣市與區錯配 / 非數字郵遞區號 → null
	 *
	 * @group edge
	 */
	public function test_查無回null(): void {
		$this->assertNull( SfRegion::from_names( '高雄市', '中正區' ) );
		$this->assertNull( SfRegion::from_names( '火星市', '中正區' ) );
		$this->assertNull( SfRegion::from_postcode( '999' ) );
		$this->assertNull( SfRegion::from_postcode( 'abc' ) );
		$this->assertNull( SfRegion::from_postcode( '' ) );
		$this->assertNull( SfRegion::resolve( '', '', '', '' ) );
	}

	/**
	 * Smoke：資料涵蓋 22 縣市 371 鄉鎮市區
	 *
	 * @group smoke
	 */
	public function test_資料筆數(): void {
		$this->assertCount( 371, SfRegion::ROWS );
		$this->assertCount( 22, \array_unique( \array_column( SfRegion::ROWS, 1 ) ) );
	}
}
