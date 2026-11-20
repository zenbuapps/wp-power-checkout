<?php
/**
 * ProviderMeta 能力宣告契約測試（P0 統一介面）
 *
 * 驗證既有 7 個 gateway 皆透過 IPaymentProvider::get_provider_meta() 宣告 payment_mode /
 * order_creation_strategy / 6 項 capability，且 capability 採「方法層級」定義：
 * 只有 gateway 類別自己覆寫 query_trade() / capture() / void_auth() 才宣告 true
 * （僅以 admin order action 提供者不算，見 specs/plan/2026-11-20-tdd-blueprint.md §5.1 / §9 修正 1）。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter ProviderMetaContract --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\EcpayAIO\Services\AioRedirectGateway;
use J7\PowerCheckout\Domains\Payment\Ecpg\Services\EcpgGateway;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services\MpgRedirectGateway;
use J7\PowerCheckout\Domains\Payment\Paynow\Services\PaynowGateway;
use J7\PowerCheckout\Domains\Payment\Payuni\Services\PayuniUppGateway;
use J7\PowerCheckout\Domains\Payment\PayuniUniEmbed\Services\PayuniUniEmbedGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\DTOs\ProviderMetaDTO;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderCreationStrategy;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\PaymentMode;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IPaymentProvider;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;
use J7\PowerCheckout\Domains\Settings\Services\SettingApiService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * ProviderMeta 契約測試
 *
 * @group integration
 * @group payment
 */
final class ProviderMetaContractTest extends TestCase {

	/** @var array<int, string> 6 個 capability 鍵 */
	private const CAPABILITY_KEYS = [ 'refund', 'partial_refund', 'capture', 'void', 'query', 'recurring' ];

	/**
	 * 每個 gateway 的預期宣告（依 Red 前讀碼確認的程式現況）
	 *
	 * @var array<string, array{class: class-string<AbstractPaymentGateway>, mode: string, caps: array<string, bool>}>
	 */
	private const EXPECTED = [
		'shopline_payment_redirect' => [
			'class' => RedirectGateway::class,
			'mode'  => 'api-redirect',
			'caps'  => [
				'refund'         => true,
				'partial_refund' => true,
				'capture'        => false,
				'void'           => false,
				'query'          => false,
				'recurring'      => false,
			],
		],
		'shopline_payment_embedded' => [
			'class' => EmbeddedGateway::class,
			'mode'  => 'client-sdk',
			'caps'  => [
				'refund'         => true,
				'partial_refund' => true,
				'capture'        => false,
				'void'           => false,
				'query'          => true,
				'recurring'      => false,
			],
		],
		'ecpay_aio'                 => [
			'class' => AioRedirectGateway::class,
			'mode'  => 'form-redirect',
			'caps'  => [
				'refund'         => true,
				'partial_refund' => true,
				'capture'        => false,
				'void'           => false,
				'query'          => false,
				'recurring'      => false,
			],
		],
		'ecpay_ecpg'                => [
			'class' => EcpgGateway::class,
			'mode'  => 'client-sdk',
			'caps'  => [
				'refund'         => true,
				'partial_refund' => true,
				'capture'        => false,
				'void'           => false,
				'query'          => false,
				'recurring'      => false,
			],
		],
		'newebpay_mpg'              => [
			'class' => MpgRedirectGateway::class,
			'mode'  => 'form-redirect',
			'caps'  => [
				'refund'         => true,
				'partial_refund' => true,
				'capture'        => false,
				'void'           => false,
				'query'          => false,
				'recurring'      => false,
			],
		],
		'payuni_upp'                => [
			'class' => PayuniUppGateway::class,
			'mode'  => 'form-redirect',
			'caps'  => [
				'refund'         => true,
				'partial_refund' => true,
				'capture'        => true,
				'void'           => true,
				'query'          => true,
				'recurring'      => false,
			],
		],
		'payuni_uni_embed'          => [
			'class' => PayuniUniEmbedGateway::class,
			'mode'  => 'client-sdk',
			'caps'  => [
				'refund'         => true,
				'partial_refund' => true,
				'capture'        => true,
				'void'           => true,
				'query'          => true,
				'recurring'      => false,
			],
		],
		'paynow'                    => [
			'class' => PaynowGateway::class,
			'mode'  => 'client-sdk',
			'caps'  => [
				'refund'         => true,
				'partial_refund' => true,
				'capture'        => false,
				'void'           => false,
				'query'          => true,
				'recurring'      => false,
			],
		],
		'tcat_pay'                  => [
			'class' => TcatPayGateway::class,
			'mode'  => 'api-redirect',
			'caps'  => [
				'refund'         => true,
				'partial_refund' => true,
				'capture'        => true,
				'void'           => true,
				'query'          => true,
				'recurring'      => false,
			],
		],
	];

