<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;

/**
 * 順豐路由操作碼 opCode → ShippingState（查詢與推送共用同一張表）
 *
 * 官方沒有給映射；依 skill `tracking.md` 附錄 14 的說明文字判讀，對齊 zenbu `sf-opcode-state.ts`，上線前需以真實推送校準。
 *  - 異常 / 滯留（70 / 77 / 33 / 934）刻意判 in_transit 而非 failed：70 的原因碼含「經客戶同意更改收派時間」等良性情況。
 *  - 99 = 轉寄或退回共用碼：只有推送的 otherInfo 為 2（返回）/ 46（簽收後退回）才判 returned。
 *  - 認不得的碼回 null（不更新貨態；不回 pending——對已出貨單是回退）。
 *
 * @see .claude/skills/sf-express/references/tracking.md 附錄 14、補充：opCode 映射建議
 */
final class SfOpcodeMapper {

	/** @var array<int|string, ShippingState> opCode → 統一狀態（純數字鍵在 PHP 會轉成 int 鍵） */
	private const MAP = [
		'50'         => ShippingState::ACCEPTED,
		'54'         => ShippingState::ACCEPTED,
		'43'         => ShippingState::ACCEPTED,
		'607'        => ShippingState::ACCEPTED,
		'655'        => ShippingState::ACCEPTED,
		'700'        => ShippingState::ACCEPTED,
		'950'        => ShippingState::ACCEPTED,
		'30'         => ShippingState::IN_TRANSIT,
		'31'         => ShippingState::IN_TRANSIT,
		'10'         => ShippingState::IN_TRANSIT,
		'11'         => ShippingState::IN_TRANSIT,
		'105'        => ShippingState::IN_TRANSIT,
		'106'        => ShippingState::IN_TRANSIT,
		'208'        => ShippingState::IN_TRANSIT,
		'649'        => ShippingState::IN_TRANSIT,
		'123'        => ShippingState::IN_TRANSIT,
		'44'         => ShippingState::IN_TRANSIT,
		'204'        => ShippingState::IN_TRANSIT,
		'880'        => ShippingState::IN_TRANSIT,
		'634'        => ShippingState::IN_TRANSIT,
		'630'        => ShippingState::IN_TRANSIT,
		'EXCHECKIN'  => ShippingState::IN_TRANSIT,
		'EXCHECKOUT' => ShippingState::IN_TRANSIT,
		'70'         => ShippingState::IN_TRANSIT,
		'77'         => ShippingState::IN_TRANSIT,
		'33'         => ShippingState::IN_TRANSIT,
		'934'        => ShippingState::IN_TRANSIT,
		'130'        => ShippingState::ARRIVED,
		'G07'        => ShippingState::ARRIVED,
		'678'        => ShippingState::ARRIVED,
		'642'        => ShippingState::ARRIVED,
		'80'         => ShippingState::DELIVERED,
		'8000'       => ShippingState::DELIVERED,
		'679'        => ShippingState::DELIVERED,
		'658'        => ShippingState::DELIVERED,
		'935'        => ShippingState::DELIVERED,
		'631'        => ShippingState::RETURNED,
		'G99'        => ShippingState::RETURNED,
	];

	/** @var string 轉寄或退回共用碼 */
	private const FORWARD_OR_RETURN = '99';

	/** @var array<int, string> 99 的退回子碼（2 返回 / 46 簽收後退回） */
	private const RETURN_SUB_CODES = [ '2', '46' ];

	/**
	 * 映射 opCode
	 *
	 * @param string $op_code    opCode
	 * @param string $other_info 推送 otherInfo（只在 99 時有意義）
	 * @return ShippingState|null 認不得回 null
	 */
	public static function map( string $op_code, string $other_info = '' ): ?ShippingState {
		$code = \trim( $op_code );
		if (self::FORWARD_OR_RETURN === $code) {
			return \in_array( \trim( $other_info ), self::RETURN_SUB_CODES, true ) ? ShippingState::RETURNED : ShippingState::IN_TRANSIT;
		}
		return self::MAP[ $code ] ?? null;
	}

	/**
	 * 解析事件時間：localTm（當地時間 yyyy-MM-dd HH:mm:ss）+ gmt（GMT+08:00 / GMT- 08:30）→ unix 秒
	 *
	 * 缺 gmt 視為 +08:00（台灣件）；gmt 有值但認不得 / 日期不存在 → null（猜錯時區會排錯事件順序）。
	 *
	 * @param string $local_tm 當地時間
	 * @param string $gmt      時區
	 * @return int|null
	 */
	public static function parse_time( string $local_tm, string $gmt = '' ): ?int {
		if (1 !== \preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', \trim( $local_tm ), $m )) {
			return null;
		}
		[ , $year, $month, $day, $hour, $minute, $second ] = \array_map( 'intval', $m );
		if (!\checkdate( $month, $day, $year ) || $hour > 23 || $minute > 59 || $second > 59) {
			return null;
		}

		$offset = 8 * 3600;
		$gmt    = \trim( $gmt );
		if ('' !== $gmt) {
			if (1 !== \preg_match( '/^GMT\s*([+-])\s*(\d{1,2}):(\d{2})$/', $gmt, $g ) || (int) $g[2] > 14 || (int) $g[3] > 59) {
				return null;
			}
			$offset = ( (int) $g[2] * 3600 + (int) $g[3] * 60 ) * ( '-' === $g[1] ? -1 : 1 );
		}

		return \gmmktime( $hour, $minute, $second, $month, $day, $year ) - $offset;
	}

	/**
	 * 從無序軌跡取「時間最晚且 opCode 認得」的一筆（時間解析不出來的不參與）
	 *
	 * @template T of array<string, mixed>
	 * @param array<int, T> $items 軌跡（鍵：opCode / localTm / gmt / otherInfo）
	 * @return T|null
	 */
	public static function pick_latest( array $items ): ?array {
		$latest      = null;
		$latest_time = PHP_INT_MIN;
		foreach ( $items as $item ) {
			if (null === self::map( self::field( $item, 'opCode' ), self::field( $item, 'otherInfo' ) )) {
				continue;
			}
			$time = self::parse_time( self::field( $item, 'localTm' ), self::field( $item, 'gmt' ) );
			if (null === $time) {
				continue;
			}
			// 同秒取先出現的（官方查詢範例以時間倒序列出）
			if ($time > $latest_time) {
				$latest      = $item;
				$latest_time = $time;
			}
		}
		return $latest;
	}

	/**
	 * 讀字串欄位（數字也收：官方標 String 的欄位實際不一定是字串）
	 *
	 * @param array<string, mixed> $item 軌跡
	 * @param string               $key  欄位
	 * @return string
	 */
	public static function field( array $item, string $key ): string {
		$value = $item[ $key ] ?? '';
		return \is_string( $value ) || \is_int( $value ) || \is_float( $value ) ? \trim( (string) $value ) : '';
	}
}
