<?php
/**
 * PAYUNi UPP V2 交易單號 MerTradeNo 編解碼（冪等）
 *
 * PAYUNi MerTradeNo 規格（payuni-upp-v2 §EncryptInfo 通用請求參數）：
 *  - 長度 ≤ 25 碼。
 *  - 字元集 [A-Za-z0-9_-]。
 *  - 同一 MerID 10 分鐘內不可重複（UPP01007）。
 *
 * 兩種格式：
 *  - 舊格式 PCU{order_id}（{@see self::generate()}；升級前的訂單仍可能用這個編號，解析時須相容）。
 *  - 每次付款嘗試的新格式 PCU{order_id}_{timestamp}（{@see self::encode_unique()}）。
 *    同一編號在 10 分鐘內重送會被 PAYUNi 拒絕（UPP01007），ATM / 超商取號後再送同一編號則會變成第二筆交易，
 *    所以顧客每次重新付款都換新編號（比照綠界 AIO / 藍新 MPG），舊編號保留在歷史 meta 供通知反查。
 *  - PCU 前綴：辨識本外掛 PAYUNi 單號 + 與其他 provider 區隔；order_id 內嵌，便於人工對帳。
 *
 * @see .claude/skills/payuni-upp-v2/SKILL.md §MerTradeNo
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers;

/** PAYUNi UPP V2 MerTradeNo 編解碼（冪等） */
final class PayuniTradeNo {

	/** @var int MerTradeNo 長度上限（PAYUNi 規格） */
	private const MAX_LENGTH = 25;

	/** @var string 前綴（英數，便於辨識與反解） */
	private const PREFIX = 'PCU';

	/** @var string order_id 與 timestamp 的分隔字元 */
	private const SEPARATOR = '_';

	/** @var int encode_unique 最多嘗試次數（每次 timestamp +1 秒，實務上 1~2 次即可） */
	private const MAX_UNIQUE_ATTEMPTS = 100;

	/**
	 * 由訂單 ID 生成冪等的 MerTradeNo（≤25 碼、僅 [A-Za-z0-9_-]）
	 *
	 * 純函數：相同 order_id 必產生相同單號（冪等），不同 order_id 必不同。
	 *
	 * @param int $order_id 訂單 ID
	 * @return string MerTradeNo
	 */
	public static function generate( int $order_id ): string {
		$trade_no = self::PREFIX . $order_id;
		// 防禦：理論上 order_id 不會讓單號超過 25 碼，仍截斷以符合 PAYUNi 硬限制
		return \substr( $trade_no, 0, self::MAX_LENGTH );
	}

	/**
	 * 為一次新的付款嘗試產生不在 $used 清單內的 MerTradeNo（PCU{order_id}_{timestamp}，≤25 碼）
	 *
	 * @param int                $order_id 訂單 ID
	 * @param array<int, string> $used     本訂單已送出過的 MerTradeNo
	 * @return string MerTradeNo
	 * @throws \RuntimeException 嘗試上限內仍無法產生不重複編號（理論上不會發生）
	 */
	public static function encode_unique( int $order_id, array $used ): string {
		$now = \time();
		for ( $offset = 0; $offset < self::MAX_UNIQUE_ATTEMPTS; $offset++ ) {
			$candidate = \substr( self::PREFIX . $order_id . self::SEPARATOR . ( $now + $offset ), 0, self::MAX_LENGTH );
			if ( ! \in_array( $candidate, $used, true ) ) {
				return $candidate;
			}
		}

		throw new \RuntimeException( "無法為訂單 #{$order_id} 產生不重複的 PAYUNi MerTradeNo" );
	}

	/**
	 * 由 MerTradeNo 反解出訂單 ID
	 *
	 * 容錯：前綴不符 / 數字段缺失 → 回 null（不拋例外）。
	 *
	 * @param string $trade_no MerTradeNo
	 * @return int|null 訂單 ID（無法解析時回 null）
	 */
	public static function parse_order_id( string $trade_no ): int|null {
		if ( ! \str_starts_with( $trade_no, self::PREFIX ) ) {
			return null;
		}

		$without_prefix = \substr( $trade_no, \strlen( self::PREFIX ) );

		// 新格式 PCU{order_id}_{timestamp}：取分隔字元之前的數字段
		$sep_pos = \strpos( $without_prefix, self::SEPARATOR );
		if ( false !== $sep_pos ) {
			$suffix         = \substr( $without_prefix, $sep_pos + 1 );
			$without_prefix = \substr( $without_prefix, 0, $sep_pos );
			if ( '' === $suffix || ! \ctype_digit( $suffix ) ) {
				return null;
			}
		}

		// 前綴後必須是純數字（order_id 十進位），否則視為不合法
		if ( '' === $without_prefix || ! \ctype_digit( $without_prefix ) ) {
			return null;
		}

		return (int) $without_prefix;
	}
}
