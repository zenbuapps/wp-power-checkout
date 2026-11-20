<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;

/**
 * 黑貓 OBTStatus 二代貨態碼（3 碼）→ ShippingState
 *
 * 對照 skill cargo-status.md §3 / §6 與 zenbu `tcat-logistics.service.ts` TCAT_STATUS_ID_STATE：
 *  - 111 已集貨 → accepted（**不可**映成 picked_up：picked_up 是「消費者取貨完成」）
 *  - 211~216 配送受阻但仍會再配、159/168 暫置 → in_transit
 *  - 208 / 308 貨在門市 → arrived；209 / 420 門市取回 / 轉交刪除 → returned（取保守側，避免靜默漏帳）
 *  - 301 配完 → delivered；161/302/303/305/309 → returned
 *  - 183~186、v2.0.0 移出文件的 241~246 → failed；181/182 → in_transit
 *  - 未知碼回 null（保留原始碼，絕不拋錯）；一代 5 碼（FTP .sod）不在本表
 */
final class TcatStatusMapper {

	/** @var array<int|string, ShippingState> 二代代碼表（數字字串鍵會被 PHP 轉成 int，查表時自動轉回） */
	private const MAP = [
		'111' => ShippingState::ACCEPTED,
		'151' => ShippingState::IN_TRANSIT,
		'152' => ShippingState::IN_TRANSIT,
		'153' => ShippingState::IN_TRANSIT,
		'154' => ShippingState::IN_TRANSIT,
		'155' => ShippingState::IN_TRANSIT,
		'159' => ShippingState::IN_TRANSIT,
		'168' => ShippingState::IN_TRANSIT,
		'181' => ShippingState::IN_TRANSIT,
		'182' => ShippingState::IN_TRANSIT,
		'202' => ShippingState::IN_TRANSIT,
		'204' => ShippingState::IN_TRANSIT,
		'205' => ShippingState::IN_TRANSIT,
		'211' => ShippingState::IN_TRANSIT,
		'212' => ShippingState::IN_TRANSIT,
		'213' => ShippingState::IN_TRANSIT,
		'214' => ShippingState::IN_TRANSIT,
		'215' => ShippingState::IN_TRANSIT,
		'216' => ShippingState::IN_TRANSIT,
		'208' => ShippingState::ARRIVED,
		'308' => ShippingState::ARRIVED,
		'209' => ShippingState::RETURNED,
		'420' => ShippingState::RETURNED,
		'301' => ShippingState::DELIVERED,
		'161' => ShippingState::RETURNED,
		'302' => ShippingState::RETURNED,
		'303' => ShippingState::RETURNED,
		'305' => ShippingState::RETURNED,
		'309' => ShippingState::RETURNED,
		'183' => ShippingState::FAILED,
		'184' => ShippingState::FAILED,
		'185' => ShippingState::FAILED,
		'186' => ShippingState::FAILED,
		'241' => ShippingState::FAILED,
		'242' => ShippingState::FAILED,
		'243' => ShippingState::FAILED,
		'244' => ShippingState::FAILED,
		'245' => ShippingState::FAILED,
		'246' => ShippingState::FAILED,
	];

	/**
	 * 映射（字串原樣比對，只 trim 半形 / 全形空白）
	 *
	 * @param string $status_id OBTStatus StatusId
	 * @return ShippingState|null 未知碼回 null
	 */
	public static function map( string $status_id ): ?ShippingState {
		$code = (string) \preg_replace( '/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $status_id );
		return self::MAP[ $code ] ?? null;
	}
}
