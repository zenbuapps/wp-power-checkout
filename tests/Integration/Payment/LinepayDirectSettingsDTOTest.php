<?php
/**
 * LINE Pay 直連設定 DTO 測試（P3）
 *
 * - 不繼承 wp-utils DTO（R16）；create() 顯式驗證並拋 \InvalidArgumentException
 * - mode 預設 test（R17）；sandbox host https://sandbox-api-pay.line.me、正式 https://api-pay.line.me
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter LinepayDirectSettingsDTO --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Linepay\DTOs\LinepaySettingsDTO;
use J7\PowerCheckout\Domains\Payment\Linepay\Services\LinepayGateway;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * LINE Pay 設定 DTO 測試
 *
 * @group integration
 * @group payment
 * @group linepay
 */
final class LinepayDirectSettingsDTOTest extends TestCase {

	/**
	 * Happy：預設值（test 模式、未啟用、無憑證、sandbox host）
	 *
	 * @group happy
	 */
	public function test_預設為test模式與sandbox_host(): void {
		$dto = LinepaySettingsDTO::create( [] );

		$this->assertSame( 'test', $dto->mode );
		$this->assertSame( 'no', $dto->enabled );
		$this->assertSame( 'LINE Pay', $dto->title );
		$this->assertSame( '', $dto->channel_id );
		$this->assertSame( '', $dto->channel_secret );
		$this->assertFalse( $dto->has_credentials() );
		$this->assertSame( 'https://sandbox-api-pay.line.me', $dto->get_api_base() );
	}

	/**
	 * Happy：prod 模式切到正式 host；憑證齊全
	 *
	 * @group happy
	 */
	public function test_prod模式使用正式host(): void {
		$dto = LinepaySettingsDTO::create(
			[
				'mode'           => 'prod',
				'channel_id'     => '1234567890',
				'channel_secret' => 'a917ab6a2367b536f8e5a6e2977e06f4',
			]
		);

		$this->assertSame( 'https://api-pay.line.me', $dto->get_api_base() );
		$this->assertTrue( $dto->has_credentials() );
	}

	/**
	 * Happy：to_array 與 create 往返一致；instance() 讀 WC option
	 *
	 * @group happy
	 */
	public function test_to_array往返一致且instance讀option(): void {
		$data = [
			'enabled'           => 'yes',
			'title'             => 'LINE Pay 付款',
			'description'       => '描述',
			'order_button_text' => '前往 LINE Pay',
			'mode'              => 'test',
			'channel_id'        => '1234567890',
			'channel_secret'    => 'a917ab6a2367b536f8e5a6e2977e06f4',
			'min_amount'        => 10,
			'max_amount'        => 50000,
		];

		$this->assertSame( $data, LinepaySettingsDTO::create( $data )->to_array() );

		ProviderUtils::update_option( LinepayGateway::ID, $data );
		$this->assertSame( $data, LinepaySettingsDTO::instance()->to_array() );
	}

	/**
	 * Edge：mode / enabled 非法值 → 拋 \InvalidArgumentException
	 *
	 * @group edge
	 */
	public function test_非法mode拋例外(): void {
		$this->expectException( \InvalidArgumentException::class );
		LinepaySettingsDTO::create( [ 'mode' => 'sandbox' ] );
	}

	/**
	 * Edge：enabled 非 yes/no → 拋例外
	 *
	 * @group edge
	 */
	public function test_非法enabled拋例外(): void {
		$this->expectException( \InvalidArgumentException::class );
		LinepaySettingsDTO::create( [ 'enabled' => 'true' ] );
	}

	/**
	 * Edge：Channel ID 只能為數字（LINE Pay Channel ID 為 10 位數字）
	 *
	 * @group edge
	 */
	public function test_channel_id非數字拋例外(): void {
		$this->expectException( \InvalidArgumentException::class );
		LinepaySettingsDTO::create( [ 'channel_id' => 'abc-123' ] );
	}

	/**
	 * Edge：憑證前後空白 / 不可見字元會被去除
	 *
	 * @group edge
	 */
	public function test_憑證去除空白(): void {
		$dto = LinepaySettingsDTO::create(
			[
				'channel_id'     => " 1234567890\u{200B}",
				'channel_secret' => "\ta917ab6a2367b536f8e5a6e2977e06f4 ",
			]
		);

		$this->assertSame( '1234567890', $dto->channel_id );
		$this->assertSame( 'a917ab6a2367b536f8e5a6e2977e06f4', $dto->channel_secret );
	}

	/**
	 * Edge：option 毀損 → instance() 不拋錯、回預設值
	 *
	 * @group edge
	 */
	public function test_option毀損時instance回預設值(): void {
		ProviderUtils::update_option(
			LinepayGateway::ID,
			[
				'enabled' => 'yes',
				'mode'    => 'weird',
			]
		);

		$dto = LinepaySettingsDTO::instance();

		$this->assertSame( 'test', $dto->mode );
		$this->assertSame( 'no', $dto->enabled );
	}
}
