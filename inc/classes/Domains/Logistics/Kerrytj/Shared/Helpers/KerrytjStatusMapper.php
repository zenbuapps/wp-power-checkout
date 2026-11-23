<?php
/**
 * 嘉里大榮貨態代碼 → 統一 ShippingState
 *
 * 規格書有三張代碼表，同一代碼在不同表意義不同（`91`：常溫表 1 = 轉交嘉里快遞、低溫表 = 配達），
 * 查表鍵 = 溫層 × 表別 × 代碼：
 *  - 常溫 BLNListTracing `StatusCodeType` 1（缺漏視為 1）→ 常溫表 1（正物流配送）
 *  - 常溫 `StatusCodeType` 2 → 常溫表 2（取件 / 退貨）→ 一律 null：退貨單事件若套到正物流訂單，「配達結案」會把退貨誤判成送達
 *  - 低溫 ColdBLNListTracing（無 StatusCodeType）→ 低溫表
 *
 * 判讀原則（移植 zenbu kerrytj-tracking.ts，上線前以真實貨況校準）：
 *  - 異常不判 FAILED（客戶不在 / 另約之後通常會再配送）；只有遺失 / 丟棄 / 過期處理判 FAILED
 *  - 站止 / 門市 / 物流櫃待領 → READY_FOR_PICKUP（PC ShippingState 有此態；zenbu 因自身 mapper 限制用 arrived）
 *  - 說明性代碼（04 訂正取消 / 63 件數更正 / 68 更正後訂正取消 / A1 發送登錄）與表外代碼 → null（不更新貨態）
 *  - 低溫表沒有「已送回寄件人」終態，`93 貨件退回` 為最接近者 → RETURNED（同 zenbu）
 *
 * @see .claude/skills/kerrytj-logistics/references/tracking.md §4
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjBlnKind;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;

/** 貨態映射 */
final class KerrytjStatusMapper {

	/** @var array<int, string> 常溫表 1 已送達 */
	private const AMBIENT_DELIVERED = [ '02', '09', '30', '36', '38', '62', '78' ];

	/** @var array<int, string> 常溫表 1 待收件人領取 */
	private const AMBIENT_READY = [ '08', '24', '72', '96', 'B1', 'F1' ];

	/** @var array<int, string> 常溫表 1 不可逆失敗 */
	private const AMBIENT_FAILED = [ '18', '64', '66' ];

	/** @var array<int, string> 常溫表 1 運送中（含之後通常會再配送的異常與退回途中） */
	private const AMBIENT_IN_TRANSIT = [
		'00',
		'03',
		'05',
		'06',
		'10',
		'11',
		'12',
		'13',
		'14',
		'15',
		'16',
		'17',
		'19',
		'22',
		'25',
		'26',
		'29',
		'31',
		'32',
		'33',
		'34',
		'35',
		'37',
		'39',
		'40',
		'41',
		'42',
		'43',
		'45',
		'46',
		'49',
		'50',
		'51',
		'52',
		'53',
		'54',
		'55',
		'56',
		'57',
		'58',
		'59',
		'65',
		'67',
		'69',
		'73',
		'74',
		'75',
		'76',
		'79',
		'82',
		'83',
		'84',
		'85',
		'86',
		'87',
		'88',
		'89',
		'90',
		'91',
		'92',
		'93',
		'94',
		'95',
		'97',
		'99',
		'A2',
		'A3',
		'A4',
		'B2',
		'B3',
		'F0',
		'F2',
		'F3',
	];

	/** @var array<int, string> 低溫表運送中 */
	private const COLD_IN_TRANSIT = [
		'15',
		'38',
		'40',
		'41',
		'43',
		'47',
		'53',
		'54',
		'58',
		'63',
		'71',
		'73',
		'8E',
		'80',
		'81',
		'82',
		'83',
		'85',
		'86',
		'87',
		'88',
		'89',
		'94',
		'95',
		'96',
		'97',
		'99',
	];

	/**
	 * 代碼 → ShippingState
	 *
	 * @param KerrytjBlnKind $kind             單號種類（常溫 / 低溫）
	 * @param int|null       $status_code_type 常溫表別（1 / 2；低溫為 null）
	 * @param string         $code             StatusCode
	 * @return ShippingState|null 不更新貨態回 null
	 */
	public static function map( KerrytjBlnKind $kind, ?int $status_code_type, string $code ): ?ShippingState {
		$code = \strtoupper( \trim( $code ) );
		if ('' === $code) {
			return null;
		}
		if (KerrytjBlnKind::COLD === $kind) {
			return self::map_cold( $code );
		}
		if (2 === $status_code_type) {
			return null;
		}
		return self::map_ambient( $code );
	}

	/**
	 * 常溫表 1
	 *
	 * @param string $code 代碼
	 * @return ShippingState|null
	 */
	private static function map_ambient( string $code ): ?ShippingState {
		return match ( true ) {
			'01' === $code => ShippingState::ACCEPTED,
			'77' === $code => ShippingState::RETURNED,
			\in_array( $code, self::AMBIENT_DELIVERED, true ) => ShippingState::DELIVERED,
			\in_array( $code, self::AMBIENT_READY, true ) => ShippingState::READY_FOR_PICKUP,
			\in_array( $code, self::AMBIENT_FAILED, true ) => ShippingState::FAILED,
			\in_array( $code, self::AMBIENT_IN_TRANSIT, true ) => ShippingState::IN_TRANSIT,
			default => null,
		};
	}

	/**
	 * 低溫表
	 *
	 * @param string $code 代碼
	 * @return ShippingState|null
	 */
	private static function map_cold( string $code ): ?ShippingState {
		return match ( true ) {
			\in_array( $code, [ '13', '22' ], true ) => ShippingState::ACCEPTED,
			\in_array( $code, [ '90', '91', '92' ], true ) => ShippingState::DELIVERED,
			'93' === $code => ShippingState::RETURNED,
			'84' === $code => ShippingState::READY_FOR_PICKUP,
			\in_array( $code, self::COLD_IN_TRANSIT, true ) => ShippingState::IN_TRANSIT,
			default => null,
		};
	}
}
