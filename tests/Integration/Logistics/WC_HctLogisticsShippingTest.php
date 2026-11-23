<?php
/**
 * 新竹物流 WC 運送方式測試（P5）
 *
 * - 每個實例一個商品種類（001 一般 / 008 冷藏 / 003 冷凍）+ 固定運費；rate meta 帶 product_type
 * - provider 未啟用不產 rate；下單經 save_checkout_meta 白名單寫入 _pc_hct_logistics_product_type，建單沿用
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter WC_HctLogisticsShipping --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Hct\Services\HctLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Hct\Services\WC_HctLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\HctLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 新竹物流運送方式
 *
 * @group integration
 * @group logistics
 * @group hct_logistics
 */
final class WC_HctLogisticsShippingTest extends TestCase {
	use HctLogisticsTestTrait;

	/** 每個測試前：啟用新竹物流 */
	protected function configure_dependencies(): void {
		$this->setup_hct_logistics();
	}

	/** 清理 */
	public function tear_down(): void {
		\delete_option( ( new WC_HctLogisticsShipping() )->get_option_key() );
		$this->reset_hct_logistics();
		parent::tear_down();
	}

	/**
	 * 建立帶實例設定的運送方式
	 *
	 * @param array<string, string> $settings 實例設定
	 * @return WC_HctLogisticsShipping
	 */
	private function make_method( array $settings = [] ): WC_HctLogisticsShipping {
		\update_option( ( new WC_HctLogisticsShipping() )->get_option_key(), $settings );
		return new WC_HctLogisticsShipping();
	}

	/**
	 * 建立選用本運送方式的訂單
	 *
	 * @param string $product_type rate meta 商品種類
	 * @param string $method_id    運送方式 id
	 * @return \WC_Order
	 */
	private function make_order_with_shipping( string $product_type, string $method_id = WC_HctLogisticsShipping::METHOD_ID ): \WC_Order {
		$order = $this->make_hct_order();
		$item  = new \WC_Order_Item_Shipping();
		$item->set_method_id( $method_id );
		$item->set_method_title( '新竹物流' );
		$item->set_total( '150' );
		$item->add_meta_data( 'product_type', $product_type );
		$order->add_item( $item );
		$order->save();
		return $order;
	}

	/**
	 * Smoke：繼承 WC_Shipping_Method、METHOD_ID、全域設定 option 不撞 provider option
	 *
	 * @group smoke
	 */
	public function test_冒煙_類別與option_key(): void {
		$method = new WC_HctLogisticsShipping();

		$this->assertInstanceOf( \WC_Shipping_Method::class, $method );
		$this->assertSame( 'hct_logistics', WC_HctLogisticsShipping::METHOD_ID );
		$this->assertNotSame( ProviderUtils::get_option_name( HctLogisticsProvider::ID ), $method->get_option_key() );
	}

	/**
	 * Happy：固定運費 + 一般 → rate meta product_type=001
	 *
	 * @group happy
	 */
	public function test_固定運費與一般rate(): void {
		$method = $this->make_method( [ 'cost' => '120' ] );

		$method->calculate_shipping( [] );
		$rate = \reset( $method->rates );

		$this->assertCount( 1, $method->rates );
		$this->assertSame( 120.0, (float) $rate->get_cost() );
		$this->assertSame( '001', $rate->get_meta_data()['product_type'] ?? null );
	}

	/**
	 * Happy：冷凍實例 → rate meta 003、標籤含「冷凍」
	 *
	 * @group happy
	 */
	public function test_冷凍rate(): void {
		$method = $this->make_method( [ 'product_type' => '003' ] );

		$method->calculate_shipping( [] );
		$rate = \reset( $method->rates );

		$this->assertSame( '003', $rate->get_meta_data()['product_type'] ?? null );
		$this->assertStringContainsString( '冷凍', $rate->get_label() );
	}

	/**
	 * Edge：provider 未啟用 → 不產 rate
	 *
	 * @group edge
	 */
	public function test_provider未啟用不產rate(): void {
		ProviderUtils::update_option( HctLogisticsProvider::ID, 'enabled', 'no' );
		$method = $this->make_method();

		$method->calculate_shipping( [] );

		$this->assertCount( 0, $method->rates );
	}

	/**
	 * Happy：下單寫入商品種類 meta + provider id
	 *
	 * @group happy
	 */
	public function test_下單寫入商品種類meta(): void {
		$order = $this->make_order_with_shipping( '008' );

		ProviderRegister::save_checkout_meta( $order, [] );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( '008', $fresh->get_meta( '_pc_hct_logistics_product_type' ) );
		$this->assertSame( HctLogisticsProvider::ID, $fresh->get_meta( '_pc_hct_logistics_provider_id' ) );
	}

	/**
	 * Security：rate meta 被竄改為非法商品種類 → 不寫入；非本運送方式 → 不寫入
	 *
	 * @group security
	 */
	public function test_非法商品種類不寫入(): void {
		$tampered = $this->make_order_with_shipping( '999' );
		$other    = $this->make_order_with_shipping( '008', 'flat_rate' );

		WC_HctLogisticsShipping::save_checkout_meta( $tampered, [] );
		WC_HctLogisticsShipping::save_checkout_meta( $other, [] );

		$this->assertSame( '', \wc_get_order( $tampered->get_id() )->get_meta( '_pc_hct_logistics_product_type' ) );
		$this->assertSame( '', \wc_get_order( $other->get_id() )->get_meta( '_pc_hct_logistics_product_type' ) );
		$this->assertFalse( WC_HctLogisticsShipping::is_chosen( $other ) );
	}

	/**
	 * Happy：建單沿用訂單商品種類（冷凍）
	 *
	 * @group happy
	 */
	public function test_建單沿用訂單商品種類(): void {
		$order = $this->make_order_with_shipping( '003' );
		WC_HctLogisticsShipping::save_checkout_meta( $order, [] );
		$this->fake_hct( 'TransData_Json', 'trans_data_ok' );

		HctLogisticsProvider::instance()->create_shipment( \wc_get_order( $order->get_id() ) );

		$this->assertSame( '003', $this->hct_request_json( 'TransData_Json' )[0]['eprdcl2'] );
	}
}
