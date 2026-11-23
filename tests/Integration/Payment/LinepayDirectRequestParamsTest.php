<?php
/**
 * LINE Pay 付款請求參數測試（P3）
 *
 * 官方金額規則（api-reference.md §1）：amount = Σ packages[].amount；packages[].amount = Σ(price × quantity)。
 * 一律以整數 TWD 組；運費 / 手續費為獨立 product 行；四捨五入差額以「金額調整」行補齊，
 * 有負數行（折扣型手續費）時整單收斂為單一 product，確保總和永遠等於訂單總額。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter LinepayDirectRequestParams --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Linepay\DTOs\RequestParams;
use Tests\Integration\Payment\Doubles\LinepayDirectTestTrait;
use Tests\Integration\TestCase;

/**
 * LINE Pay 請求參數測試
 *
 * @group integration
 * @group payment
 * @group linepay
 */
final class LinepayDirectRequestParamsTest extends TestCase {
	use LinepayDirectTestTrait;

	/** 每個測試前：啟用 LINE Pay */
	protected function configure_dependencies(): void {
		$this->setup_linepay();
	}

	/**
	 * 建參數
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 */
	private function params( \WC_Order $order ): array {
		return RequestParams::from_order(
			$order,
			'PCL' . $order->get_id() . '-1-ABC123',
			'https://example.com/confirm',
			'https://example.com/cancel'
		)->to_array();
	}

	/**
	 * 斷言官方金額規則
	 *
	 * @param array<string, mixed> $params 參數
	 * @return void
	 */
	private function assert_amount_rules( array $params ): void {
		$package_sum = 0;
		foreach ( $params['packages'] as $package ) {
			$product_sum = 0;
			foreach ( $package['products'] as $product ) {
				$this->assertIsInt( $product['price'] );
				$this->assertIsInt( $product['quantity'] );
				$product_sum += $product['price'] * $product['quantity'];
			}
			$this->assertSame( $package['amount'], $product_sum, 'package.amount 必須等於 Σ(price × quantity)' );
			$package_sum += $package['amount'];
		}
		$this->assertIsInt( $params['amount'] );
		$this->assertSame( $params['amount'], $package_sum, 'amount 必須等於 Σ packages.amount' );
	}

	/**
	 * 商品名稱清單
	 *
	 * @param array<string, mixed> $params 參數
	 * @return array<int, string>
	 */
	private static function product_names( array $params ): array {
		return \array_map( static fn( array $p ): string => (string) $p['name'], $params['packages'][0]['products'] );
	}

	/**
	 * Happy：多商品 + 運費 → 運費獨立一行，總和 = 訂單總額；基本欄位正確
	 *
	 * @group happy
	 */
	public function test_多商品含運費總和等於訂單金額(): void {
		$order  = $this->make_linepay_order(
			860.0,
			'pending',
			false,
			'linepay',
			[
				[ '筆記本', 2, 500.0 ],
				[ '鋼筆', 1, 300.0 ],
			],
			60.0
		);
		$params = $this->params( $order );

		$this->assert_amount_rules( $params );
		$this->assertSame( 860, $params['amount'] );
		$this->assertSame( 'TWD', $params['currency'] );
		$this->assertSame( 'PCL' . $order->get_id() . '-1-ABC123', $params['orderId'] );
		$this->assertSame( 'https://example.com/confirm', $params['redirectUrls']['confirmUrl'] );
		$this->assertSame( 'https://example.com/cancel', $params['redirectUrls']['cancelUrl'] );
		$this->assertSame( 'zh_TW', $params['options']['display']['locale'] );
		$this->assertArrayNotHasKey( 'payment', $params['options'], '自動請款（預設）不送 options.payment' );

		$products = $params['packages'][0]['products'];
		$this->assertSame( '筆記本', $products[0]['name'] );
		$this->assertSame( 2, $products[0]['quantity'] );
		$this->assertSame( 250, $products[0]['price'] );
		$this->assertContains( '運費', self::product_names( $params ) );
	}

	/**
	 * Happy：折扣已反映在商品行小計（WC coupon 行為）→ 依折後金額組，總和一致
	 *
	 * @group happy
	 */
	public function test_含折扣後商品金額總和一致(): void {
		$order = $this->make_linepay_order( 510.0, 'pending', false, 'linepay', [ [ '禮盒', 1, 450.0 ] ], 60.0 );

		$params = $this->params( $order );

		$this->assert_amount_rules( $params );
		$this->assertSame( 510, $params['amount'] );
	}

	/**
	 * Edge：含小數的行小計四捨五入後與總額有差 → 補「金額調整」行，總和仍一致
	 *
	 * @group edge
	 */
	public function test_小數總額以調整行補齊(): void {
		$order = $this->make_linepay_order(
			100.0,
			'pending',
			false,
			'linepay',
			[
				[ 'A', 1, 33.33 ],
				[ 'B', 1, 33.33 ],
				[ 'C', 1, 33.34 ],
			]
		);

		$params = $this->params( $order );

		$this->assert_amount_rules( $params );
		$this->assertSame( 100, $params['amount'] );
		$this->assertContains( '金額調整', self::product_names( $params ) );
	}

	/**
	 * Edge：行小計不能被數量整除 → 該行以數量 1、名稱標示「× N」
	 *
	 * @group edge
	 */
	public function test_不可整除的行改為數量1(): void {
		$order = $this->make_linepay_order( 100.0, 'pending', false, 'linepay', [ [ '貼紙', 3, 100.0 ] ] );

		$params   = $this->params( $order );
		$products = $params['packages'][0]['products'];

		$this->assert_amount_rules( $params );
		$this->assertSame( 1, $products[0]['quantity'] );
		$this->assertSame( 100, $products[0]['price'] );
		$this->assertStringContainsString( '× 3', $products[0]['name'] );
	}

	/**
	 * Edge：有負數行（折扣型手續費）→ 收斂為單一 product，price = amount
	 *
	 * @group edge
	 */
	public function test_負數行收斂為單一品項(): void {
		$order = $this->make_linepay_order( 950.0, 'pending', false, 'linepay', [ [ '商品', 1, 1000.0 ] ] );
		$fee   = new \WC_Order_Item_Fee();
		$fee->set_name( '會員折抵' );
		$fee->set_total( '-50' );
		$order->add_item( $fee );
		$order->save();

		$params   = $this->params( \wc_get_order( $order->get_id() ) );
		$products = $params['packages'][0]['products'];

		$this->assert_amount_rules( $params );
		$this->assertSame( 950, $params['amount'] );
		$this->assertCount( 1, $products );
		$this->assertSame( 950, $products[0]['price'] );
		$this->assertSame( 1, $products[0]['quantity'] );
	}

	/**
	 * Edge：商品名稱超長截斷（products[].name ≤ 4000）、package id 與 currency 固定
	 *
	 * @group edge
	 */
	public function test_商品名稱過長截斷(): void {
		$order = $this->make_linepay_order( 100.0, 'pending', false, 'linepay', [ [ \str_repeat( '長', 5000 ), 1, 100.0 ] ] );

		$params = $this->params( $order );

		$this->assertLessThanOrEqual( 4000, \mb_strlen( $params['packages'][0]['products'][0]['name'] ) );
		$this->assertSame( '1', $params['packages'][0]['id'] );
	}
}
