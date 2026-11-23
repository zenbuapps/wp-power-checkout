<?php
/**
 * 黑貓宅急便設定 DTO 測試（P4）
 *
 * - mode 預設 test（R17）；必填 / 格式在 create() 顯式驗證並拋 \InvalidArgumentException（R16）
 * - option 毀損 → instance() 不拋錯、回預設值
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter TcatLogisticsSettingsDTO --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Tcat\DTOs\TcatLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\TcatLogisticsProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 黑貓宅急便設定 DTO
 *
 * @group integration
 * @group logistics
 * @group tcat_logistics
 */
final class TcatLogisticsSettingsDTOTest extends TestCase {

	/** 清理 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( TcatLogisticsProvider::ID ) );
		TcatLogisticsSettingsDTO::reset();
		parent::tear_down();
	}

	/**
	 * Smoke：預設值（mode=test、未啟用、常溫 60cm、A4 二模、不指定時段）
	 *
	 * @group smoke
	 */
	public function test_預設值(): void {
		$dto = TcatLogisticsSettingsDTO::create( [] );

		$this->assertSame( 'test', $dto->mode, 'R17：新 provider mode 預設 test' );
		$this->assertSame( 'no', $dto->enabled );
		$this->assertSame( '0001', $dto->default_thermosphere );
		$this->assertSame( '0001', $dto->default_spec );
		$this->assertSame( '01', $dto->print_obt_type );
		$this->assertSame( '04', $dto->delivery_time );
		$this->assertSame( '0015', $dto->product_type_id );
		$this->assertFalse( $dto->has_credentials() );
		$this->assertSame( 'https://egs.suda.com.tw:8443/api/Egs', $dto->api_base() );
	}

	/**
	 * Happy：prod 模式 base URL；to_array 與 create 往返一致
	 *
	 * @group happy
	 */
	public function test_prod模式與往返一致(): void {
		$dto = TcatLogisticsSettingsDTO::create(
			[
				'mode'           => 'prod',
				'customer_id'    => '123456789012',
				'customer_token' => 'token',
			]
		);

		$this->assertSame( 'https://api.suda.com.tw/api/Egs', $dto->api_base() );
		$this->assertTrue( $dto->has_credentials() );
		$this->assertEquals( $dto, TcatLogisticsSettingsDTO::create( $dto->to_array() ) );
	}

	/**
	 * Error：格式不合法 → \InvalidArgumentException
	 *
	 * @dataProvider invalid_cases
	 * @group error
	 *
	 * @param array<string, mixed> $data 設定
	 */
	public function test_格式不合法拋例外( array $data ): void {
		$this->expectException( \InvalidArgumentException::class );
		TcatLogisticsSettingsDTO::create( $data );
	}

	/**
	 * 不合法案例
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function invalid_cases(): array {
		return [
			'mode 非 test/prod'       => [ [ 'mode' => 'sandbox' ] ],
			'enabled 非 yes/no'       => [ [ 'enabled' => 'true' ] ],
			'契客代號 11 碼'             => [ [ 'customer_id' => '12345678901' ] ],
			'溫層代碼不存在'               => [ [ 'default_thermosphere' => '0004' ] ],
			'規格代碼不存在'               => [ [ 'default_spec' => '0005' ] ],
			'列印類別不存在'               => [ [ 'print_obt_type' => '04' ] ],
			'配達時段 03 不存在'           => [ [ 'delivery_time' => '03' ] ],
			'商品類別不存在'               => [ [ 'product_type_id' => '0016' ] ],
			'寄件手機非 09 開頭'          => [ [ 'sender_mobile' => '0212345678' ] ],
			'商品名稱含禁用字串'            => [ [ 'product_name' => '電子發票' ] ],
			'低溫不可 150cm（E020）'     => [ [ 'default_thermosphere' => '0003', 'default_spec' => '0004' ] ],
			'寄件郵號取不出六碼'            => [ [ 'sender_zip_code' => '123' ] ],
			'欄位型別錯'                 => [ [ 'title' => [ 'x' ] ] ],
		];
	}

	/**
	 * Edge：option 毀損 → instance() 回預設、不拋錯
	 *
	 * @group edge
	 */
	public function test_option毀損回預設(): void {
		ProviderUtils::update_option( TcatLogisticsProvider::ID, [ 'mode' => 'bogus' ] );
		TcatLogisticsSettingsDTO::reset();

		$dto = TcatLogisticsSettingsDTO::instance();

		$this->assertSame( 'test', $dto->mode );
	}

	/**
	 * Edge：寄件郵號可填 PostNumber 原文，正規化為末六碼
	 *
	 * @group edge
	 */
	public function test_寄件郵號正規化為六碼(): void {
		$dto = TcatLogisticsSettingsDTO::create( [ 'sender_zip_code' => '71-802-24-B' ] );

		$this->assertSame( '80224B', $dto->sender_zip_code );
	}
}
