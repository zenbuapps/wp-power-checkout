<?php
/**
 * 嘉里大榮託運單號（BLN）格式測試（P7：kerrytj_logistics）
 *
 * - 常溫 11 碼流水號（無檢查碼，PlainSerialFormat(11)）；低溫 9 碼流水號 + MOD 7 檢查碼（Mod7CheckDigitFormat(9)）
 * - 向量移植自 zenbu kerrytj-bln.spec.ts：規格書範例 667000000 → 6670000002；
 *   規格書貨況範例單號 6343634496 / 6876384093 / 6088958363 驗算通過；6343634497 檢查碼錯
 * - 號段起訖解析：常溫須 11 碼；低溫收 9 碼流水號或檢查碼正確的 10 碼單號
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter KerrytjBln --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjBlnKind;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjBln;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\WaybillNumberAllocator;
use Tests\Integration\TestCase;

/**
 * 嘉里大榮 BLN
 *
 * @group integration
 * @group logistics
 * @group kerrytj_logistics
 */
final class KerrytjBlnTest extends TestCase {

	/** 清理 */
	public function tear_down(): void {
		\delete_option( WaybillNumberAllocator::RANGE_OPTION_PREFIX . 'kerrytj_bln_test' );
		\delete_option( WaybillNumberAllocator::CURRENT_OPTION_PREFIX . 'kerrytj_bln_test' );
		parent::tear_down();
	}

	/**
	 * Happy：低溫 MOD 7 golden（規格書範例 667000000 → 6670000002）
	 *
	 * @group happy
	 */
	public function test_低溫檢查碼_規格書範例(): void {
		$this->assertSame( '6670000002', KerrytjBln::format( KerrytjBlnKind::COLD )->format( 667000000 ) );
		$this->assertSame( KerrytjBlnKind::COLD, KerrytjBln::kind_of( '6670000002' ) );
	}

	/**
	 * Happy：規格書貨況範例低溫單號驗算通過
	 *
	 * @dataProvider cold_samples
	 * @group happy
	 *
	 * @param string $bln 單號
	 */
	public function test_規格書低溫範例單號判為低溫( string $bln ): void {
		$this->assertSame( KerrytjBlnKind::COLD, KerrytjBln::kind_of( $bln ) );
	}

	/** @return array<string, array{0: string}> */
	public function cold_samples(): array {
		return [
			'6343634496' => [ '6343634496' ],
			'6876384093' => [ '6876384093' ],
			'6088958363' => [ '6088958363' ],
		];
	}

	/**
	 * Edge：10 碼但檢查碼錯 → 不是合法單號
	 *
	 * @group edge
	 */
	public function test_低溫檢查碼錯誤不是合法單號(): void {
		$this->assertNull( KerrytjBln::kind_of( '6343634497' ) );
	}

	/**
	 * Edge：常溫 11 碼補零、無檢查碼；非數字 / 長度錯 → null
	 *
	 * @group edge
	 */
	public function test_常溫11碼補零與非法值(): void {
		$format = KerrytjBln::format( KerrytjBlnKind::AMBIENT );
		$this->assertSame( '40350718602', $format->format( 40350718602 ) );
		$this->assertSame( '00000000123', $format->format( 123 ) );
		$this->assertSame( 99999999999, $format->max_serial() );
		$this->assertSame( 999999999, KerrytjBln::format( KerrytjBlnKind::COLD )->max_serial() );
		$this->assertSame( KerrytjBlnKind::AMBIENT, KerrytjBln::kind_of( '40350718602' ) );
		$this->assertNull( KerrytjBln::kind_of( '4035071860A' ) );
		$this->assertNull( KerrytjBln::kind_of( '403507186021' ) );
		$this->assertNull( KerrytjBln::kind_of( '' ) );
	}

	/**
	 * Happy：號段起訖解析（常溫 11 碼；低溫 9 碼流水號或檢查碼正確的 10 碼）
	 *
	 * @group happy
	 */
	public function test_號段起訖解析(): void {
		$this->assertSame( 40350718600, KerrytjBln::parse_range_bound( KerrytjBlnKind::AMBIENT, '40350718600' ) );
		$this->assertNull( KerrytjBln::parse_range_bound( KerrytjBlnKind::AMBIENT, '4035071860' ) );
		$this->assertSame( 667000000, KerrytjBln::parse_range_bound( KerrytjBlnKind::COLD, '6670000002' ) );
		$this->assertSame( 667000009, KerrytjBln::parse_range_bound( KerrytjBlnKind::COLD, '667000009' ) );
		$this->assertNull( KerrytjBln::parse_range_bound( KerrytjBlnKind::COLD, '6670000003' ) );
		$this->assertNull( KerrytjBln::parse_range_bound( KerrytjBlnKind::COLD, 'abc' ) );
	}

	/**
	 * Edge：號段包含判斷（溫層不同即不在範圍內）
	 *
	 * @group edge
	 */
	public function test_號段包含判斷(): void {
		$this->assertTrue( KerrytjBln::in_range( '40350718605', KerrytjBlnKind::AMBIENT, 40350718600, 40350718610 ) );
		$this->assertFalse( KerrytjBln::in_range( '40350718611', KerrytjBlnKind::AMBIENT, 40350718600, 40350718610 ) );
		$this->assertTrue( KerrytjBln::in_range( '6670000002', KerrytjBlnKind::COLD, 667000000, 667000009 ) );
		$this->assertFalse( KerrytjBln::in_range( '6670000002', KerrytjBlnKind::AMBIENT, 40350718600, 40350718610 ) );
		$this->assertFalse( KerrytjBln::in_range( 'ZBLX9A2C3D4E5F6', KerrytjBlnKind::AMBIENT, 40350718600, 40350718610 ) );
	}

	/**
	 * Edge：沿用 P5 WaybillNumberAllocator 配號，號段邊界用罄後 current 不超過 end
	 *
	 * @group edge
	 */
	public function test_配號器邊界_低溫最後一號後用罄(): void {
		$allocator = new WaybillNumberAllocator( 'kerrytj_bln_test', KerrytjBln::format( KerrytjBlnKind::COLD ) );
		$allocator->sync_range( 667000000, 667000001 );

		$this->assertSame( '6670000002', $allocator->allocate() );
		$this->assertSame( '6670000013', $allocator->allocate() );
		try {
			$allocator->allocate();
			$this->fail( '預期用罄' );
		} catch ( \Throwable $e ) {
			$this->assertSame( 'NUMBER_EXHAUSTED', $e->error_code->value ?? null );
		}
		$this->assertSame( 0, $allocator->status()['remaining'] );
	}
}