	/** 啟用全部 gateway（最小設定，讓建構子正常執行） */
	protected function configure_dependencies(): void {
		\update_option( 'woocommerce_currency', 'TWD' );
		foreach ( \array_keys( self::EXPECTED ) as $id ) {
			ProviderUtils::update_option(
				$id,
				[
					'enabled' => 'yes',
					'title'   => "測試金流 {$id}",
				]
			);
		}
	}

	/**
	 * Data provider：逐家 gateway
	 *
	 * @return array<string, array{0: string}>
	 */
	public function gateway_provider(): array {
		$cases = [];
		foreach ( \array_keys( self::EXPECTED ) as $id ) {
			$cases[ $id ] = [ $id ];
		}
		return $cases;
	}

	/**
	 * 建立 gateway 實例
	 *
	 * @param string $id gateway id
	 * @return AbstractPaymentGateway
	 */
	private function make_gateway( string $id ): AbstractPaymentGateway {
		$class = self::EXPECTED[ $id ]['class'];
		return new $class();
	}

	/**
	 * 冒煙：實作 IPaymentProvider、回 ProviderMetaDTO、id 一致、6 個 capability 皆 bool
	 *
	 * @dataProvider gateway_provider
	 * @group smoke
	 *
	 * @param string $id gateway id
	 */
	public function test_冒煙_既有gateway回傳ProviderMetaDTO且能力皆為bool( string $id ): void {
		$gateway = $this->make_gateway( $id );

		$this->assertInstanceOf( IPaymentProvider::class, $gateway );
		$meta = $gateway->get_provider_meta();
		$this->assertInstanceOf( ProviderMetaDTO::class, $meta );
		$this->assertSame( $id, $meta->id );
		$this->assertSame( self::EXPECTED[ $id ]['class']::ID, $meta->id );

		$array = $meta->to_array();
		$this->assertSame( self::CAPABILITY_KEYS, \array_keys( $array['capabilities'] ) );
		foreach ( self::CAPABILITY_KEYS as $key ) {
			$this->assertIsBool( $array['capabilities'][ $key ], "{$id} capability {$key} 必須為 bool" );
		}
	}

	/**
	 * Happy：各家宣告值等於讀碼確認後的表（payment_mode / order_creation_strategy / capabilities）
	 *
	 * @dataProvider gateway_provider
	 * @group happy
	 *
	 * @param string $id gateway id
	 */
	public function test_各家宣告值符合程式現況( string $id ): void {
		$array = $this->make_gateway( $id )->get_provider_meta()->to_array();

		$this->assertSame( self::EXPECTED[ $id ]['mode'], $array['payment_mode'] );
		$this->assertSame( 'pre-create-pending', $array['order_creation_strategy'] );
		$this->assertSame( self::EXPECTED[ $id ]['caps'], $array['capabilities'] );
	}

