<?php
/**
 * 新 provider icon / 顯示名稱 / SLP 付款方式相鄰排序測試
 *
 * - v1.4.0 新增的 7 個 provider（linepay / tcat_pay / shopline_payment_embedded、
 *   tcat_logistics / hct_logistics / sf_express / kerrytj_logistics）都要有 icon
 * - LINE Pay 名稱不帶「(直連)」；SLP 兩個 gateway 為「SHOPLINE Payments（跳轉支付 / 嵌入支付）」（#28 統一官方名稱）
 * - option woocommerce_gateway_order 讀取時嵌入支付緊接在跳轉支付後面
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter ProviderIconNaming --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Logistics\Hct\Services\HctLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\KerrytjLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\SfExpressProvider;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\TcatLogisticsProvider;
use J7\PowerCheckout\Domains\Payment\Linepay\Services\LinepayGateway;
use J7\PowerCheckout\Domains\Payment\ProviderRegister;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;
use J7\PowerCheckout\Plugin;
use Tests\Integration\TestCase;

/**
 * Provider icon / 命名 / 排序
 *
 * @group integration
 * @group payment
 */
final class ProviderIconNamingTest extends TestCase {

	/**
	 * @return array<string, array{0: class-string, 1: string}>
	 */
	public static function local_icon_provider(): array {
		return [
			'linepay'           => [ LinepayGateway::class, 'linepay.png' ],
			'tcat_pay'          => [ TcatPayGateway::class, 'tcat.png' ],
			'tcat_logistics'    => [ TcatLogisticsProvider::class, 'tcat.png' ],
			'hct_logistics'     => [ HctLogisticsProvider::class, 'hct.png' ],
			'sf_express'        => [ SfExpressProvider::class, 'sf_express.png' ],
			'kerrytj_logistics' => [ KerrytjLogisticsProvider::class, 'kerrytj.png' ],
		];
	}

	/**
	 * Happy：新 provider 的 get_settings() 帶本地 icon 網址，且檔案存在
	 *
	 * @group happy
	 * @dataProvider local_icon_provider
	 *
	 * @param class-string $class_name provider class
	 * @param string       $file       icon 檔名
	 */
	public function test_新provider帶本地icon( string $class_name, string $file ): void {
		/** @var callable $callable */
		$callable = [ $class_name, 'get_settings' ];
		/** @var array<string, mixed> $settings */
		$settings = \call_user_func( $callable );

		$this->assertSame( Plugin::$url . '/inc/assets/images/icons/' . $file, $settings['icon'] ?? null );
		$this->assertFileExists( Plugin::$dir . '/inc/assets/images/icons/' . $file );
	}

	/**
	 * Happy：SLP 嵌入支付與跳轉支付共用同一個 icon
	 *
	 * @group happy
	 */
	public function test_SLP嵌入支付與跳轉支付共用icon(): void {
		$this->assertSame( RedirectSettingsDTO::DEFAULT_ICON, EmbeddedGateway::get_settings()['icon'] ?? null );
		$this->assertSame( RedirectSettingsDTO::DEFAULT_ICON, ( new \ReflectionClass( RedirectSettingsDTO::class ) )->getDefaultProperties()['icon'] );
	}

	/**
	 * Happy：顯示名稱（method_title）
	 *
	 * @group happy
	 */
	public function test_顯示名稱(): void {
		$this->assertSame( 'LINE Pay', ( new \ReflectionClass( LinepayGateway::class ) )->getDefaultProperties()['method_title'] );
		$this->assertSame( 'SHOPLINE Payments（跳轉支付）', ( new \ReflectionClass( RedirectGateway::class ) )->getDefaultProperties()['method_title'] );
		$this->assertSame( 'SHOPLINE Payments（嵌入支付）', ( new \ReflectionClass( EmbeddedGateway::class ) )->getDefaultProperties()['method_title'] );
	}

	/**
	 * Happy：嵌入支付被排到最後（既有站台排序沒有它）→ 移到跳轉支付正後方並重新編號
	 *
	 * @group happy
	 */
	public function test_嵌入支付移到跳轉支付後方(): void {
		$ordering = [
			'bacs'                    => 0,
			RedirectGateway::ID       => 1,
			'ecpay_aio'               => 2,
			'linepay'                 => 3,
			EmbeddedGateway::ID       => 9,
			'cod'                     => 4,
		];

		$result = ProviderRegister::keep_slp_gateways_adjacent( $ordering );

		$this->assertSame(
			[
				'bacs'              => 0,
				RedirectGateway::ID => 1,
				EmbeddedGateway::ID => 2,
				'ecpay_aio'         => 3,
				'linepay'           => 4,
				'cod'               => 5,
			],
			$result
		);
	}

	/**
	 * Edge：排序中沒有嵌入支付時也插在跳轉支付後方
	 *
	 * @group edge
	 */
	public function test_排序缺嵌入支付時插入(): void {
		$result = ProviderRegister::keep_slp_gateways_adjacent( [ RedirectGateway::ID => 5, 'bacs' => 2 ] );

		$this->assertSame( [ 'bacs' => 0, RedirectGateway::ID => 1, EmbeddedGateway::ID => 2 ], $result );
	}

	/**
	 * Edge：沒有跳轉支付 / 非陣列 → 原樣回傳
	 *
	 * @group edge
	 */
	public function test_無跳轉支付或非陣列時不動(): void {
		$this->assertSame( [ 'bacs' => 3, EmbeddedGateway::ID => 0 ], ProviderRegister::keep_slp_gateways_adjacent( [ 'bacs' => 3, EmbeddedGateway::ID => 0 ] ) );
		$this->assertFalse( ProviderRegister::keep_slp_gateways_adjacent( false ) );
		$this->assertSame( '', ProviderRegister::keep_slp_gateways_adjacent( '' ) );
	}

	/**
	 * Happy：經 get_option 讀取時 filter 生效，WC 付款方式中兩者相鄰
	 *
	 * @group happy
	 */
	public function test_get_option讀取時生效(): void {
		\update_option( 'woocommerce_gateway_order', [ RedirectGateway::ID => 0, 'bacs' => 1, EmbeddedGateway::ID => 2 ] );

		$this->assertSame(
			[ RedirectGateway::ID => 0, EmbeddedGateway::ID => 1, 'bacs' => 2 ],
			\get_option( 'woocommerce_gateway_order' )
		);
	}
}
