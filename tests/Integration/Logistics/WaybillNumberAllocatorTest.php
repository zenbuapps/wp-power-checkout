<?php
/**
 * 通用託運單號段配號器測試（P5：新竹物流建立，P7 嘉里大榮共用）
 *
 * - 號段（start / end，為「流水號本體」）存 option `pc_waybill_range_{key}`；目前值存 `pc_waybill_current_{key}`
 * - 配號以單一 SQL `UPDATE ... SET option_value = LAST_INSERT_ID(option_value + 1) WHERE ... < end` 原子遞增，
 *   不經 object cache「讀後寫」（同時兩請求不會配到同號）
 * - 號碼格式（檢查碼）由注入的 IWaybillNumberFormat 決定：新竹 = 9 碼本體 + MOD 7、嘉里大榮常溫 = 11 碼流水號
 * - 用罄 → NUMBER_EXHAUSTED（current 不超過 end）；未設定 → VALIDATION
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter WaybillNumberAllocator --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Shared\Exceptions\WaybillNumberException;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\Mod7CheckDigitFormat;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\PlainSerialFormat;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\IWaybillNumberFormat;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\WaybillNumberAllocator;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\TestCase;

/**
 * 號段配號器
 *
 * @group integration
 * @group logistics
 * @group waybill
 */
final class WaybillNumberAllocatorTest extends TestCase {

	/** @var string 測試用號段 key */
	private const KEY = 'test_waybill';

	/** 清理 */
	public function tear_down(): void {
		\delete_option( WaybillNumberAllocator::RANGE_OPTION_PREFIX . self::KEY );
		\delete_option( WaybillNumberAllocator::CURRENT_OPTION_PREFIX . self::KEY );
		parent::tear_down();
	}

	/**
	 * 新竹格式配號器
	 *
	 * @return WaybillNumberAllocator
	 */
	private function hct_allocator(): WaybillNumberAllocator {
		return new WaybillNumberAllocator( self::KEY, new Mod7CheckDigitFormat( 9 ) );
	}

	/**
	 * 直接讀 DB 的目前值（繞過 object cache）
	 *
	 * @return int|null
	 */
	private function db_current(): ?int {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", WaybillNumberAllocator::CURRENT_OPTION_PREFIX . self::KEY ) );
		return null === $value ? null : (int) $value;
	}

	/**
	 * 斷言拋出指定錯誤碼的 WaybillNumberException
	 *
	 * @param ErrorCode $code     錯誤碼
	 * @param callable  $callback 執行內容
	 * @return void
	 */
	private function assert_waybill_error( ErrorCode $code, callable $callback ): void {
		try {
			$callback();
			$this->fail( '預期拋出 WaybillNumberException' );
		} catch ( WaybillNumberException $e ) {
			$this->assertSame( $code, $e->error_code );
		}
	}

	/**
	 * Happy：依序配號（新竹 9 碼本體 + MOD 7 檢查碼），current 遞增
	 *
	 * @group happy
	 */
	public function test_依序配號且current遞增(): void {
		$allocator = $this->hct_allocator();
		$allocator->sync_range( 100173483, 100173490 );

		$this->assertSame( '1001734834', $allocator->allocate() );
		$this->assertSame( 100173483, $this->db_current() );
		$this->assertSame( '1001734845', $allocator->allocate() );
		$this->assertSame( 100173484, $this->db_current() );
	}

	/**
	 * Happy：status 回號段、目前值、下一號與剩餘量
	 *
	 * @group happy
	 */
	public function test_status回剩餘量與下一號(): void {
		$allocator = $this->hct_allocator();
		$allocator->sync_range( 100173483, 100173492 );
		$allocator->allocate();

		$status = $allocator->status();

		$this->assertTrue( $status['configured'] );
		$this->assertSame( 100173483, $status['start'] );
		$this->assertSame( 100173492, $status['end'] );
		$this->assertSame( 100173483, $status['current'] );
		$this->assertSame( 9, $status['remaining'] );
		$this->assertSame( '1001734845', $status['next'] );
	}

	/**
	 * Edge：連續兩次配號不重複
	 *
	 * @group edge
	 */
	public function test_連續兩次配號不重複(): void {
		$this->hct_allocator()->sync_range( 1, 100 );

		$first  = $this->hct_allocator()->allocate();
		$second = $this->hct_allocator()->allocate();

		$this->assertNotSame( $first, $second );
	}

	/**
	 * Edge：配號不讀 object cache（快取是舊值也不會重號）——證明是 DB 原子遞增而非讀後寫
	 *
	 * @group edge
	 */
	public function test_配號不受過期快取影響(): void {
		$allocator = $this->hct_allocator();
		$allocator->sync_range( 1, 100 );
		$first = $allocator->allocate();

		\wp_cache_set( WaybillNumberAllocator::CURRENT_OPTION_PREFIX . self::KEY, '0', 'options' );
		$second = $this->hct_allocator()->allocate();

		$this->assertNotSame( $first, $second );
		$this->assertSame( 2, $this->db_current() );
	}

