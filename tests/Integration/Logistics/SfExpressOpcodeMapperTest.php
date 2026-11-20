<?php
/**
 * 順豐路由操作碼 opCode → ShippingState 測試（P6：sf_express）
 *
 * 映射表對齊 zenbu `sf-opcode-state.ts`（依 skill `tracking.md` 附錄 14 的說明文字判讀，官方沒有給映射）：
 *  - 50/54/43/607/655/700/950 → accepted；30/31/44/204/634/70/77/33… → in_transit（異常件刻意不判 failed）
 *  - 130/G07/678/642 → arrived；80/8000/679/658/935 → delivered；631/G99 → returned
 *  - 99 = 轉寄或退回共用碼：otherInfo 2 / 46 → returned，其餘 → in_transit
 *  - 認不得的碼 → null（不更新貨態，不回退成 pending）
 * 時間：localTm（當地時間）+ gmt（GMT+08:00 / GMT-08:30）→ unix 秒；缺 gmt 視為 +08:00；格式錯 → null
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter SfExpressOpcodeMapper --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfOpcodeMapper;
use Tests\Integration\TestCase;

/**
 * 順豐 opCode 映射
 *
 * @group integration
 * @group logistics
 * @group sf_express
 */
final class SfExpressOpcodeMapperTest extends TestCase {

	/**
	 * Data：代表碼
	 *
	 * @return array<string, array{0: string, 1: string, 2: ShippingState|null}>
	 */
	public function opcode_cases(): array {
		return [
			'50 已收件'          => [ '50', '', ShippingState::ACCEPTED ],
			'950 電商收件'        => [ '950', '', ShippingState::ACCEPTED ],
			'30 裝車'           => [ '30', '', ShippingState::IN_TRANSIT ],
			'31 到達'           => [ '31', '', ShippingState::IN_TRANSIT ],
			'44 派送中'          => [ '44', '', ShippingState::IN_TRANSIT ],
			'634 港澳台二程派件'     => [ '634', '', ShippingState::IN_TRANSIT ],
			'70 問題件不判 failed'  => [ '70', '', ShippingState::IN_TRANSIT ],
			'EXCHECKIN'        => [ 'EXCHECKIN', '', ShippingState::IN_TRANSIT ],
			'130 到達代理點'       => [ '130', '', ShippingState::ARRIVED ],
			'G07'              => [ 'G07', '', ShippingState::ARRIVED ],
			'80 已簽收'          => [ '80', '', ShippingState::DELIVERED ],
			'8000'             => [ '8000', '', ShippingState::DELIVERED ],
			'631 退回'          => [ '631', '', ShippingState::RETURNED ],
			'G99'              => [ 'G99', '', ShippingState::RETURNED ],
			'99 + 2 返回'       => [ '99', '2', ShippingState::RETURNED ],
			'99 + 46 簽收後退回'   => [ '99', '46', ShippingState::RETURNED ],
			'99 + 1 轉寄'       => [ '99', '1', ShippingState::IN_TRANSIT ],
			'99 無 otherInfo'   => [ '99', '', ShippingState::IN_TRANSIT ],
			'前後空白'            => [ ' 80 ', '', ShippingState::DELIVERED ],
			'未知碼'             => [ '12345', '', null ],
			'空字串'             => [ '', '', null ],
			'原型鏈名稱'           => [ 'constructor', '', null ],
		];
	}

	/**
	 * Happy：opCode 映射
	 *
	 * @dataProvider opcode_cases
	 * @group happy
	 *
	 * @param string             $op_code    opCode
	 * @param string             $other_info otherInfo
	 * @param ShippingState|null $expected   預期
	 */
	public function test_opCode映射( string $op_code, string $other_info, ?ShippingState $expected ): void {
		$this->assertSame( $expected, SfOpcodeMapper::map( $op_code, $other_info ) );
	}

	/**
	 * Happy：localTm + gmt → unix 秒
	 *
	 * @group happy
	 */
	public function test_時間解析(): void {
		$this->assertSame( \strtotime( '2026-11-21 01:30:00 UTC' ), SfOpcodeMapper::parse_time( '2026-11-21 09:30:00', 'GMT+08:00' ) );
		$this->assertSame( \strtotime( '2026-11-21 01:30:00 UTC' ), SfOpcodeMapper::parse_time( '2026-11-21 09:30:00', '' ), '缺 gmt 視為 +08:00' );
		$this->assertSame( \strtotime( '2026-11-21 18:00:00 UTC' ), SfOpcodeMapper::parse_time( '2026-11-21 09:30:00', 'GMT-08:30' ) );
		$this->assertSame( \strtotime( '2026-11-21 01:30:00 UTC' ), SfOpcodeMapper::parse_time( '2026-11-21 09:30:00', 'GMT+ 08:00' ), '查詢文件寫法 GMT+/- 08:00' );
	}

	/**
	 * Edge：時間格式錯 / 不存在的日期 / 認不得的時區 → null
	 *
	 * @group edge
	 */
	public function test_時間解析失敗回null(): void {
		$this->assertNull( SfOpcodeMapper::parse_time( '2026/11/21 09:30:00', 'GMT+08:00' ) );
		$this->assertNull( SfOpcodeMapper::parse_time( '2026-02-30 09:30:00', 'GMT+08:00' ) );
		$this->assertNull( SfOpcodeMapper::parse_time( '2026-11-21 25:30:00', 'GMT+08:00' ) );
		$this->assertNull( SfOpcodeMapper::parse_time( '2026-11-21 09:30:00', 'UTC+8' ) );
		$this->assertNull( SfOpcodeMapper::parse_time( '', '' ) );
	}

	/**
	 * Happy：無序軌跡取時間最晚、且認得的那一筆
	 *
	 * @group happy
	 */
	public function test_取最新認得的軌跡(): void {
		$items = [
			[
				'opCode'  => '50',
				'localTm' => '2026-11-20 10:00:00',
				'gmt'     => 'GMT+08:00',
			],
			[
				'opCode'  => '80',
				'localTm' => '2026-11-21 09:30:00',
				'gmt'     => 'GMT+08:00',
			],
			[
				'opCode'  => '99999',
				'localTm' => '2026-11-22 09:30:00',
				'gmt'     => 'GMT+08:00',
			],
			[
				'opCode'  => '30',
				'localTm' => 'bad time',
				'gmt'     => 'GMT+08:00',
			],
		];

		$latest = SfOpcodeMapper::pick_latest( $items );

		$this->assertNotNull( $latest );
		$this->assertSame( '80', $latest['opCode'] );
		$this->assertNull( SfOpcodeMapper::pick_latest( [] ) );
		$this->assertNull( SfOpcodeMapper::pick_latest( [ [ 'opCode' => '12345', 'localTm' => '2026-11-21 09:30:00' ] ] ) );
	}
}
