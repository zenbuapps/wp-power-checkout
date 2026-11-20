<?php
/**
 * 順豐 WC 運送方式測試（P6：sf_express）
 *
 * - 固定運費；provider 未啟用不產 rate；建單只組台灣收件（receiverInfo.country=TW）→ 目的地非 TW 不產 rate
 * - 下單經 save_checkout_meta 寫入 _pc_sf_express_provider_id（只有選用本運送方式時）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter WC_SfExpressShipping --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\ProviderRegister;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\SfExpressProvider;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\WC_SfExpressShipping;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfExpressMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\SfExpressTestTrait;
use Tests\Integration\TestCase;

/**
 * 順豐運送方式
 *
 * @group integration
 * @group logistics
 * @group sf_express
 */
final class WC_SfExpressShippingTest extends TestCase {
	use SfExpressTestTrait;

	/** 每個測試前：啟用順豐 */
	protected function configure_dependencies(): void {
		$this->setup_sf_express();
	}

	/** 清理 */
	public function tear_down(): void {
		\delete_option( ( new WC_SfExpressShipping() )->get_option_key() );
		$this->reset_sf_express();
		parent::tear_down();
	}

	/**
	 * 建立帶設定的運送方式
	 *
	 * @param array<string, string> $settings 設定
	 * @return WC_SfExpressShipping
	 */
	private function make_method( array $settings = [] ): WC_SfExpressShipping {
		\update_option( ( new WC_SfExpressShipping() )->get_option_key(), $settings );
		return new WC_SfExpressShipping();
	}

	/**
	 * 建立選用某運送方式的訂單
	 *
	 * @param string $method_id 運送方式 id
	 * @return \WC_Order
	 */
	private function make_order_with_shipping( string $method_id = WC_SfExpressShipping::METHOD_ID ): \WC_Order {
		$order = $this->make_sf_order();
		$item  = new \WC_Order_Item_Shipping();
		$item->set_method_id( $method_id );
		$item->set_method_title( '順豐速運' );
		$item->set_total( '200' );
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
		$method = new WC_SfExpressShipping();

		$this->assertInstanceOf( \WC_Shipping_Method::class, $method );
		$this->assertSame( 'sf_express', WC_SfExpressShipping::METHOD_ID );
		$this->assertNotSame( ProviderUtils::get_option_name( SfExpressProvider::ID ), $method->get_option_key() );
	}

	/**
	 * Happy：固定運費 rate（目的地 TW）
	 *
	 * @group happy
	 */
	public function test_固定運費rate(): void {
		$method = $this->make_method( [ 'cost' => '180' ] );

		$method->calculate_shipping( [ 'destination' => [ 'country' => 'TW' ] ] );
		$rate = \reset( $method->rates );

		$this->assertCount( 1, $method->rates );
		$this->assertSame( 180.0, (float) $rate->get_cost() );
	}

	/**
	 * Edge：provider 未啟用 / 目的地非 TW → 不產 rate
	 *
	 * @group edge
	 */
	public function test_未啟用或非台灣不產rate(): void {
		$abroad = $this->make_method();
		$abroad->calculate_shipping( [ 'destination' => [ 'country' => 'JP' ] ] );
		$this->assertCount( 0, $abroad->rates );

		ProviderUtils::update_option( SfExpressProvider::ID, 'enabled', 'no' );
		$disabled = $this->make_method();
		$disabled->calculate_shipping( [ 'destination' => [ 'country' => 'TW' ] ] );
		$this->assertCount( 0, $disabled->rates );
	}

	/**
	 * Happy：選用本運送方式下單 → 寫 provider id；其他運送方式不寫
	 *
	 * @group happy
	 */
	public function test_下單寫入provider_id(): void {
		$chosen = $this->make_order_with_shipping();
		$other  = $this->make_order_with_shipping( 'flat_rate' );

		ProviderRegister::save_checkout_meta( $chosen, [] );
		ProviderRegister::save_checkout_meta( $other, [] );

		$this->assertSame( SfExpressProvider::ID, \wc_get_order( $chosen->get_id() )->get_meta( SfExpressMetaKeys::PROVIDER_ID_KEY ) );
		$this->assertSame( '', \wc_get_order( $other->get_id() )->get_meta( SfExpressMetaKeys::PROVIDER_ID_KEY ) );
		$this->assertTrue( WC_SfExpressShipping::is_chosen( $chosen ) );
		$this->assertFalse( WC_SfExpressShipping::is_chosen( $other ) );
	}
}
