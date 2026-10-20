<?php
/**
 * 藍新 NewebPay MPG MerchantOrderNo 編解碼
 *
 * 藍新 MerchantOrderNo 規格（NDNF-1.2.2）：
 *  - 英數字（alphanumeric），長度上限 30 碼。
 *  - per-MerchantID 唯一，送出過就不能再送（取號後再送回 MPG03008，issue #25）→ 每次付款嘗試用 encode_unique 換新編號。
 *
 * ⚠️ 與綠界 AIO MerchantTradeNo（≤20）不同：藍新上限 30，且藍新明定「英數字」，
 *    故本 helper「不含底線」（綠界範本 TradeNo 雖也只用英數，但語意獨立，避免耦合）。
 *
 * 格式：PC{order_id}T{反轉後的 timestamp}，截斷至 30 碼。
 * 反轉 timestamp 讓相鄰訂單編號前綴差異化，降低高併發下的重複機率（仍以 order_id 保證唯一）。
 *
 * @see .claude/skills/newebpay-mpg/references/api-reference.md §MerchantOrderNo（alphanumeric, max 30）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers;

/** 藍新 MPG MerchantOrderNo 編解碼 */
final class MpgOrderNo {

	/** @var int MerchantOrderNo 長度上限（藍新規格） */
	private const MAX_LENGTH = 30;

	/** @var string 前綴（英數，便於辨識與反解；'T' 為 order_id 與 timestamp 的分隔） */
	private const PREFIX = 'PC';

	/** @var string order_id 與 timestamp 的分隔字元 */
	private const SEPARATOR = 'T';

	/** @var int encode_unique 最多嘗試次數（每次 timestamp +1 秒，實務上 1~2 次即可） */
	private const MAX_UNIQUE_ATTEMPTS = 100;

	/**
	 * 由訂單 ID 生成唯一的 MerchantOrderNo（英數，≤30 碼）
	 *
	 * @param int $order_id 訂單 ID
	 * @return string MerchantOrderNo
	 */
	public static function encode( int $order_id ): string {
		return self::encode_at( $order_id, \time() );
	}

	/**
	 * 由訂單 ID 生成不在 $used 清單內的 MerchantOrderNo（格式同 {@see self::encode()}）
	 *
	 * 藍新同一 MerchantOrderNo 送出後不可再送（取號後未繳費也一樣，回 MPG03008 已存在相同的商店訂單編號），
	 * 顧客重試付款時須換新編號；encode() 以秒為單位，同一秒內重試改用下一秒的 timestamp 重算。
	 *
	 * @param int           $order_id 訂單 ID
	 * @param array<string> $used     本訂單已送出過的 MerchantOrderNo
	 * @return string MerchantOrderNo
	 * @throws \RuntimeException 嘗試上限內仍無法產生不重複編號（理論上不會發生）
	 */
	public static function encode_unique( int $order_id, array $used ): string {
		$now = \time();
		for ( $offset = 0; $offset < self::MAX_UNIQUE_ATTEMPTS; $offset++ ) {
			$candidate = self::encode_at( $order_id, $now + $offset );
			if ( ! \in_array( $candidate, $used, true ) ) {
				return $candidate;
			}
		}

		throw new \RuntimeException( "無法為訂單 #{$order_id} 產生不重複的 MerchantOrderNo" );
	}

	/**
	 * 以指定 timestamp 組 MerchantOrderNo
	 *
	 * @param int $order_id  訂單 ID
	 * @param int $timestamp Unix timestamp
	 * @return string MerchantOrderNo（≤30 碼）
	 */
	private static function encode_at( int $order_id, int $timestamp ): string {
		$order_no = self::PREFIX . $order_id . self::SEPARATOR . \strrev( (string) $timestamp );
		return \substr( $order_no, 0, self::MAX_LENGTH );
	}

	/**
	 * 由 MerchantOrderNo 反解出訂單 ID
	 *
	 * 取 PREFIX 之後、最後一個 SEPARATOR 之前的數字段（容錯：找不到分隔則回 PREFIX 之後全部）。
	 *
	 * @param string $order_no MerchantOrderNo
	 * @return string 訂單 ID（字串；無法解析時回空字串）
	 */
	public static function decode( string $order_no ): string {
		if ( ! \str_starts_with( $order_no, self::PREFIX ) ) {
			return '';
		}

		$without_prefix = \substr( $order_no, \strlen( self::PREFIX ) );
		$sep_pos        = \strrpos( $without_prefix, self::SEPARATOR );

		if ( false === $sep_pos ) {
			return $without_prefix;
		}

		return \substr( $without_prefix, 0, $sep_pos );
	}
}
