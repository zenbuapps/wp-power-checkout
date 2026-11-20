<?php
/**
 * OrderResolver（缺陷 C：Webhook 統一查單邏輯）
 *
 * 原本 Webhook 只認 _pc_payment_identity 反查訂單，而該 meta 的唯一寫入點就是「客戶導回」——
 * 客戶沒導回（關掉分頁 / 網路中斷）就永遠找不到訂單，付款無法認列。
 *
 * 本類別提供付款 identity → 結帳 sessionId → 數字型 referenceOrderId 的查單順序，
 * 並在備援命中時回填付款 identity。Session/SDK 的 referenceOrderId 可能是 RL 交易編號，
 * 因此以建立 session 時存入的 _pc_identity 連結訂單。
 *
 * 資安：
 *  - 每條路徑都必須驗證 payment_method 屬於 SLP gateway 白名單（導轉式 / 嵌入式），不得誤配其他 gateway 的訂單。
 *  - identity 命中結果額外做數字型 referenceOrderId 或 sessionId 一致性複驗。
 *
 * @see specs/open-issue/issue-18-plan.md §流程 2
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Plugin;

/** Webhook 查單解析器 */
final class OrderResolver {

	/** @var array<int, string> SLP gateway ID 白名單（查單只接受這些付款方式的訂單） */
	private const SLP_GATEWAY_IDS = [ RedirectGateway::ID, EmbeddedGateway::ID ];

	/**
	 * 依 tradeOrderId / referenceOrderId 解析出訂單
	 *
	 * @param string $trade_order_id     SLP 付款交易訂單編號（_pc_payment_identity）
	 * @param string $reference_order_id 數字型 WC 訂單編號或 SLP 的 RL 交易參考編號
	 * @param string $session_id         SLP 結帳 sessionId（Session/SDK 流程）
	 *
	 * @return \WC_Order|null 找到的訂單，皆未命中時回傳 null（不 throw）
	 */
	public static function resolve( string $trade_order_id, string $reference_order_id, string $session_id = '' ): ?\WC_Order {
		// ① identity 優先
		$order = self::resolve_by_identity( $trade_order_id, $reference_order_id, $session_id );
		if ($order instanceof \WC_Order) {
			return $order;
		}

		// ② Session/SDK 付款的 referenceOrderId 可能是 RL 編號；結帳 sessionId 才是訂單識別碼。
		$order = self::resolve_by_session( $trade_order_id, $reference_order_id, $session_id );
		if ($order instanceof \WC_Order) {
			return $order;
		}

		// ③ 舊版數字型 referenceOrderId 備援
		return self::resolve_by_reference( $trade_order_id, $reference_order_id );
	}


	/**
	 * 以 _pc_payment_identity 反查，並做 gateway 與訂單識別碼一致性複驗
	 *
	 * @param string $trade_order_id     SLP 付款交易訂單編號
	 * @param string $reference_order_id 特店訂單號
	 * @param string $session_id         SLP 結帳 sessionId
	 *
	 * @return \WC_Order|null 命中且驗證通過的訂單
	 */
	private static function resolve_by_identity( string $trade_order_id, string $reference_order_id, string $session_id ): ?\WC_Order {
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

		// 數字型 referenceOrderId 明確指向別張訂單時，不採用此付款 identity 命中結果。
		if (\ctype_digit($reference_order_id) && $reference_order_id !== (string) $order->get_id()) {
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
		if ('' !== $session_id && !\ctype_digit($reference_order_id)) {
			$stored_session_id = ( new MetaKeys( $order ) )->get_identity();
			if ('' === $stored_session_id || !\hash_equals($stored_session_id, $session_id)) {
				return null;
			}
		}

		return $order;
	}

	/** @return \WC_Order|null 以 SLP 結帳 sessionId 查單，並驗證數字型訂單編號沒有衝突 */
	private static function resolve_by_session( string $trade_order_id, string $reference_order_id, string $session_id ): ?\WC_Order {
		$order = MetaKeys::get_order_by_identity_key( $session_id );
		if (!$order instanceof \WC_Order || !self::is_slp_order( $order )) {
			return null;
		}
		if (\ctype_digit($reference_order_id) && $reference_order_id !== (string) $order->get_id()) {
			return null;
		}
		if ('' !== $trade_order_id) {
			( new MetaKeys( $order ) )->update_payment_identity( $trade_order_id );
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
	 * 驗證訂單是否為 SLP 付款訂單（gateway ID 白名單：導轉式 + 嵌入式）
	 *
	 * 嵌入式（站內付）沒有 session，付款結果只來自 trade.* 事件，與導轉式共用同一個 webhook 端點（ASM-3 / R7）。
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return bool 是否為 SLP 訂單
	 */
	private static function is_slp_order( \WC_Order $order ): bool {
		return \in_array( $order->get_payment_method(), self::SLP_GATEWAY_IDS, true );
	}
}