	/**
	 * Edge：最後一號配出後用罄 → NUMBER_EXHAUSTED；current 不超過 end
	 *
	 * @group edge
	 */
	public function test_用罄後拋NUMBER_EXHAUSTED且current不超過end(): void {
		$allocator = $this->hct_allocator();
		$allocator->sync_range( 10, 11 );
		$allocator->allocate();
		$allocator->allocate();

		$this->assert_waybill_error( ErrorCode::NUMBER_EXHAUSTED, static fn() => $allocator->allocate() );
		$this->assert_waybill_error( ErrorCode::NUMBER_EXHAUSTED, static fn() => $allocator->allocate() );
		$this->assertSame( 11, $this->db_current() );
		$this->assertSame( 0, $allocator->status()['remaining'] );
		$this->assertSame( '', $allocator->status()['next'] );
	}

	/**
	 * Error：未設定號段 → VALIDATION
	 *
	 * @group error
	 */
	public function test_未設定號段拋VALIDATION(): void {
		$allocator = $this->hct_allocator();

		$this->assertFalse( $allocator->is_configured() );
		$this->assertFalse( $allocator->status()['configured'] );
		$this->assert_waybill_error( ErrorCode::VALIDATION, static fn() => $allocator->allocate() );
	}

	/**
	 * Error：號段不合法（起 > 迄、起 < 1、超過格式位數）→ VALIDATION，不寫入
	 *
	 * @group error
	 */
	public function test_號段不合法拋VALIDATION(): void {
		$allocator = $this->hct_allocator();

		$this->assert_waybill_error( ErrorCode::VALIDATION, static fn() => $allocator->sync_range( 200, 100 ) );
		$this->assert_waybill_error( ErrorCode::VALIDATION, static fn() => $allocator->sync_range( 0, 100 ) );
		$this->assert_waybill_error( ErrorCode::VALIDATION, static fn() => $allocator->sync_range( 1, 1000000000 ) );
		$this->assertFalse( $allocator->is_configured() );
	}

	/**
	 * Edge：調整號段——目前值仍在新號段內則保留（延長迄號不重配）；不在則從新起號開始
	 *
	 * @group edge
	 */
	public function test_調整號段保留或重設目前值(): void {
		$allocator = $this->hct_allocator();
		$allocator->sync_range( 100, 200 );
		$allocator->allocate();
		$allocator->allocate();

		$allocator->sync_range( 100, 300 );
		$this->assertSame( 101, $this->db_current() );

		$allocator->sync_range( 500, 600 );
		$this->assertSame( 499, $this->db_current() );
		$this->assertSame( '0000005003', $allocator->allocate() );
	}

	/**
	 * Edge：清除號段後視為未設定
	 *
	 * @group edge
	 */
	public function test_清除號段(): void {
		$allocator = $this->hct_allocator();
		$allocator->sync_range( 1, 10 );
		$allocator->clear_range();

		$this->assertFalse( $allocator->is_configured() );
	}

	/**
	 * Edge：可注入號碼格式策略（test double），配號器不依賴 provider
	 *
	 * @group edge
	 */
	public function test_可注入號碼格式策略(): void {
		$double    = new class() implements IWaybillNumberFormat {
			/** @var array<int, int> 收到的流水號 */
			public array $seen = [];

			/**
			 * @param int $serial 流水號
			 * @return string
			 */
			public function format( int $serial ): string {
				$this->seen[] = $serial;
				return "X{$serial}";
			}

			/** @return int */
			public function max_serial(): int {
				return 99;
			}
		};
		$allocator = new WaybillNumberAllocator( self::KEY, $double );
		$allocator->sync_range( 98, 99 );

		$this->assertSame( 'X98', $allocator->allocate() );
		$this->assertSame( 'X99', $allocator->allocate() );
		$this->assertSame( [ 98, 99 ], $double->seen );
		$this->assert_waybill_error( ErrorCode::VALIDATION, static fn() => $allocator->sync_range( 1, 100 ) );
	}

	/**
	 * Happy：內建格式——MOD 7（新竹 / 嘉里大榮低溫）與純流水號補零（嘉里大榮常溫 11 碼）
	 *
	 * @group happy
	 */
	public function test_內建號碼格式(): void {
		$mod7  = new Mod7CheckDigitFormat( 9 );
		$plain = new PlainSerialFormat( 11 );

		$this->assertSame( '1001734834', $mod7->format( 100173483 ) );
		$this->assertSame( '2250446752', $mod7->format( 225044675 ) );
		$this->assertSame( '0000000011', $mod7->format( 1 ) );
		$this->assertSame( 999999999, $mod7->max_serial() );
		$this->assertSame( '00000000042', $plain->format( 42 ) );
		$this->assertSame( 99999999999, $plain->max_serial() );
	}

	/**
	 * Error：非法 key（防 option 名稱注入）→ \InvalidArgumentException
	 *
	 * @group error
	 */
	public function test_非法key(): void {
		$this->expectException( \InvalidArgumentException::class );
		new WaybillNumberAllocator( "bad key'", new Mod7CheckDigitFormat( 9 ) );
	}
}
