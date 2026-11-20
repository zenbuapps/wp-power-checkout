<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\DTOs;

/**
 * LINE Pay 付款請求（POST /v4/payments/request）參數
 *
 * 官方金額規則：amount = Σ packages[].amount；packages[].amount = Σ(products[].price × quantity)。
 * 一律以**整數 TWD** 組（金額由後端訂單決定，不信任前端）：
 *  - 商品行：行小計（含稅、已扣 coupon）四捨五入；能被數量整除 → price × qty，否則 qty=1、名稱標「× N」
 *  - 運費（含稅）合成一行「運費」；手續費每筆一行
 *  - 四捨五入差額 > 0 → 補一行「金額調整」
 *  - 出現負數（折抵型手續費、差額為負）或無任何正數行 → 整單收斂為單一 product（price = amount），
 *    避免送出 LINE Pay 可能拒收的負數價格，且總和永遠等於訂單總額
 *
 * @see .claude/skills/linepay/references/api-reference.md §1
 */
final class RequestParams {

	/** @var int products[].name 長度上限 */
	private const NAME_MAX_LENGTH = 4000;

	/**
	 * Constructor
	 *
	 * @param int                                                        $amount      付款金額（整數 TWD）
	 * @param string                                                     $order_id    LINE Pay orderId
	 * @param array<int, array{name: string, quantity: int, price: int}> $products    商品行
	 * @param string                                                     $confirm_url confirmUrl
	 * @param string                                                     $cancel_url  cancelUrl
	 */
	private function __construct(
		public readonly int $amount,
		public readonly string $order_id,
		public readonly array $products,
		public readonly string $confirm_url,
		public readonly string $cancel_url,
	) {
	}

	/**
	 * 由訂單建立
	 *
	 * @param \WC_Order $order            訂單
	 * @param string    $linepay_order_id LINE Pay orderId
	 * @param string    $confirm_url      confirmUrl
	 * @param string    $cancel_url       cancelUrl
	 * @return self
	 * @throws \InvalidArgumentException 金額不是正整數
	 */
	public static function from_order( \WC_Order $order, string $linepay_order_id, string $confirm_url, string $cancel_url ): self {
		$amount = (int) \round( (float) $order->get_total() );
		if ( $amount <= 0 ) {
			throw new \InvalidArgumentException( 'LINE Pay 付款金額必須為正整數' );
		}

		return new self(
			amount: $amount,
			order_id: $linepay_order_id,
			products: self::build_products( $order, $amount ),
			confirm_url: $confirm_url,
			cancel_url: $cancel_url,
		);
	}

	/** @return array<string, mixed> 請求 body */
	public function to_array(): array {
		return [
			'amount'       => $this->amount,
			'currency'     => 'TWD',
			'orderId'      => $this->order_id,
			'packages'     => [
				[
					'id'       => '1',
					'amount'   => $this->amount,
					'products' => $this->products,
				],
			],
			'redirectUrls' => [
				'confirmUrl' => $this->confirm_url,
				'cancelUrl'  => $this->cancel_url,
			],
			'options'      => [
				'display' => [
					'locale' => 'zh_TW',
				],
			],
		];
	}

	/**
	 * 組商品行（總和保證 = $amount）
	 *
	 * @param \WC_Order $order  訂單
	 * @param int       $amount 付款金額
	 * @return array<int, array{name: string, quantity: int, price: int}>
	 */
	private static function build_products( \WC_Order $order, int $amount ): array {
		$products = [];
		$names    = [];

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$name    = $item->get_name();
			$names[] = $name;
			$total   = (int) \round( (float) $order->get_line_total( $item, true, false ) );
			$qty     = \max( 1, (int) $item->get_quantity() );
			if ( 0 === $total ) {
				continue;
			}
			$products[] = 0 === $total % $qty
			? self::product( $name, $qty, \intdiv( $total, $qty ) )
			: self::product( "{$name} × {$qty}", 1, $total );
		}

		$shipping = 0;
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			$shipping += (float) $order->get_line_total( $item, true, false );
		}
		$shipping = (int) \round( $shipping );
		if ( 0 !== $shipping ) {
			$products[] = self::product( \__( '運費', 'power_checkout' ), 1, $shipping );
		}

		foreach ( $order->get_items( 'fee' ) as $item ) {
			$total = (int) \round( (float) $order->get_line_total( $item, true, false ) );
			if ( 0 !== $total ) {
				$products[] = self::product( $item->get_name(), 1, $total );
			}
		}

		$sum  = \array_sum( \array_map( static fn( array $p ): int => $p['price'] * $p['quantity'], $products ) );
		$diff = $amount - $sum;
		if ( $diff > 0 ) {
			$products[] = self::product( \__( '金額調整', 'power_checkout' ), 1, $diff );
		}

		$has_negative = [] !== \array_filter( $products, static fn( array $p ): bool => $p['price'] < 0 );
		if ( [] === $products || $diff < 0 || $has_negative ) {
			$label = [] !== $names ? \implode( '、', $names ) : \sprintf( \__( '訂單 #%s', 'power_checkout' ), $order->get_order_number() );
			return [ self::product( $label, 1, $amount ) ];
		}

		return $products;
	}

	/**
	 * 單一商品行
	 *
	 * @param string $name     名稱
	 * @param int    $quantity 數量
	 * @param int    $price    單價
	 * @return array{name: string, quantity: int, price: int}
	 */
	private static function product( string $name, int $quantity, int $price ): array {
		$name = \trim( \wp_strip_all_tags( $name ) );
		return [
			'name'     => \mb_substr( '' === $name ? '-' : $name, 0, self::NAME_MAX_LENGTH ),
			'quantity' => $quantity,
			'price'    => $price,
		];
	}
}
