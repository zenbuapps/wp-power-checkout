<?php
/**
 * 黑貓宅急便日期規則測試（P4）
 *
 * - 出貨日 / 希望配達日：週日不配送；配達日 > 出貨日（E090）且 ≤ 出貨日 + 7（E091）
 * - 逆物流 TakeDate：限 D+1~D+7；隔日取件須在前一上班日 16:30 前呼叫（文件 16:30 / 17:30 矛盾，取嚴格值 16:30）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter TcatLogisticsDates --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Exceptions\TcatLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatLogisticsDates;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\TestCase;

/**
 * 黑貓宅急便日期規則
 *
 * @group integration
 * @group logistics
 * @group tcat_logistics
 */
final class TcatLogisticsDatesTest extends TestCase {

	/**
	 * 台北時間
	 *
	 * @param string $datetime Y-m-d H:i
	 * @return \DateTimeImmutable
	 */
	private static function tw( string $datetime ): \DateTimeImmutable {
		return new \DateTimeImmutable( $datetime, new \DateTimeZone( 'Asia/Taipei' ) );
	}

	/**
	 * Happy：平日出貨 → 出貨日當天、配達日隔天
	 *
	 * @group happy
	 */
	public function test_平日出貨與配達日(): void {
		$now = self::tw( '2026-11-24 10:00' ); // 週二

		$this->assertSame( '20261124', TcatLogisticsDates::shipment_date( $now ) );
		$this->assertSame( '20261125', TcatLogisticsDates::delivery_date( '20261124' ) );
	}

	/**
	 * Edge：週六出貨 → 配達日跳過週日到週一；週日不出貨 → 順延週一
	 *
	 * @group edge
	 */
	public function test_週日不出貨不配送(): void {
		$this->assertSame( '20261130', TcatLogisticsDates::delivery_date( '20261128' ) ); // 週六 → 週一
		$this->assertSame( '20261130', TcatLogisticsDates::shipment_date( self::tw( '2026-11-29 09:00' ) ) ); // 週日 → 週一
	}

	/**
	 * Happy：16:30 前預設隔日取件；16:30（含）後預設後天取件
	 *
	 * @group happy
	 */
	public function test_預設取件日依1630截止(): void {
		$this->assertSame( '20261125', TcatLogisticsDates::take_date( self::tw( '2026-11-24 16:29' ) ) );
		$this->assertSame( '20261126', TcatLogisticsDates::take_date( self::tw( '2026-11-24 16:30' ) ) );
	}

	/**
	 * Error：16:30 後指定隔日取件 → VALIDATION（取嚴格值 16:30）
	 *
	 * @group error
	 */
	public function test_1630後指定隔日取件為VALIDATION(): void {
		try {
			TcatLogisticsDates::take_date( self::tw( '2026-11-24 16:31' ), '20261125' );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
			$this->assertStringContainsString( '16:30', $e->getMessage() );
		}
	}

	/**
	 * Happy：16:30 前指定隔日取件 OK
	 *
	 * @group happy
	 */
	public function test_1630前可指定隔日取件(): void {
		$this->assertSame( '20261125', TcatLogisticsDates::take_date( self::tw( '2026-11-24 16:29' ), '20261125' ) );
	}

	/**
	 * Error：取件日超出 D+1~D+7、格式錯、週日 → VALIDATION
	 *
	 * @dataProvider invalid_take_dates
	 * @group error
	 *
	 * @param string $requested 指定取件日
	 */
	public function test_取件日不合法( string $requested ): void {
		$this->expectException( TcatLogisticsException::class );
		TcatLogisticsDates::take_date( self::tw( '2026-11-24 10:00' ), $requested );
	}

	/**
	 * 不合法取件日
	 *
	 * @return array<string, array{0: string}>
	 */
	public function invalid_take_dates(): array {
		return [
			'當天 D+0'  => [ '20261124' ],
			'過去'      => [ '20261120' ],
			'D+8'     => [ '20261202' ],
			'格式錯'     => [ '2026-11-25' ],
			'週日'      => [ '20261129' ],
		];
	}
}
