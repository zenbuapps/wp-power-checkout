<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers;

/**
 * 黑貓PAY 契客訂單編號（cust_order_no）
 *
 * 格式 `PCT{order_id}-{5 碼英數}`：
 *  - 平台 cust_order_no 同一契客代號下永久唯一（重送報「您已經上傳過此一契約訂單號碼」），
 *    同訂單重新付款必須換新編號 → 每次嘗試帶隨機後綴，歷史保留於 meta 供 APN 反查。
 *  - 長度 ≤25（CVS 統一金流上限）；字元集只用英數與減號（COCS 規定「三位數以上英數字或減號」）。
 */
final class TcatPayTradeNo {

	/** @var string 前綴 */
	public const PREFIX = 'PCT';

	/** @var int 長度上限（CVS 統一金流 ≤25） */
	public const MAX_LENGTH = 25;

	/** @var int 隨機後綴長度 */
	private const SUFFIX_LENGTH = 5;

	/** @var string 隨機後綴字元集（去除易混淆的 I / O） */
	private const ALPHABET = '0123456789ABCDEFGHJKLMNPQRSTUVWXYZ';

	/**
	 * 產生新編號
	 *
	 * @param int $order_id 訂單 ID
	 * @return string
	 * @throws \InvalidArgumentException 超過長度上限
	 */
	public static function generate( int $order_id ): string {
		$suffix = '';
		for ( $i = 0; $i < self::SUFFIX_LENGTH; $i++ ) {
			$suffix .= self::ALPHABET[ \random_int( 0, \strlen( self::ALPHABET ) - 1 ) ];
		}

		$trade_no = self::PREFIX . $order_id . '-' . $suffix;
		if ( \strlen( $trade_no ) > self::MAX_LENGTH ) {
			throw new \InvalidArgumentException( '黑貓PAY 交易編號超過長度上限' );
		}
		return $trade_no;
	}
}
