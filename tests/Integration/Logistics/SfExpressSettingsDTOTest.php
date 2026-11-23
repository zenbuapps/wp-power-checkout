<?php
/**
 * 順豐設定 DTO 測試（P6：sf_express）
 *
 * - mode 預設 test（R17）；enabled 預設 no；interProductCode 預設 INT0005（沙箱 TW→TW 推薦產品，正式以簽約為準）
 * - create() 顯式驗證（R16）：mode / enabled 值域、AES 金鑰 43 字元、寄件類型 1|2、統編 8 碼、寄件郵遞區號 3/5/6 碼
 * - 憑證齊全判斷：API 呼叫需 appKey / appSecret / AES / customerCode；建單另需月結卡號 / 產品碼 / 寄件人
 * - 不帶任何憑證預設值（公開 sandbox 憑證也不帶）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter SfExpressSettingsDTO --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\SfExpress\DTOs\SfExpressSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\SfExpressProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\SfExpressTestTrait;
use Tests\Integration\TestCase;

/**
 * 順豐設定 DTO
 *
 * @group integration
 * @group logistics
 * @group sf_express
 */
final class SfExpressSettingsDTOTest extends TestCase {
	use SfExpressTestTrait;

	/** 清理 */
	public function tear_down(): void {
		$this->reset_sf_express();
		parent::tear_down();
	}

	/**
	 * Happy：預設值（mode=test、enabled=no、無任何憑證）
	 *
	 * @group happy
	 */
	public function test_預設值(): void {
		$dto = SfExpressSettingsDTO::create( [] );

		$this->assertSame( 'test', $dto->mode );
		$this->assertSame( 'no', $dto->enabled );
		$this->assertSame( 'INT0005', $dto->product_code );
		$this->assertSame( '2', $dto->sender_cargo_type );
		$this->assertSame( '', $dto->app_key );
		$this->assertSame( '', $dto->app_secret );
		$this->assertSame( '', $dto->encoding_aes_key );
		$this->assertSame( '', $dto->customer_code );
		$this->assertSame( '', $dto->month_card );
		$this->assertFalse( $dto->has_api_credentials() );
		$this->assertSame( 'https://api-ifsp-sit.sf.global', $dto->base_url() );
	}

	/**
	 * Happy：to_array 與 create 往返一致；正式站 base_url
	 *
	 * @group happy
	 */
	public function test_往返一致與正式站(): void {
		$data = [
			'enabled'          => 'yes',
			'mode'             => 'prod',
			'app_key'          => self::$sf_app_key,
			'app_secret'       => self::$sf_app_secret,
			'encoding_aes_key' => self::$sf_aes_key,
			'customer_code'    => self::$sf_customer_code,
			'month_card'       => self::$sf_month_card,
			'sender_tax_id'    => '12345678',
			'sender_postcode'  => '10048',
		];

		$dto = SfExpressSettingsDTO::create( $data );

		$this->assertSame( $dto->to_array(), SfExpressSettingsDTO::create( $dto->to_array() )->to_array() );
		$this->assertSame( 'https://api-ifsp.sf.global', $dto->base_url() );
		$this->assertTrue( $dto->has_api_credentials() );
		$this->assertArrayNotHasKey( 'unknown', SfExpressSettingsDTO::create( [ 'unknown' => 'x' ] )->to_array() );
	}

	/**
	 * Data：不合法輸入
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function invalid_cases(): array {
		return [
			'mode 值域'    => [ [ 'mode' => 'sandbox' ] ],
			'enabled 值域' => [ [ 'enabled' => 'true' ] ],
			'AES 金鑰長度'   => [ [ 'encoding_aes_key' => 'too-short' ] ],
			'寄件類型'       => [ [ 'sender_cargo_type' => '3' ] ],
			'統編位數'       => [ [ 'sender_tax_id' => '1234' ] ],
			'寄件郵遞區號'     => [ [ 'sender_postcode' => '1004' ] ],
			'產品碼格式'      => [ [ 'product_code' => 'INT 0005' ] ],
			'非字串'        => [ [ 'app_key' => [ 'x' ] ] ],
		];
	}

	/**
	 * Error：不合法輸入 → \InvalidArgumentException（R16：顯式驗證）
	 *
	 * @dataProvider invalid_cases
	 * @group error
	 *
	 * @param array<string, mixed> $data 設定
	 */
	public function test_不合法輸入拋例外( array $data ): void {
		$this->expectException( \InvalidArgumentException::class );
		SfExpressSettingsDTO::create( $data );
	}

	/**
	 * Happy：缺漏清單（API 憑證 / 建單必要欄位 / 寄件人）只列欄位名、不含值
	 *
	 * @group happy
	 */
	public function test_缺漏清單(): void {
		$dto = SfExpressSettingsDTO::create(
			[
				'app_key'          => self::$sf_app_key,
				'app_secret'       => self::$sf_app_secret,
				'encoding_aes_key' => self::$sf_aes_key,
				'customer_code'    => self::$sf_customer_code,
			]
		);

		$this->assertTrue( $dto->has_api_credentials() );
		$this->assertSame( [ '月結卡號' ], $dto->missing_shipment_settings() );
		$this->assertSame( [ '寄件人姓名', '寄件人手機', '寄件郵遞區號', '寄件地址' ], $dto->missing_sender() );
		$this->assertFalse( $dto->has_push_key() );
	}

	/**
	 * Edge：option 毀損 → instance() 回預設值不拋錯
	 *
	 * @group edge
	 */
	public function test_option毀損回預設(): void {
		ProviderUtils::update_option( SfExpressProvider::ID, [ 'mode' => 'broken' ] );
		SfExpressSettingsDTO::reset();

		$this->assertSame( 'test', SfExpressSettingsDTO::instance()->mode );
	}
}
