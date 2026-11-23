<?php
/**
 * EmbeddedReference（SLP 嵌入式：每次付款嘗試的 referenceOrderId 產生與歸屬判定）
 *
 * SLP 拒絕同一 referenceOrderId 再次建立交易（1001 重複下單），而嵌入式付款失敗（拒卡 / 3DS 放棄）
 * 後顧客會在同一張訂單重付，因此每次 confirm 都要產生新的 referenceOrderId：
 *
 *   {order_id}-{attempt}-{6 碼大寫 hex}　例：6664-2-3FA91C（SLP String(32)，最長 20+1+4+1+6 = 32）
 *
 * 每次送出的 reference 都在打 API **之前**寫入訂單 meta `_pc_slp_reference_history`
 * （SLP 可能已建單但我方逾時，webhook 仍要能找得到單）。
 *
 * 歸屬鐵律（資安）：新格式 reference 只有在「格式中的訂單 ID 等於該訂單」**且**
 * 「確實存在於該訂單的 reference 歷史 meta」時，才判定屬於該訂單。
 * 歷史只由伺服器端 confirm 寫入，顧客 / 網址 / webhook 內容都無法寫入，
 * 因此 attempt 序號可被猜到也無妨；亂數尾碼只用來避免多環境共用商店時撞號。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Helpers;

/** SLP 嵌入式 referenceOrderId 產生與歸屬判定 */
final class EmbeddedReference {

	/** @var string 訂單 meta：本訂單送出過的所有 referenceOrderId（依嘗試順序） */
	public const HISTORY_META_KEY = '_pc_slp_reference_history';

	/** @var string 新格式正則：{order_id}-{attempt}-{6 hex}；群組 1 = 訂單 ID */
	private const PATTERN = '/^([1-9]\d{0,19})-([1-9]\d{0,3})-([0-9A-F]{6})$/';

	/** @var int SLP referenceOrderId 長度上限 */
	public const MAX_LENGTH = 32;

	/** @var int attempt 序號上限（4 位數） */
	private const MAX_ATTEMPT = 9999;

	/**
	 * 產生下一次付款嘗試的 referenceOrderId，並**先**寫入歷史 meta（含 save）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 * @throws \RuntimeException 嘗試次數超過上限或長度超出 SLP 限制
	 */
	public static function next( \WC_Order $order ): string {
		$history = self::get_history( $order );
		$attempt = \count( $history ) + 1;
		if ( $attempt > self::MAX_ATTEMPT ) {
			throw new \RuntimeException( "SLP 嵌入式訂單 #{$order->get_id()} 付款嘗試次數已達上限" );
		}

		do {
			$reference = \sprintf( '%d-%d-%s', $order->get_id(), $attempt, \strtoupper( \bin2hex( \random_bytes( 3 ) ) ) );
		} while ( \in_array( $reference, $history, true ) );

		if ( \strlen( $reference ) > self::MAX_LENGTH ) {
			throw new \RuntimeException( "SLP referenceOrderId {$reference} 超過長度上限" );
		}

		$history[] = $reference;
		$order->update_meta_data( self::HISTORY_META_KEY, $history );
		$order->save();

		return $reference;
	}

	/**
	 * 本訂單送出過的 referenceOrderId 歷史
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<int, string>
	 */
	public static function get_history( \WC_Order $order ): array {
		$value = $order->get_meta( self::HISTORY_META_KEY );
		if ( ! \is_array( $value ) ) {
			return [];
		}

		return \array_values( \array_filter( $value, 'is_string' ) );
	}

	/**
	 * 是否為新格式（嵌入式每次嘗試唯一）reference
	 *
	 * @param string $reference referenceOrderId
	 * @return bool
	 */
	public static function is_attempt_reference( string $reference ): bool {
		return 1 === \preg_match( self::PATTERN, $reference );
	}

	/**
	 * 新格式 reference 屬於哪張訂單（訂單 ID 必須等於格式內 ID，且 reference 在該訂單歷史內）
	 *
	 * @param string $reference referenceOrderId
	 * @return \WC_Order|null 不是新格式、訂單不存在或不在歷史 → null
	 */
	public static function find_order( string $reference ): ?\WC_Order {
		if ( 1 !== \preg_match( self::PATTERN, $reference, $matches ) ) {
			return null;
		}

		$order = \wc_get_order( (int) $matches[1] );
		if ( ! $order instanceof \WC_Order ) {
			return null;
		}

		return self::belongs_to( $order, $reference ) ? $order : null;
	}

	/**
	 * 新格式 reference 是否屬於此訂單
	 *
	 * @param \WC_Order $order     訂單
	 * @param string    $reference referenceOrderId
	 * @return bool 非新格式一律 false（舊格式歸屬由呼叫端既有規則判定）
	 */
	public static function belongs_to( \WC_Order $order, string $reference ): bool {
		if ( 1 !== \preg_match( self::PATTERN, $reference, $matches ) ) {
			return false;
		}

		if ( $matches[1] !== (string) $order->get_id() ) {
			return false;
		}

		return \in_array( $reference, self::get_history( $order ), true );
	}
}
