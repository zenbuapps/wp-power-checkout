<?php
/**
 * OrderResolver（缺陷 C：Webhook 統一查單邏輯）
 *
 * 原本 Webhook 只認 _pc_payment_identity 反查訂單，而該 meta 的唯一寫入點就是「客戶導回」——
 * 客戶沒導回（關掉分頁 / 網路中斷）就永遠找不到訂單，付款無法認列。
 *
 * 本類別提供 identity 優先 → referenceOrderId 備援的兩段式查單，並在備援命中時回填 identity。
 * referenceOrderId 由我方於 CreateSessionDTO 送出（= $order->get_id()），SLP 於通知原樣回傳，
 * 是比 identity 更可靠、且對「客戶沒導回」情境免疫的查單主鍵。
 *
 * 資安：
 *  - 兩條路徑都必須驗證 payment_method === RedirectGateway::ID，不得誤配其他 gateway 的訂單。
 *  - identity 命中結果額外做 referenceOrderId 一致性複驗，防止 meta 被汙染後造成付款誤配。
 *
 * @see specs/open-issue/issue-18-plan.md §流程 2
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Plugin;

/** Webhook 查單解析器 */
final class OrderResolver {

	/**
	 * 依 tradeOrderId / referenceOrderId 解析出訂單
	 *
	 * @param string $trade_order_id     SLP 付款交易訂單編號（_pc_payment_identity）
	 * @param string $reference_order_id 特店訂單號（= WC order id）
	 *
	 * @return \WC_Order|null 找到的訂單，皆未命中時回傳 null（不 throw）
	 */
	public static function resolve( string $trade_order_id, string $reference_order_id ): ?\WC_Order {
		// ① identity 優先
		$order = self::resolve_by_identity( $trade_order_id, $reference_order_id );
		if ($order instanceof \WC_Order) {
			return $order;
		}

		// ② referenceOrderId 備援（對「客戶沒導回」的情境免疫）
		return self::resolve_by_reference( $trade_order_id, $reference_order_id );
	}


	/**
	 * 以 _pc_payment_identity 反查，並做 gateway 驗證與 referenceOrderId 一致性複驗
	 *
	 * @param string $trade_order_id     SLP 付款交易訂單編號
	 * @param string $reference_order_id 特店訂單號
	 *
	 * @return \WC_Order|null 命中且驗證通過的訂單
	 */
	private static function resolve_by_identity( string $trade_order_id, string $reference_order_id ): ?\WC_Order {
		if ('' === $trade_order_id) {
			return null;
		}

		$order = MetaKeys::get_order_by_identity_payment_key( $trade_order_id );
		if (!$order instanceof \WC_Order) {
			return null;
		}

		if (!self::is_slp_order( $order )) {
			return null;
		}

		// 一致性複驗：identity 命中的訂單必須與通知的 referenceOrderId 相符，
		// 不符代表 meta 可能被汙染（FM-4），改走 referenceOrderId 備援。
		if ('' !== $reference_order_id && $reference_order_id !== (string) $order->get_id()) {
			Plugin::logger(
				'⚠️ SLP 查單：identity 命中的訂單與通知的 referenceOrderId 不一致，疑似 meta 汙染',
				'warning',
				[
					'tradeOrderId'     => $trade_order_id,
					'referenceOrderId' => $reference_order_id,
					'matched_order_id' => $order->get_id(),
				]
			);
			return null;
		}

		return $order;
	}


	/**
	 * 以 referenceOrderId 備援查單，命中時回填 identity
	 *
	 * @param string $trade_order_id     SLP 付款交易訂單編號
	 * @param string $reference_order_id 特店訂單號
	 *
	 * @return \WC_Order|null 命中且驗證通過的訂單
	 */
	private static function resolve_by_reference( string $trade_order_id, string $reference_order_id ): ?\WC_Order {
		if (!\ctype_digit( $reference_order_id )) {
			return null;
		}

		$order = \wc_get_order( (int) $reference_order_id );
		if (!$order instanceof \WC_Order) {
			return null;
		}

		if (!self::is_slp_order( $order )) {
			return null;
		}

		// 回填 identity，讓後續通知能走較快的 identity 路徑
		if ('' !== $trade_order_id) {
			( new MetaKeys( $order ) )->update_payment_identity( $trade_order_id );
		}

		return $order;
	}


	/**
	 * 驗證訂單是否為 SLP 跳轉式付款訂單
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return bool 是否為 SLP 訂單
	 */
	private static function is_slp_order( \WC_Order $order ): bool {
		return RedirectGateway::ID === $order->get_payment_method();
	}
}
