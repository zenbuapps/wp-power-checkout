<?php
/**
 * SLP 嵌入式設定 DTO 測試（P1）
 *
 * 藍圖 §9 修正 3：嵌入式共用導轉式（RedirectSettingsDTO）的商店憑證（merchantId / apiKey / signKey /
 * clientKey / mode），EmbeddedSettingsDTO 只放顯示欄位（enabled / title / description / order_button_text）。
 * is_available() 依 RedirectSettingsDTO 憑證是否齊全而變。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter SlpEmbeddedSettingsDTO --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\EmbeddedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Payment\Doubles\SlpEmbeddedTestTrait;
use Tests\Integration\TestCase;

/**
 * SLP 嵌入式設定 DTO 測試
 *
 * @group integration
 * @group payment
 * @group shopline_payment_embedded
 */
final class SlpEmbeddedSettingsDTOTest extends TestCase {
	use SlpEmbeddedTestTrait;

	/** @var array<int, string> 嵌入式設定唯一允許的欄位 */
	private const DISPLAY_KEYS = [ 'enabled', 'title', 'description', 'order_button_text' ];

	/** 每個測試前：TWD + 導轉式 test 模式（內建 sandbox 憑證） */
	protected function configure_dependencies(): void {
		$this->setup_slp_embedded();
	}

	/** 清理 HTTP 攔截 */
	public function tear_down(): void {
		$this->reset_slp_embedded_http();
		parent::tear_down();
	}

	/**
	 * Happy：預設值（未存任何設定）
	 *
	 * @group happy
	 */
	public function test_預設值_未啟用且顯示欄位有預設文字(): void {
		\delete_option( ProviderUtils::get_option_name( EmbeddedGateway::ID ) );

		$dto = EmbeddedSettingsDTO::instance();

		$this->assertSame( 'no', $dto->enabled );
		$this->assertNotSame( '', $dto->title );
		$this->assertNotSame( '', $dto->description );
		$this->assertNotSame( '', $dto->order_button_text );
	}

	/**
	 * Edge：只含顯示欄位，不含任何憑證 / mode 欄位
	 *
	 * @group edge
	 */
	public function test_不含任何憑證欄位(): void {
		$array = EmbeddedSettingsDTO::create(
			[
				'enabled'    => 'yes',
				'title'      => '信用卡',
				'apiKey'     => 'sk_should_be_ignored',
				'clientKey'  => 'pk_should_be_ignored',
				'merchantId' => 'should_be_ignored',
				'signKey'    => 'should_be_ignored',
				'mode'       => 'prod',
			]
		)->to_array();

		$this->assertSame( self::DISPLAY_KEYS, \array_keys( $array ) );
	}

	/**
	 * Edge：字串欄位去除前後空白 / 不可見字元
	 *
	 * @group edge
	 */
	public function test_字串欄位會trim(): void {
		$dto = EmbeddedSettingsDTO::create(
			[
				'enabled'           => ' yes ',
				'title'             => "  信用卡站內付\u{200B} ",
				'description'       => "\t描述 ",
				'order_button_text' => ' 付款 ',
			]
		);

		$this->assertSame( 'yes', $dto->enabled );
		$this->assertSame( '信用卡站內付', $dto->title );
		$this->assertSame( '描述', $dto->description );
		$this->assertSame( '付款', $dto->order_button_text );
	}

	/**
	 * Error：create() 顯式驗證（R16），非法 enabled / 非字串欄位 → InvalidArgumentException
	 *
	 * @group error
	 */
	public function test_create非法值拋InvalidArgumentException(): void {
		$this->expectException( \InvalidArgumentException::class );
		EmbeddedSettingsDTO::create( [ 'enabled' => 'maybe' ] );
	}

	/**
	 * Error：非字串 title → InvalidArgumentException
	 *
	 * @group error
	 */
	public function test_create非字串欄位拋InvalidArgumentException(): void {
		$this->expectException( \InvalidArgumentException::class );
		EmbeddedSettingsDTO::create( [ 'title' => [ 'array' ] ] );
	}

	/**
	 * Edge：option 毀損時 instance() 不拋錯，回預設值（避免 gateway 建構失敗拖垮整個付款頁）
	 *
	 * @group edge
	 */
	public function test_option毀損時instance回預設值(): void {
		ProviderUtils::update_option(
			EmbeddedGateway::ID,
			[
				'enabled' => 'maybe',
				'title'   => [ 'broken' ],
			]
		);

		$dto = EmbeddedSettingsDTO::instance();

		$this->assertSame( 'no', $dto->enabled );
		$this->assertNotSame( '', $dto->title );
	}

	/**
	 * Edge：導轉式為正式模式且 clientKey 空 → is_available()=false（憑證以 RedirectSettingsDTO 為準）
	 *
	 * @group edge
	 */
	public function test_導轉式clientKey空時is_available為false(): void {
		$this->setup_slp_embedded(
			false,
			[
				'mode'       => 'prod',
				'merchantId' => 'MERCHANT_PROD',
				'apiKey'     => 'sk_live_dummy',
				'clientKey'  => '',
			]
		);

		$gateway = new EmbeddedGateway();

		$this->assertFalse( $gateway->has_store_credentials() );
		$this->assertFalse( $gateway->is_available() );
	}

	/**
	 * Edge：導轉式正式模式憑證齊全 → has_store_credentials()=true；購物車有金額時 is_available()=true
	 *
	 * @group edge
	 */
	public function test_導轉式憑證齊全時is_available為true(): void {
		$this->setup_slp_embedded(
			false,
			[
				'mode'       => 'prod',
				'merchantId' => 'MERCHANT_PROD',
				'apiKey'     => 'sk_live_dummy',
				'clientKey'  => 'pk_live_dummy',
			]
		);
		$this->load_cart_with_total( 500.0 );

		$gateway = new EmbeddedGateway();

		$this->assertTrue( $gateway->has_store_credentials() );
		$this->assertTrue( $gateway->is_available() );
	}

	/**
	 * 建立購物車並放入一件商品（is_available 需要 cart total > 0）
	 *
	 * @param float $price 商品價格
	 * @return void
	 */
	private function load_cart_with_total( float $price ): void {
		\wc_load_cart();
		$product = new \WC_Product_Simple();
		$product->set_name( 'SLP 嵌入式測試商品' );
		$product->set_regular_price( (string) $price );
		$product->set_virtual( true );
		$product->save();

		\WC()->cart->empty_cart();
		\WC()->cart->add_to_cart( $product->get_id() );
		\WC()->cart->calculate_totals();
	}
}
