<?php
/**
 * 嘉里大榮 WC 運送方式測試（P7：kerrytj_logistics）
 *
 * - 每個實例一個溫層（1 常溫 / 2 冷藏 / 3 冷凍）+ 固定運費；rate meta 帶 temperature
 * - provider 未啟用不產 rate；目的地非台灣不產 rate
 * - 下單經 save_checkout_meta 白名單寫入 _pc_kerrytj_logistics_temperature，建單沿用（決定常溫 / 低溫號段）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter WC_KerrytjLogisticsShipping --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\KerrytjLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\WC_KerrytjLogisticsShipping;
use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\KerrytjLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 嘉里大榮運送方式
 *
 * @group integration
 * @group logistics
 * @group kerrytj_logistics
 */
final class WC_KerrytjLogisticsShippingTest extends TestCase {
	use KerrytjLogisticsTestTrait;

	/** 每個測試前：啟用嘉里大榮 */
	protected function configure_dependencies(): void {
		$this->setup_kerrytj_logistics();
	}

	/** 清理 */
	public function tear_down(): void {
		\delete_option( ( new WC_KerrytjLogisticsShipping() )->get_option_key() );
		$this->reset_kerrytj_logistics();
		parent::tear_down();
	}

	/**
	 * 建立帶設定的運送方式
	 *
	 * @param array<string, string> $settings 設定
	 * @return WC_KerrytjLogisticsShipping
	 */
	private function make_method( array $settings = [] ): WC_KerrytjLogisticsShipping {
		\update_option( ( new WC_KerrytjLogisticsShipping() )->get_option_key(), $settings );
		return new WC_KerrytjLogisticsShipping();
	}

	/**
	 * 建立選用本運送方式的訂單
	 *
	 * @param string $temperature rate meta 溫層
	 * @param string $method_id   運送方式 id
	 * @return \WC_Order
	 */
	private function make_order_with_shipping( string $temperature, string $method_id = WC_KerrytjLogisticsShipping::METHOD_ID ): \WC_Order {
		$order = $this->make_kerrytj_order();
		$item  = new \WC_Order_Item_Shipping();
		$item->set_method_id( $method_id );
		$item->set_method_title( '嘉里大榮' );
		$item->set_total( '150' );
		$item->add_meta_data( 'temperature', $temperature );
		$order->add_item( $item );
		$order->save();
		return $order;
	}

	/** @return array<string, mixed> 台灣目的地 package */
	private function tw_package(): array {
		return [
			'contents'    => [],
			'destination' => [ 'country' => 'TW' ],
		];
	}

	/**
	 * Smoke：繼承 WC_Shipping_Method、METHOD_ID、option 不撞 provider option
	 *
	 * @group smoke
	 */
	public function test_冒煙_類別與option_key(): void {
		$method = new WC_KerrytjLogisticsShipping();

		$this->assertInstanceOf( \WC_Shipping_Method::class, $method );
		$this->assertSame( 'kerrytj_logistics', WC_KerrytjLogisticsShipping::METHOD_ID );
		$this->assertNotSame( ProviderUtils::get_option_name( KerrytjLogisticsProvider::ID ), $method->get_option_key() );
	}

	/**
	 * Happy：固定運費 + 常溫 → rate meta temperature=1
	 *
	 * @group happy
	 */
	public function test_固定運費與常溫rate(): void {
		$method = $this->make_method( [ 'cost' => '130' ] );

		$method->calculate_shipping( $this->tw_package() );
		$rate = \reset( $method->rates );

		$this->assertCount( 1, $method->rates );
		$this->assertSame( 130.0, (float) $rate->get_cost() );
		$this->assertSame( '1', $rate->get_meta_data()['temperature'] ?? null );
	}

	/**
	 * Happy：冷凍實例 → rate meta 3、標籤含「冷凍」
	 *
	 * @group happy
	 */
	public function test_冷凍rate(): void {
		$method = $this->make_method( [ 'temperature' => '3' ] );

		$method->calculate_shipping( $this->tw_package() );
		$rate = \reset( $method->rates );

		$this->assertSame( '3', $rate->get_meta_data()['temperature'] ?? null );
		$this->assertStringContainsString( '冷凍', $rate->get_label() );
	}

	/**
	 * Edge：provider 未啟用 / 目的地非台灣 → 不產 rate
	 *
	 * @group edge
	 */
	public function test_未啟用或非台灣不產rate(): void {
		$foreign = $this->make_method();
		$foreign->calculate_shipping( [ 'destination' => [ 'country' => 'JP' ] ] );
		$this->assertCount( 0, $foreign->rates );

		ProviderUtils::update_option( KerrytjLogisticsProvider::ID, 'enabled', 'no' );
		$disabled = $this->make_method();
		$disabled->calculate_shipping( $this->tw_package() );
		$this->assertCount( 0, $disabled->rates );
	}

	/**
	 * Happy：下單寫入溫層 meta + provider id
	 *
	 * @group happy
	 */
	public function test_下單寫入溫層meta(): void {
		$order = $this->make_order_with_shipping( '2' );

		ProviderRegister::save_checkout_meta( $order, [] );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( '2', $fresh->get_meta( '_pc_kerrytj_logistics_temperature' ) );
		$this->assertSame( KerrytjLogisticsProvider::ID, $fresh->get_meta( '_pc_kerrytj_logistics_provider_id' ) );
	}

	/**
	 * Security：rate meta 竄改為非法溫層 → 不寫入；非本運送方式 → 不寫入
	 *
	 * @group security
	 */
	public function test_非法溫層不寫入(): void {
		$tampered = $this->make_order_with_shipping( '9' );
		$other    = $this->make_order_with_shipping( '3', 'flat_rate' );

		WC_KerrytjLogisticsShipping::save_checkout_meta( $tampered, [] );
		WC_KerrytjLogisticsShipping::save_checkout_meta( $other, [] );

		$this->assertSame( '', \wc_get_order( $tampered->get_id() )->get_meta( '_pc_kerrytj_logistics_temperature' ) );
		$this->assertSame( '', \wc_get_order( $other->get_id() )->get_meta( '_pc_kerrytj_logistics_temperature' ) );
		$this->assertFalse( WC_KerrytjLogisticsShipping::is_chosen( $other ) );
	}

	/**
	 * Happy：建單沿用訂單溫層（冷藏 → 低溫號段）
	 *
	 * @group happy
	 */
	public function test_建單沿用訂單溫層(): void {
		$order = $this->make_order_with_shipping( '2' );
		WC_KerrytjLogisticsShipping::save_checkout_meta( $order, [] );
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_ok' );

		KerrytjLogisticsProvider::instance()->create_shipment( \wc_get_order( $order->get_id() ) );

		$sent = $this->kerrytj_request_json( 'ShipmentDelivery' )[0];
		$this->assertSame( '2', $sent['Temperature'] );
		$this->assertSame( self::$kerrytj_cold_first, $sent['BLN'] );
	}
}
