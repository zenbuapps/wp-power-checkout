<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Shared\Utils;

use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\ETaxType;

/**
 * 訂單 Utils
 * 因為 HPOS & 傳統訂單儲存，許多地方要判斷 2 次
 * 使用這 Utils 做統一判斷
 */
final class OrderUtils {

	/**  @return bool Is HPOS enabled  */
	public static function is_hpos_enabled(): bool {
		return \class_exists(\Automattic\WooCommerce\Utilities\OrderUtil::class) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	/** @return bool Is Order Detail Page */
	public static function is_order_detail( $hook = '' ): bool {
		if (!$hook && isset($_GET['page'])) { // phpcs:ignore
			return 'wc-orders' === $_GET['page'] && isset($_GET['id']); // phpcs:ignore
		}

		if ('woocommerce_page_wc-orders' === $hook) { // HOPS
			return true;
		}

		if ('post.php' === $hook && 'shop_order' === \get_post_type() ) {
			return true;
		}

		return false;
	}

	/** @return int|null 在 Order detail page 取得 order id */
	public static function get_order_id( $hook = '' ): int|null {
		if (!self::is_order_detail($hook)) {
			return null;
		}
		return ( (int) ( @$_GET['post'] ?? @$_GET['id'] ) ) ?: null; // phpcs:ignore
	}

	/**
	 * 取得完整地址
	 *
	 * @param \WC_Order $order 訂單
	 * @param array     $override_args 覆寫參數
	 * @param string    $separator 分隔符號
	 *
	 * @return string
	 */
	public static function get_full_address( \WC_Order $order, array $override_args = [], string $separator = '' ): string {
		$default = [
			'address_1' => $order->get_billing_address_1(),
			'address_2' => $order->get_billing_address_2(),
			'city'      => $order->get_billing_city(),
			'state'     => $order->get_billing_state(),
			'country'   => $order->get_billing_country(),
			'postcode'  => $order->get_billing_postcode(),
		];

		$args = \wp_parse_args($override_args, $default );

		return \wp_specialchars_decode( \WC()->countries->get_formatted_address( $args, $separator ) );
	}


	/**
	 * 取得 order item TaxType
	 *
	 * @param \WC_Order_Item $item Order Item
	 *
	 * @return ETaxType
	 */
	public static function get_tax_type( \WC_Order_Item $item ): ETaxType {
		$tax_type = (int) $item->get_meta('_pc_tax_type');
		return ETaxType::tryFrom( $tax_type) ?? ETaxType::TAXABLE;
	}

	/**
	 * 取得訂單
	 *
	 * @param string|int $order_id 訂單號
	 *
	 * @return \WC_Order 訂單
	 * @throws \Exception 解析失敗
	 */
	public static function get_order( string|int $order_id ): \WC_Order {
		$order = \wc_get_order($order_id);
		if (!$order instanceof \WC_Order) {
			throw new \Exception("找不到訂單 order_id:{$order_id}");
		}
		return $order;
	}
}
