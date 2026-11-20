<?php
/**
 * 物流 provider meta 契約測試（P0 統一介面）
 *
 * 既有 3 家（綠界 / PAYUNi / PayNow）實作 ILogisticsProvider::get_provider_meta()，
 * capability 與現行實作一致（Red 前讀碼確認）：
 *   - ecpay_logistics：cancel 僅 C2C 帳號可用（B2C 呼叫拋「僅支援 C2C」），仍宣告 true
 *   - payuni_logistics：cancel_shipment 一律拋「不支援直接取消」→ can_cancel=false
 *   - paynow_logistics：create_return 一律拋「尚未實作」→ can_return=false
 *   - tcat_logistics（P4）：只做宅配（ASM-6）；選店 / 取消拋 UNSUPPORTED → has_cvs_map / can_cancel=false
 *   - hct_logistics（P5）：純宅配；選店拋 UNSUPPORTED → has_cvs_map=false，其餘（含 TransDataCancel 取消）皆 true
 *   - sf_express（P6）：宅配；選店 / 退貨拋 UNSUPPORTED → has_cvs_map / can_return=false，建單 / 查詢 / 列印 / 取消 true
 *   - kerrytj_logistics（P7）：宅配；規格書無取消 API、選店不支援 → has_cvs_map / can_cancel=false，建單 / 查詢 / 列印 / 退貨（PickupV2）true
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter LogisticsProviderMetaContract --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Ecpay\Services\EcpayLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Hct\Services\HctLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\KerrytjLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\PayuniLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\DTOs\LogisticsProviderMetaDTO;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\SfExpressProvider;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\TcatLogisticsProvider;
use Tests\Integration\TestCase;

/**
 * 物流 provider meta 契約測試
 *
 * @group integration
 * @group logistics
 */
final class LogisticsProviderMetaContractTest extends TestCase {

	/** @var array<int, string> 6 個 capability 鍵（順序固定） */
	private const CAPABILITY_KEYS = [
		'can_create_shipment',
		'can_query_shipment',
		'has_cvs_map',
		'can_print',
		'can_return',
		'can_cancel',
	];

	/**
	 * 每家預期宣告
	 *
	 * @var array<string, array{class: class-string, methods: array<int, string>, caps: array<string, bool>}>
	 */
	private const EXPECTED = [
		'ecpay_logistics'  => [
			'class'   => EcpayLogisticsProvider::class,
			'methods' => [ 'HOME', 'CVS' ],
			'caps'    => [
				'can_create_shipment' => true,
				'can_query_shipment'  => true,
				'has_cvs_map'         => true,
				'can_print'           => true,
				'can_return'          => true,
				'can_cancel'          => true,
			],
		],
		'payuni_logistics' => [
			'class'   => PayuniLogisticsProvider::class,
			'methods' => [ 'HOME', 'CVS' ],
			'caps'    => [
				'can_create_shipment' => true,
				'can_query_shipment'  => true,
				'has_cvs_map'         => true,
				'can_print'           => true,
				'can_return'          => true,
				'can_cancel'          => false,
			],
		],
		'paynow_logistics' => [
			'class'   => PaynowLogisticsProvider::class,
			'methods' => [ 'HOME', 'CVS' ],
			'caps'    => [
				'can_create_shipment' => true,
				'can_query_shipment'  => true,
				'has_cvs_map'         => true,
				'can_print'           => true,
				'can_return'          => false,
				'can_cancel'          => true,
			],
		],
		'tcat_logistics'   => [
			'class'   => TcatLogisticsProvider::class,
			'methods' => [ 'HOME' ],
			'caps'    => [
				'can_create_shipment' => true,
				'can_query_shipment'  => true,
				'has_cvs_map'         => false,
				'can_print'           => true,
				'can_return'          => true,
				'can_cancel'          => false,
			],
		],
		'hct_logistics'    => [
			'class'   => HctLogisticsProvider::class,
			'methods' => [ 'HOME' ],
			'caps'    => [
				'can_create_shipment' => true,
				'can_query_shipment'  => true,
				'has_cvs_map'         => false,
				'can_print'           => true,
				'can_return'          => true,
				'can_cancel'          => true,
			],
		],
		'sf_express'       => [
			'class'   => SfExpressProvider::class,
			'methods' => [ 'HOME' ],
			'caps'    => [
				'can_create_shipment' => true,
				'can_query_shipment'  => true,
				'has_cvs_map'         => false,
				'can_print'           => true,
				'can_return'          => false,
				'can_cancel'          => true,
			],
		],
		'kerrytj_logistics' => [
			'class'   => KerrytjLogisticsProvider::class,
			'methods' => [ 'HOME' ],
			'caps'    => [
				'can_create_shipment' => true,
				'can_query_shipment'  => true,
				'has_cvs_map'         => false,
				'can_print'           => true,
				'can_return'          => true,
				'can_cancel'          => false,
			],
		],
	];

	/**
	 * Data provider：逐家
	 *
	 * @return array<string, array{0: string}>
	 */
	public function provider_ids(): array {
		$cases = [];
		foreach ( \array_keys( self::EXPECTED ) as $id ) {
			$cases[ $id ] = [ $id ];
		}
		return $cases;
	}

	/**
	 * 取得 provider 實例
	 *
	 * @param string $id provider id
	 * @return ILogisticsProvider
	 */
	private function make_provider( string $id ): ILogisticsProvider {
		$class    = self::EXPECTED[ $id ]['class'];
		$provider = $class::instance();
		$this->assertInstanceOf( ILogisticsProvider::class, $provider );
		return $provider;
	}

	/**
	 * 冒煙：回 LogisticsProviderMetaDTO，id 一致，6 個 capability 皆 bool
	 *
	 * @dataProvider provider_ids
	 * @group smoke
	 *
	 * @param string $id provider id
	 */
	public function test_冒煙_既有物流provider回傳meta_DTO( string $id ): void {
		$meta = $this->make_provider( $id )->get_provider_meta();

		$this->assertInstanceOf( LogisticsProviderMetaDTO::class, $meta );
		$this->assertSame( $id, $meta->id );
		$this->assertSame( self::EXPECTED[ $id ]['class']::ID, $meta->id );

		$array = $meta->to_array();
		$this->assertSame( self::CAPABILITY_KEYS, \array_keys( $array['capabilities'] ) );
		foreach ( self::CAPABILITY_KEYS as $key ) {
			$this->assertIsBool( $array['capabilities'][ $key ] );
		}
	}

	/**
	 * Happy：capability 與 supported_delivery_methods 與現行實作一致
	 *
	 * @dataProvider provider_ids
	 * @group happy
	 *
	 * @param string $id provider id
	 */
	public function test_能力宣告與現行實作一致( string $id ): void {
		$array = $this->make_provider( $id )->get_provider_meta()->to_array();

		$this->assertSame( self::EXPECTED[ $id ]['caps'], $array['capabilities'] );
		$this->assertSame( self::EXPECTED[ $id ]['methods'], $array['supported_delivery_methods'] );
	}
}
