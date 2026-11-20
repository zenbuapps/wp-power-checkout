<?php
/**
 * WC_TcatLogisticsShipping 測試（P4）
 *
 * - 固定運費 rate（provider 未啟用不產 rate）
 * - 溫層（常溫 / 冷藏 / 冷凍）由運送方式實例設定決定，rate meta 帶 thermosphere，下單寫入 _pc_tcat_logistics_thermosphere
 * - 全域設定 option key 與 provider option（woocommerce_tcat_logistics_settings）分開，避免互相覆寫
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter WC_TcatLogisticsShipping --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\TcatLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\WC_TcatLogisticsShipping;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\TcatLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 黑貓宅急便運送方式
 *
 * @group integration
 * @group logistics
 * @group tcat_logistics
 */
final class WC_TcatLogisticsShippingTest extends TestCase {
	use TcatLogisticsTestTrait;

	/** 每個測試前：啟用黑貓宅急便 */
	protected function configure_dependencies(): void {
		$this->setup_tcat_logistics();
	}

	/** 清理 */
	public function tear_down(): void {
		\delete_option( ( new WC_TcatLogisticsShipping() )->get_option_key() );
		$this->reset_tcat_logistics();
		parent::tear_down();
	}

	/**
	 * 建立帶實例設定的運送方式
	 *
	 * @param array<string, string> $settings 實例設定
	 * @return WC_TcatLogisticsShipping
	 */
	private function make_method( array $settings = [] ): WC_TcatLogisticsShipping {
		\update_option( ( new WC_TcatLogisticsShipping() )->get_option_key(), $settings );
		return new WC_TcatLogisticsShipping();
	}

	/**
	 * 建立選用本運送方式的訂單（shipping item 帶 rate meta）
	 *
	 * @param string $thermosphere rate meta 溫層
	 * @param string $method_id    運送方式 id
	 * @return \WC_Order
	 */
	private function make_order_with_shipping( string $thermosphere, string $method_id = WC_TcatLogisticsShipping::METHOD_ID ): \WC_Order {
		$order = $this->make_tcat_order();
		$item  = new \WC_Order_Item_Shipping();
		$item->set_method_id( $method_id );
		$item->set_method_title( '黑貓宅急便' );
		$item->set_total( '150' );
		$item->add_meta_data( 'thermosphere', $thermosphere );
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
		$method = new WC_TcatLogisticsShipping();

		$this->assertInstanceOf( \WC_Shipping_Method::class, $method );
		$this->assertSame( 'tcat_logistics', WC_TcatLogisticsShipping::METHOD_ID );
		$this->assertNotSame( ProviderUtils::get_option_name( TcatLogisticsProvider::ID ), $method->get_option_key() );
	}

	/**
	 * Happy：固定運費 + 預設常溫 → 1 個 rate，meta thermosphere=0001
	 *
	 * @group happy
	 */
	public function test_固定運費與常溫rate(): void {
		$method = $this->make_method( [ 'cost' => '150' ] );

		$method->calculate_shipping( [] );
		$rates = $method->rates;

		$this->assertCount( 1, $rates );
		$rate = \reset( $rates );
		$this->assertSame( 150.0, (float) $rate->get_cost() );
		$this->assertSame( '0001', $rate->get_meta_data()['thermosphere'] ?? null );
	}

	/**
	 * Happy：冷凍實例 → rate meta 0003，標籤含「冷凍」
	 *
	 * @group happy
	 */
	public function test_冷凍溫層rate(): void {
		$method = $this->make_method( [ 'thermosphere' => '0003' ] );

		$method->calculate_shipping( [] );
		$rate = \reset( $method->rates );

		$this->assertSame( '0003', $rate->get_meta_data()['thermosphere'] ?? null );
		$this->assertStringContainsString( '冷凍', $rate->get_label() );
	}

	/**
	 * Edge：provider 未啟用 → 不產 rate（顧客不可選無法出貨的方式）
	 *
	 * @group edge
	 */
	public function test_provider未啟用不產rate(): void {
		ProviderUtils::update_option( TcatLogisticsProvider::ID, 'enabled', 'no' );
		$method = $this->make_method();

		$method->calculate_shipping( [] );

		$this->assertCount( 0, $method->rates );
	}

	/**
	 * Happy：下單寫入溫層 meta + provider id（經 ProviderRegister::save_checkout_meta）
	 *
	 * @group happy
	 */
	public function test_下單寫入溫層meta(): void {
		$order = $this->make_order_with_shipping( '0002' );

		ProviderRegister::save_checkout_meta( $order, [] );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( '0002', $fresh->get_meta( '_pc_tcat_logistics_thermosphere' ) );
		$this->assertSame( TcatLogisticsProvider::ID, $fresh->get_meta( '_pc_tcat_logistics_provider_id' ) );
	}

	/**
	 * Security：rate meta 被竄改為非法溫層 → 不寫入
	 *
	 * @group security
	 */
	public function test_非法溫層不寫入(): void {
		$order = $this->make_order_with_shipping( '9999' );

		WC_TcatLogisticsShipping::save_checkout_meta( $order, [] );

		$this->assertSame( '', \wc_get_order( $order->get_id() )->get_meta( '_pc_tcat_logistics_thermosphere' ) );
	}

	/**
	 * Edge：非本運送方式的訂單 → 不寫入
	 *
	 * @group edge
	 */
	public function test_非本運送方式不寫入(): void {
		$order = $this->make_order_with_shipping( '0002', 'flat_rate' );

		WC_TcatLogisticsShipping::save_checkout_meta( $order, [] );

		$this->assertFalse( WC_TcatLogisticsShipping::is_chosen( $order ) );
		$this->assertSame( '', \wc_get_order( $order->get_id() )->get_meta( '_pc_tcat_logistics_thermosphere' ) );
	}

	/**
	 * Happy：建單沿用訂單溫層（冷凍）
	 *
	 * @group happy
	 */
	public function test_建單沿用訂單溫層(): void {
		$this->setup_tcat_logistics( [ 'sender_zip_code' => '80224B' ] );
		$order = $this->make_order_with_shipping( '0003' );
		WC_TcatLogisticsShipping::save_checkout_meta( $order, [] );
		$this->fake_tcat( 'PrintOBT', 'print_obt_ok' );

		TcatLogisticsProvider::instance()->create_shipment( \wc_get_order( $order->get_id() ) );

		$this->assertSame( '0003', $this->tcat_requests( 'PrintOBT' )[0]['decoded']['Orders'][0]['Thermosphere'] );
	}
}
