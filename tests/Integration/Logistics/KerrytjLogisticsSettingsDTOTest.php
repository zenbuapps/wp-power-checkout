<?php
/**
 * 嘉里大榮設定 DTO 測試（P7：kerrytj_logistics）
 *
 * - mode 預設 test（R17）→ EDIEntranceTest；prod → EDIEntrance；貨況主機只有一組
 * - create() 顯式驗證並拋 \InvalidArgumentException（R16）：客編 8 碼、寄件人郵遞區號、預設溫層、號段
 * - 號段：常溫起訖 11 碼；低溫起訖 9 碼流水號或檢查碼正確的 10 碼（存原值，range() 回流水號）；起訖皆空 = 未設定
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter KerrytjLogisticsSettingsDTO --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs\KerrytjLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjBlnKind;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 嘉里大榮設定
 *
 * @group integration
 * @group logistics
 * @group kerrytj_logistics
 */
final class KerrytjLogisticsSettingsDTOTest extends TestCase {

	/** 清理 */
	public function tear_down(): void {
		\delete_option( ProviderUtils::get_option_name( 'kerrytj_logistics' ) );
		KerrytjLogisticsSettingsDTO::reset();
		parent::tear_down();
	}

	/**
	 * Smoke：預設值
	 *
	 * @group smoke
	 */
	public function test_冒煙_預設值(): void {
		$dto = KerrytjLogisticsSettingsDTO::create( [] );

		$this->assertSame( 'no', $dto->enabled );
		$this->assertSame( 'test', $dto->mode );
		$this->assertSame( '1', $dto->default_temperature );
		$this->assertFalse( $dto->has_credentials() );
		$this->assertNull( $dto->range( KerrytjBlnKind::AMBIENT ) );
		$this->assertNull( $dto->range( KerrytjBlnKind::COLD ) );
		$this->assertSame( 'https://api.kerrytj.com/EDIEntranceTest', $dto->edi_base() );
		$this->assertSame( 'https://KerryTracingEDI.kerrytj.com/api/Tracing', KerrytjLogisticsSettingsDTO::TRACING_BASE );
		$this->assertSame( [ 'sender_name', 'sender_phone', 'sender_postcode', 'sender_address' ], $dto->missing_sender_fields() );
	}

	/**
	 * Happy：完整設定往返；prod 端點；低溫 10 碼起號轉流水號
	 *
	 * @group happy
	 */
	public function test_完整設定往返(): void {
		$input = [
			'enabled'               => 'yes',
			'title'                 => '嘉里大榮',
			'description'           => '宅配',
			'mode'                  => 'prod',
			'username'              => 'u',
			'password'              => 'p',
			'customer_no'           => '12345678',
			'sender_name'           => '某某商行',
			'sender_phone'          => '0223456789',
			'sender_postcode'       => '104',
			'sender_address'        => '台北市中山區某路1號',
			'ambient_waybill_start' => '40350718600',
			'ambient_waybill_end'   => '40350718699',
			'cold_waybill_start'    => '6670000002',
			'cold_waybill_end'      => '667000099',
			'default_temperature'   => '3',
		];

		$dto = KerrytjLogisticsSettingsDTO::create( $input );

		$this->assertSame( $input, $dto->to_array() );
		$this->assertSame( 'https://api.kerrytj.com/EDIEntrance', $dto->edi_base() );
		$this->assertTrue( $dto->has_credentials() );
		$this->assertSame( [], $dto->missing_sender_fields() );
		$this->assertSame(
			[
				'start' => 40350718600,
				'end'   => 40350718699,
			],
			$dto->range( KerrytjBlnKind::AMBIENT )
		);
		$this->assertSame(
			[
				'start' => 667000000,
				'end'   => 667000099,
			],
			$dto->range( KerrytjBlnKind::COLD )
		);
	}

	/**
	 * Error：不合法值 → \InvalidArgumentException
	 *
	 * @dataProvider invalid_inputs
	 * @group error
	 *
	 * @param array<string, mixed> $input 輸入
	 */
	public function test_不合法值拋例外( array $input ): void {
		$this->expectException( \InvalidArgumentException::class );
		KerrytjLogisticsSettingsDTO::create( $input );
	}

	/** @return array<string, array{0: array<string, mixed>}> */
	public function invalid_inputs(): array {
		return [
			'mode 非 test/prod' => [ [ 'mode' => 'sandbox' ] ],
			'客編非 8 碼'          => [ [ 'customer_no' => '1234' ] ],
			'寄件郵遞區號非數字'        => [ [ 'sender_postcode' => 'abc' ] ],
			'預設溫層非法'           => [ [ 'default_temperature' => '4' ] ],
			'常溫號段只填一端'         => [ [ 'ambient_waybill_start' => '40350718600' ] ],
			'常溫號段非 11 碼'       => [
				[
					'ambient_waybill_start' => '4035071860',
					'ambient_waybill_end'   => '4035071869',
				],
			],
			'常溫號段起大於迄'         => [
				[
					'ambient_waybill_start' => '40350718700',
					'ambient_waybill_end'   => '40350718699',
				],
			],
			'低溫 10 碼檢查碼錯'      => [
				[
					'cold_waybill_start' => '6670000003',
					'cold_waybill_end'   => '667000099',
				],
			],
			'enabled 非字串'      => [ [ 'enabled' => [ 'yes' ] ] ],
		];
	}

	/**
	 * Edge：option 毀損 → instance() 不拋錯，回預設值
	 *
	 * @group edge
	 */
	public function test_option毀損回預設(): void {
		\update_option( ProviderUtils::get_option_name( 'kerrytj_logistics' ), [ 'mode' => 'xxx' ] );
		KerrytjLogisticsSettingsDTO::reset();

		$this->assertSame( 'test', KerrytjLogisticsSettingsDTO::instance()->mode );
	}
}
