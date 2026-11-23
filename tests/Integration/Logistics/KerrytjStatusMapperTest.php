<?php
/**
 * 嘉里大榮貨態代碼 → ShippingState 測試（P7：kerrytj_logistics）
 *
 * 查表鍵 = 溫層 × 表別 × 代碼（skill tracking.md §4.4）：
 *  - 常溫 BLNListTracing StatusCodeType 1 → 常溫表 1（正物流配送）
 *  - 常溫 StatusCodeType 2 → 常溫表 2（取件 / 退貨）→ 一律 null（不套到正物流訂單）
 *  - 低溫 ColdBLNListTracing（無 StatusCodeType）→ 低溫表
 * 說明性代碼（04 / 63 / 68 / A1）與表外代碼 → null（不更新貨態）。向量移植自 zenbu kerrytj-tracking.spec.ts。
 * PC ShippingState 有 READY_FOR_PICKUP：站止 / 門市 / 物流櫃待領（08 / 24 / 72 / 96 / B1 / F1、低溫 84）映射為 READY_FOR_PICKUP。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter KerrytjStatusMapper --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjBlnKind;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjStatusMapper;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use Tests\Integration\TestCase;

/**
 * 嘉里大榮貨態映射
 *
 * @group integration
 * @group logistics
 * @group kerrytj_logistics
 */
final class KerrytjStatusMapperTest extends TestCase {

	/**
	 * Happy：91 常溫表 1 = 轉交嘉里快遞（運送中），低溫表 = 配達
	 *
	 * @group happy
	 */
	public function test_同一代碼依溫層查不同表(): void {
		$this->assertSame( ShippingState::IN_TRANSIT, KerrytjStatusMapper::map( KerrytjBlnKind::AMBIENT, 1, '91' ) );
		$this->assertSame( ShippingState::DELIVERED, KerrytjStatusMapper::map( KerrytjBlnKind::COLD, null, '91' ) );
	}

	/**
	 * Edge：常溫表 2（取件 / 退貨）一律不映射；同一個 02 在表 1 是配達
	 *
	 * @group edge
	 */
	public function test_常溫表2不映射(): void {
		$this->assertNull( KerrytjStatusMapper::map( KerrytjBlnKind::AMBIENT, 2, 'C1' ) );
		$this->assertNull( KerrytjStatusMapper::map( KerrytjBlnKind::AMBIENT, 2, '02' ) );
		$this->assertSame( ShippingState::DELIVERED, KerrytjStatusMapper::map( KerrytjBlnKind::AMBIENT, 1, '02' ) );
	}

	/**
	 * Happy：常溫表 1 代表碼
	 *
	 * @dataProvider ambient_codes
	 * @group happy
	 *
	 * @param string        $code  代碼
	 * @param ShippingState $state 預期
	 */
	public function test_常溫表1代表碼( string $code, ShippingState $state ): void {
		$this->assertSame( $state, KerrytjStatusMapper::map( KerrytjBlnKind::AMBIENT, 1, $code ) );
	}

	/** @return array<string, array{0: string, 1: ShippingState}> */
	public function ambient_codes(): array {
		return [
			'01 集貨'     => [ '01', ShippingState::ACCEPTED ],
			'02 配達'     => [ '02', ShippingState::DELIVERED ],
			'09 站止領回'   => [ '09', ShippingState::DELIVERED ],
			'30 聯運配達'   => [ '30', ShippingState::DELIVERED ],
			'78 代收門市取件' => [ '78', ShippingState::DELIVERED ],
			'77 送回寄件人'  => [ '77', ShippingState::RETURNED ],
			'08 站止未領'   => [ '08', ShippingState::READY_FOR_PICKUP ],
			'F1 全家進店完成' => [ 'F1', ShippingState::READY_FOR_PICKUP ],
			'00 客戶不在'   => [ '00', ShippingState::IN_TRANSIT ],
			'73 配送'     => [ '73', ShippingState::IN_TRANSIT ],
			'18 貨件遺失'   => [ '18', ShippingState::FAILED ],
			'64 丟棄'     => [ '64', ShippingState::FAILED ],
		];
	}

	/**
	 * Happy：低溫表代表碼
	 *
	 * @dataProvider cold_codes
	 * @group happy
	 *
	 * @param string        $code  代碼
	 * @param ShippingState $state 預期
	 */
	public function test_低溫表代表碼( string $code, ShippingState $state ): void {
		$this->assertSame( $state, KerrytjStatusMapper::map( KerrytjBlnKind::COLD, null, $code ) );
	}

	/** @return array<string, array{0: string, 1: ShippingState}> */
	public function cold_codes(): array {
		return [
			'13 集貨站受付'  => [ '13', ShippingState::ACCEPTED ],
			'22 貨件發送受付' => [ '22', ShippingState::ACCEPTED ],
			'73 配貨攜出'   => [ '73', ShippingState::IN_TRANSIT ],
			'84 站止未領'   => [ '84', ShippingState::READY_FOR_PICKUP ],
			'90 破損已收'   => [ '90', ShippingState::DELIVERED ],
			'92 自領'     => [ '92', ShippingState::DELIVERED ],
			'93 貨件退回'   => [ '93', ShippingState::RETURNED ],
		];
	}

	/**
	 * Edge：說明性 / 表外代碼 → null；大小寫不敏感
	 *
	 * @group edge
	 */
	public function test_說明性與表外代碼不映射_大小寫不敏感(): void {
		foreach ( [ '04', '63', '68', 'A1', '07', 'ZZ', '', 'constructor' ] as $code ) {
			$this->assertNull( KerrytjStatusMapper::map( KerrytjBlnKind::AMBIENT, 1, $code ), "常溫 {$code}" );
		}
		$this->assertNull( KerrytjStatusMapper::map( KerrytjBlnKind::COLD, null, '01' ) );
		$this->assertSame( ShippingState::IN_TRANSIT, KerrytjStatusMapper::map( KerrytjBlnKind::COLD, null, '8e' ) );
		$this->assertSame( ShippingState::READY_FOR_PICKUP, KerrytjStatusMapper::map( KerrytjBlnKind::AMBIENT, 1, 'f1' ) );
	}

	/**
	 * Edge：常溫事件缺 StatusCodeType 視為表 1
	 *
	 * @group edge
	 */
	public function test_常溫缺表別視為表1(): void {
		$this->assertSame( ShippingState::DELIVERED, KerrytjStatusMapper::map( KerrytjBlnKind::AMBIENT, null, '02' ) );
	}
}
