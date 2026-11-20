<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;

/**
 * 新竹物流貨況（searchGoods status_code / status 文字）→ 統一 ShippingState
 *
 * - 主路徑：status_code（Ver3.1 新增，62 碼）查表；表外代碼 → PENDING（保守，不用文字覆寫刻意未列的碼，如 H04 客戶不在）
 * - status_code 缺漏時才以中文 status 關鍵字比對（否定語境優先：「客戶不在 / 保管中」先於「送達」；「取消」先於「退貨」）
 * - 宅配無門市取件語意 → 不輸出 READY_FOR_PICKUP / PICKED_UP / EXPIRED_UNCLAIMED
 * - 映射為起手建議（skill §7），上線前以真實回傳資料校準
 *
 * @see .claude/skills/hct-logistics/references/cargo-tracking-decrypt.md §6、§7
 */
final class HctStatusMapper {

	/** @var array<string, ShippingState> status_code → ShippingState */
	private const CODE_MAP = [
		'A01' => ShippingState::ACCEPTED,
		'R04' => ShippingState::ACCEPTED,
		'A04' => ShippingState::ARRIVED,
		'A02' => ShippingState::IN_TRANSIT,
		'A03' => ShippingState::IN_TRANSIT,
		'A05' => ShippingState::IN_TRANSIT,
		'A06' => ShippingState::IN_TRANSIT,
		'R09' => ShippingState::IN_TRANSIT,
		'R10' => ShippingState::IN_TRANSIT,
		'R11' => ShippingState::IN_TRANSIT,
		'R12' => ShippingState::IN_TRANSIT,
		'R13' => ShippingState::IN_TRANSIT,
		'H09' => ShippingState::IN_TRANSIT,
		'H10' => ShippingState::IN_TRANSIT,
		'O01' => ShippingState::IN_TRANSIT,
		'O02' => ShippingState::IN_TRANSIT,
		'O03' => ShippingState::IN_TRANSIT,
		'O04' => ShippingState::IN_TRANSIT,
		'O05' => ShippingState::IN_TRANSIT,
		'H01' => ShippingState::DELIVERED,
		'H08' => ShippingState::DELIVERED,
		'C01' => ShippingState::DELIVERED,
		'H17' => ShippingState::RETURNED,
		'C10' => ShippingState::RETURNED,
		'C14' => ShippingState::RETURNED,
		'C15' => ShippingState::RETURNED,
		'R06' => ShippingState::FAILED,
		'R07' => ShippingState::FAILED,
		'R08' => ShippingState::FAILED,
		'R14' => ShippingState::FAILED,
		'R15' => ShippingState::FAILED,
		'R16' => ShippingState::FAILED,
		'H02' => ShippingState::FAILED,
		'H03' => ShippingState::FAILED,
		'H05' => ShippingState::FAILED,
		'H06' => ShippingState::FAILED,
		'H07' => ShippingState::FAILED,
		'H12' => ShippingState::FAILED,
		'H13' => ShippingState::FAILED,
		'C04' => ShippingState::FAILED,
		'C05' => ShippingState::FAILED,
		'C06' => ShippingState::FAILED,
		'C13' => ShippingState::FAILED,
		'C25' => ShippingState::FAILED,
		'R19' => ShippingState::CANCELLED,
	];

	/**
	 * 映射
	 *
	 * @param string $status_code 貨況代碼（可空）
	 * @param string $status_text 貨況文字（可空）
	 * @return ShippingState
	 */
	public static function map( string $status_code, string $status_text = '' ): ShippingState {
		$code = \strtoupper( \trim( $status_code ) );
		if ('' !== $code) {
			return self::CODE_MAP[ $code ] ?? ShippingState::PENDING;
		}

		$text = \trim( $status_text );
		return match ( true ) {
			'' === $text => ShippingState::PENDING,
			self::has( $text, '/客戶不在|保管中/u' ) => ShippingState::IN_TRANSIT,
			self::has( $text, '/送達/u' ) => ShippingState::DELIVERED,
			self::has( $text, '/取消/u' ) => ShippingState::CANCELLED,
			self::has( $text, '/退回|退貨/u' ) => ShippingState::RETURNED,
			self::has( $text, '/拒(絕)?收|查無此人|地址(異常|錯誤)|無人接聽|破損|缺件|短少|喪失|遺失/u' ) => ShippingState::FAILED,
			self::has( $text, '/分貨中/u' ) => ShippingState::ARRIVED,
			self::has( $text, '/取件完成/u' ) => ShippingState::ACCEPTED,
			self::has( $text, '/配送中|運送|前往配送|轉運|整理中|委外|配送途中/u' ) => ShippingState::IN_TRANSIT,
			default => ShippingState::PENDING,
		};
	}

	/**
	 * 正則比對
	 *
	 * @param string $text    文字
	 * @param string $pattern 正則
	 * @return bool
	 */
	private static function has( string $text, string $pattern ): bool {
		return 1 === \preg_match( $pattern, $text );
	}
}
