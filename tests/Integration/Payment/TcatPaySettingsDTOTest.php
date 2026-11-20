<?php
/**
 * 黑貓PAY 設定 DTO 測試（P2）
 *
 * R16：不依賴 wp-utils DTO 必填驗證，create() 內顯式驗證並拋 \InvalidArgumentException。
 * R17：mode 預設 test（sandbox `https://test.4128888card.com.tw/app`）。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter TcatPaySettingsDTO --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\TcatPay\DTOs\TcatPaySettingsDTO;
use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Enums\TcatPayMethod;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayAmountLimits;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * 黑貓PAY 設定 DTO 測試
 *
 * @group integration
 * @group payment
 * @group tcat_pay
 */
final class TcatPaySettingsDTOTest extends TestCase {

	/**
	 * Happy：預設 test 模式、sandbox base、payuni 收單、只開刷卡、繳費 3 天
	 *
	 * @group happy
	 */
	public function test_預設值為test模式與sandbox端點(): void {
		$dto = TcatPaySettingsDTO::create( [] );

		$this->assertSame( 'test', $dto->mode );
		$this->assertSame( 'https://test.4128888card.com.tw/app', $dto->get_api_base() );
		$this->assertSame( 'no', $dto->enabled );
		$this->assertSame( 'payuni', $dto->acquirer_type );
		$this->assertSame( [ 'credit' ], $dto->enabled_methods );
		$this->assertSame( 3, $dto->expire_days );
		$this->assertSame( '', $dto->cust_id );
		$this->assertSame( '', $dto->api_password );
		$this->assertSame( '', $dto->hash_base );
	}

	/**
	 * Happy：prod 模式切換正式 base（無尾斜線）
	 *
	 * @group happy
	 */
	public function test_prod模式使用正式端點(): void {
		$dto = TcatPaySettingsDTO::create( [ 'mode' => 'prod' ] );
		$this->assertSame( 'https://cocs.4128888card.com.tw', $dto->get_api_base() );
	}

	/**
	 * Happy：各子方式金額上限預設值（codes-reference.md §5）與可覆寫
	 *
	 * @group happy
	 */
	public function test_各子方式金額上限預設與覆寫(): void {
		$dto = TcatPaySettingsDTO::create( [] );
		$this->assertSame( 100000, TcatPayAmountLimits::max_for( TcatPayMethod::CREDIT, $dto ) );
		$this->assertSame( 20000, TcatPayAmountLimits::max_for( TcatPayMethod::CVS_IBON, $dto ) );
		$this->assertSame( 49999, TcatPayAmountLimits::max_for( TcatPayMethod::ATM, $dto ) );

		$custom = TcatPaySettingsDTO::create( [ 'max_amount_atm' => '550000' ] );
		$this->assertSame( 550000, TcatPayAmountLimits::max_for( TcatPayMethod::ATM, $custom ) );
	}

	/**
	 * Edge：上限設定不合法（空白 / 負數 / 非整數）→ 退回平台預設
	 *
	 * @group edge
	 */
	public function test_上限設定不合法退回預設(): void {
		foreach ( [ '', '-1', '1.5', '55萬', 0 ] as $raw ) {
			$dto = TcatPaySettingsDTO::create( [ 'max_amount_cvs' => $raw ] );
			$this->assertSame( 20000, TcatPayAmountLimits::max_for( TcatPayMethod::CVS_IBON, $dto ), '輸入 ' . \var_export( $raw, true ) );
		}
	}

	/**
	 * Edge：字串欄位去除不可見字元（全形空白 / 零寬 / Tab）
	 *
	 * @group edge
	 */
	public function test_字串欄位去除不可見字元(): void {
		$dto = TcatPaySettingsDTO::create(
			[
				'cust_id'      => "\u{3000}TESTCUST01\t",
				'api_password' => "\u{200B}pw\n",
				'hash_base'    => ' HB ',
			]
		);
		$this->assertSame( 'TESTCUST01', $dto->cust_id );
		$this->assertSame( 'pw', $dto->api_password );
		$this->assertSame( 'HB', $dto->hash_base );
	}

	/**
	 * Error：不合法值顯式拋 InvalidArgumentException（R16）
	 *
	 * @group error
	 */
	public function test_不合法值拋InvalidArgumentException(): void {
		$cases = [
			'mode 非 test/prod'    => [ 'mode' => 'sandbox' ],
			'acquirer 不在白名單'      => [ 'acquirer_type' => 'ctbc' ],
			'子方式不在白名單'            => [ 'enabled_methods' => [ 'credit', 'linepay' ] ],
			'子方式空陣列'              => [ 'enabled_methods' => [] ],
			'cust_id 非字串'         => [ 'cust_id' => [ 'x' ] ],
			'enabled 非 yes/no'    => [ 'enabled' => 'true' ],
			'expire_days 超出 1~30' => [ 'expire_days' => 31 ],
		];
		foreach ( $cases as $label => $data ) {
			try {
				TcatPaySettingsDTO::create( $data );
				$this->fail( "{$label} 應拋 InvalidArgumentException" );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage(), $label );
			}
		}
	}

	/**
	 * Edge：option 毀損時 instance() 不拋錯，回預設值
	 *
	 * @group edge
	 */
	public function test_option毀損時instance回預設(): void {
		ProviderUtils::update_option(
			TcatPayGateway::ID,
			[
				'enabled' => 'yes',
				'mode'    => 'not-a-mode',
			]
		);
		$dto = TcatPaySettingsDTO::instance();
		$this->assertSame( 'test', $dto->mode );
		$this->assertSame( 'no', $dto->enabled );
	}

	/**
	 * Happy：to_array 往返一致（admin 設定頁讀寫）
	 *
	 * @group happy
	 */
	public function test_to_array往返一致(): void {
		$data = TcatPaySettingsDTO::create(
			[
				'enabled'         => 'yes',
				'cust_id'         => 'TESTCUST01',
				'enabled_methods' => [ 'credit', 'atm' ],
				'expire_days'     => '5',
			]
		)->to_array();

		$this->assertSame( 'yes', $data['enabled'] );
		$this->assertSame( [ 'credit', 'atm' ], $data['enabled_methods'] );
		$this->assertSame( 5, $data['expire_days'] );
		$this->assertSame( $data, TcatPaySettingsDTO::create( $data )->to_array() );
	}
}