	/**
	 * Happy：capture / void / query 宣告為 true ⇔ gateway 類別自己覆寫對應方法（方法層級定義）
	 *
	 * @dataProvider gateway_provider
	 * @group happy
	 *
	 * @param string $id gateway id
	 */
	public function test_能力宣告與方法覆寫一致( string $id ): void {
		$gateway = $this->make_gateway( $id );
		$caps    = $gateway->get_provider_meta()->capabilities;

		$map = [
			'capture'        => $caps->capture,
			'void_auth'      => $caps->void,
			'query_trade'    => $caps->query,
			'process_refund' => $caps->refund,
		];
		foreach ( $map as $method => $declared ) {
			$declaring  = ( new \ReflectionMethod( $gateway, $method ) )->getDeclaringClass()->getName();
			$overridden = AbstractPaymentGateway::class !== $declaring;
			$this->assertSame( $overridden, $declared, "{$id}::{$method} 宣告值與是否覆寫不一致" );
		}
	}

	/**
	 * Edge：未覆寫 get_provider_meta() 的子類回保守預設
	 *
	 * @group edge
	 */
	public function test_未覆寫的gateway回保守預設(): void {
		$gateway = new class() extends AbstractPaymentGateway {
			/** @var string gateway id */
			public $id = 'pc_test_meta_default_gateway';

			/**
			 * 最小設定
			 *
			 * @param bool $with_default 是否帶預設值
			 * @return array<string, mixed>
			 */
			public static function get_settings( bool $with_default = true ): array {
				return [
					'enabled' => 'yes',
					'title'   => '測試預設 meta',
				];
			}
		};

		$meta = $gateway->get_provider_meta();

		$this->assertSame( 'pc_test_meta_default_gateway', $meta->id );
		$this->assertSame( PaymentMode::FORM_REDIRECT, $meta->payment_mode );
		$this->assertSame( OrderCreationStrategy::PRE_CREATE_PENDING, $meta->order_creation_strategy );
		$this->assertFalse( $meta->capabilities->capture );
		$this->assertFalse( $meta->capabilities->void );
		$this->assertFalse( $meta->capabilities->query );
		$this->assertFalse( $meta->capabilities->recurring );
		// 未覆寫 process_refund（預設回 UNSUPPORTED）→ 不得宣告可退款
		$this->assertFalse( $meta->capabilities->refund );
		$this->assertFalse( $meta->capabilities->partial_refund );
	}

	/**
	 * Security：預設 verify_callback() fail-closed
	 *
	 * @group security
	 */
	public function test_預設verify_callback回false(): void {
		$gateway = new class() extends AbstractPaymentGateway {
			/** @var string gateway id */
			public $id = 'pc_test_meta_verify_gateway';

			/**
			 * 最小設定
			 *
			 * @param bool $with_default 是否帶預設值
			 * @return array<string, mixed>
			 */
			public static function get_settings( bool $with_default = true ): array {
				return [
					'enabled' => 'yes',
					'title'   => '測試 verify',
				];
			}
		};

		$request = new \WP_REST_Request( 'POST', '/power-checkout/test/callback' );
		$request->set_body( '{"any":"payload"}' );

		$this->assertFalse( $gateway->verify_callback( $request ) );
	}

	/**
	 * Happy：GET /settings 回應附帶 provider_meta（Vue 隱藏不支援的按鈕用）
	 *
	 * @group happy
	 */
	public function test_settings_API回應附帶各gateway的provider_meta(): void {
		$request  = new \WP_REST_Request( 'GET', '/power-checkout/v1/settings' );
		$response = SettingApiService::get_settings_callback( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'provider_meta', $data['data'] );
		$this->assertArrayHasKey( 'payments', $data['data']['provider_meta'] );
		$this->assertArrayHasKey( 'logistics', $data['data']['provider_meta'] );

		$payments = $data['data']['provider_meta']['payments'];
		$this->assertArrayHasKey( 'payuni_upp', $payments );
		$this->assertSame( self::EXPECTED['payuni_upp']['caps'], $payments['payuni_upp']['capabilities'] );
		$this->assertSame( 'client-sdk', $payments['paynow']['payment_mode'] );
	}
}
