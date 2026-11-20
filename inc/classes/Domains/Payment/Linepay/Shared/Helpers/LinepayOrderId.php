<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers;

/**
 * LINE Pay orderId：`PCL{order_id}-{attempt}-{6 碼隨機}`
 *
 * 此 orderId 對 LINE Pay 是永久唯一鍵（已 confirm 的 orderId 再建請求 → 1172），
 * 每次付款嘗試必須產生新值；隨機尾碼避免多個站台共用同一個 sandbox / 正式 Channel 時
 * 因 WooCommerce 訂單編號重複而撞號（orderId 上限 100 字元）。
 */
final class LinepayOrderId {

	/** @var string 前綴 */
	public const PREFIX = 'PCL';

	/**
	 * 產生 orderId
	 *
	 * @param int $order_id WC 訂單 ID
	 * @param int $attempt  付款嘗試序號
	 * @return string
	 */
	public static function generate( int $order_id, int $attempt ): string {
		$suffix = \strtoupper( \substr( \bin2hex( \random_bytes( 8 ) ), 0, 6 ) );
		return self::PREFIX . "{$order_id}-{$attempt}-{$suffix}";
	}
}
