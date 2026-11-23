<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers;

/**
 * 黑貓PAY order_detail（法規必填品名，不可含 HTML tag）
 *
 * COCS ≤500；CVS 若開 PDF 綜合繳款單則 ≤150（無法得知商家是否開啟，CVS 一律以 150 保守截斷）。
 */
final class ItemName {

	/** @var int COCS 上限 */
	public const COCS_MAX = 500;

	/** @var int CVS 上限（PDF 綜合繳款單） */
	public const CVS_MAX = 150;

	/**
	 * 組品名「商品A x1, 商品B x2」並截斷
	 *
	 * @param \WC_Order $order 訂單
	 * @param int       $max   字數上限
	 * @return string
	 */
	public static function build( \WC_Order $order, int $max ): string {
		$names = [];
		foreach ( $order->get_items() as $item ) {
			$name = \trim( \wp_strip_all_tags( \html_entity_decode( (string) $item->get_name(), ENT_QUOTES, 'UTF-8' ) ) );
			if ( '' === $name ) {
				continue;
			}
			$qty     = $item instanceof \WC_Order_Item_Product ? (int) $item->get_quantity() : 1;
			$names[] = "{$name} x{$qty}";
		}

		$detail = \implode( ', ', $names );
		if ( '' === $detail ) {
			/* translators: %s: 訂單編號 */
			$detail = \sprintf( \__( '訂單 #%s', 'power_checkout' ), $order->get_order_number() );
		}

		if ( \mb_strlen( $detail ) > $max ) {
			$detail = \mb_substr( $detail, 0, $max - 1 ) . '…';
		}
		return $detail;
	}
}
