<?php
/**
 * 新竹物流設定 DTO 測試（P5）
 *
 * - mode 預設 test（R17）；必填 / 值域在 create() 顯式驗證並拋 \InvalidArgumentException（R16）
 * - 號段為 9 碼本體（不含檢查碼），起訖皆空 = 由新竹系統配號
 * - 貨況查詢參數（偏移天數 / 8 位 IV / 32 位 v）三者全填才啟用
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter HctLogisticsSettingsDTO --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Hct\DTOs\HctLogisticsSettingsDTO;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 新竹物流設定
 *
 * @group integration
 * @group logistics
 * @group hct_logistics
 */
final class HctLogisticsSettingsDTOTest extends TestCase {

	/** 清理 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( 'hct_logistics' ) );
		HctLogisticsSettingsDTO::reset();
		parent::tear_down();
	}

	/**
	 * Smoke：預設值（mode=test、未啟用、商品種類一般、重量 1）
	 *
	 * @group smoke
	 */
	public function test_冒煙_預設值(): void {
		$dto = HctLogisticsSettingsDTO::create( [] );

		$this->assertSame( 'no', $dto->enabled );
		$this->assertSame( 'test', $dto->mode );
		$this->assertSame( '001', $dto->default_product_type );
		$this->assertSame( '1', $dto->default_weight );
		$this->assertFalse( $dto->has_credentials() );
		$this->assertFalse( $dto->has_waybill_range() );
		$this->assertFalse( $dto->has_track_query() );
		$this->assertSame( 'https://hctrt.hct.com.tw/edi_webservice2_test/service1.asmx', $dto->endpoint() );
	}

	/**
	 * Happy：完整設定往返一致
	 *
	 * @group happy
	 */
	public function test_完整設定往返(): void {
		$data = [
			'enabled'              => 'yes',
			'title'                => '新竹物流',
			'description'          => '宅配到府',
			'mode'                 => 'prod',
			'company'              => 'company-x',
			'password'             => 'password-x',
			'customer_code'        => '12345678900',
			'sender_name'          => '測試商店',
			'sender_tel'           => '0223456789',
			'sender_address'       => '新竹縣新豐鄉建興路100號',
			'waybill_start'        => '100173483',
			'waybill_end'          => '100173582',
			'default_product_type' => '008',
			'default_weight'       => '5',
			'track_offset_days'    => '-33',
			'track_iv'             => 'ABCDEFGH',
			'track_v'              => \str_repeat( 'A', 32 ),
		];

		$dto = HctLogisticsSettingsDTO::create( $data );

		$this->assertSame( $data, $dto->to_array() );
		$this->assertTrue( $dto->has_credentials() );
		$this->assertTrue( $dto->has_waybill_range() );
		$this->assertTrue( $dto->has_track_query() );
		$this->assertSame( -33, $dto->track_offset() );
		$this->assertSame( 'https://hctrt.hct.com.tw/edi_webservice2/service1.asmx', $dto->endpoint() );
	}

	/**
	 * Error：值域 / 格式不合法 → \InvalidArgumentException
	 *
	 * @dataProvider invalid_cases
	 * @group error
	 *
	 * @param array<string, string> $data 設定
	 */
	public function test_不合法設定( array $data ): void {
		$this->expectException( \InvalidArgumentException::class );
		HctLogisticsSettingsDTO::create( $data );
	}

	/** @return array<string, array{0: array<string, string>}> */
	public function invalid_cases(): array {
		return [
			'mode'          => [ [ 'mode' => 'sandbox' ] ],
			'enabled'       => [ [ 'enabled' => 'true' ] ],
			'product_type'  => [ [ 'default_product_type' => '002' ] ],
			'weight'        => [ [ 'default_weight' => '0' ] ],
			'customer_code' => [ [ 'customer_code' => '123' ] ],
			'只填起號'          => [ [ 'waybill_start' => '100' ] ],
			'號段起大於迄'        => [
				[
					'waybill_start' => '200',
					'waybill_end'   => '100',
				],
			],
			'號段超過9碼'        => [
				[
					'waybill_start' => '1',
					'waybill_end'   => '1000000000',
				],
			],
			'IV長度'          => [
				[
					'track_offset_days' => '-33',
					'track_iv'          => 'SHORT',
					'track_v'           => \str_repeat( 'A', 32 ),
				],
			],
			'v長度'           => [
				[
					'track_offset_days' => '-33',
					'track_iv'          => 'ABCDEFGH',
					'track_v'           => 'ABC',
				],
			],
			'偏移非整數'         => [ [ 'track_offset_days' => 'x' ] ],
		];
	}

	/**
	 * Edge：option 毀損時 instance() 不拋錯，回預設值
	 *
	 * @group edge
	 */
	public function test_option毀損回預設(): void {
		\update_option( ProviderUtils::get_option_name( 'hct_logistics' ), [ 'mode' => 'broken' ] );
		HctLogisticsSettingsDTO::reset();

		$this->assertSame( 'test', HctLogisticsSettingsDTO::instance()->mode );
	}
}
