<?php
/**
 * 嘉里大榮請求欄位組裝測試（P7：kerrytj_logistics）
 *
 * - ShipmentDelivery / UpdateShipmentDelivery 34 欄（全字串、選填送 ""，api-reference §3）；標籤 16 欄（§7.1）；託運明細 26 欄（§7.2）
 * - 姓名截 12 字（zenbu 測試機實測：伺服器上限 30、標籤約可印 15 字），全名移到備註；電話 > 15 / 地址 > 60 → 拒絕（截斷會送錯）
 * - 郵遞區號只收 3 碼：訂單郵遞區號優先，否則以縣市 + 鄉鎮市區查表（台 / 臺不分）
 * - 溫層須與單號格式一致（常溫 11 碼 / 低溫 10 碼）；低溫 CON 上限 10 字；訂單編號放不下 → CON 空、寫備註
 * - PickupV2 17 欄：Shipper* = 取件地（顧客）、Consignee* = 送回地（商家）；PickupNo = 訂單編號 + 取件單號末 5 碼（≤ 30）
 * 向量移植自 zenbu kerrytj-payload.spec.ts。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter KerrytjShipmentParams --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs\KerrytjLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs\PickupParams;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs\ShipmentDeliveryParams;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjTemperature;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Exceptions\KerrytjLogisticsException;
use Tests\Integration\Logistics\Doubles\KerrytjLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 嘉里大榮欄位組裝
 *
 * @group integration
 * @group logistics
 * @group kerrytj_logistics
 */
final class KerrytjShipmentParamsTest extends TestCase {
	use KerrytjLogisticsTestTrait;

	/** @var array<int, string> 規格書 34 欄 */
	private const SHIPMENT_KEYS = [
		'ShipDate', 'CustomerNo', 'Consignee', 'ConsigneePost', 'ConsigneeAdd', 'ConsigneePhone', 'BLN',
		'Weight', 'Volume', 'Shipper', 'ShipperPost', 'ShipperAdd', 'ShipperTel', 'CON', 'AC',
		'PickupTime', 'ETA', 'Remark', 'Temperature', 'Piece', 'DeclaredValue', 'SpecialService',
		'StationEnable', 'Receipt', 'HolidayId', 'MemberId', 'BoxNumber', 'IsValuable', 'CityType',
		'Specification', 'BoxSize', 'DailyDelyId', 'PaymentType', 'ETP',
	];

	/** 每個測試前：設定 */
	protected function configure_dependencies(): void {
		$this->setup_kerrytj_logistics();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_kerrytj_logistics();
		parent::tear_down();
	}

	/**
	 * 組常溫託運資料
	 *
	 * @param \WC_Order          $order       訂單
	 * @param KerrytjTemperature $temperature 溫層
	 * @param string             $bln         單號
	 * @return array<string, string>
	 */
	private function build( \WC_Order $order, KerrytjTemperature $temperature = KerrytjTemperature::AMBIENT, string $bln = '40350718602' ): array {
		return ShipmentDeliveryParams::from_order( $order, KerrytjLogisticsSettingsDTO::instance(), $temperature, '20261121', $bln )->to_api_row();
	}

	/**
	 * 斷言拋 VALIDATION 並回訊息
	 *
	 * @param callable $callback 執行內容
	 * @return string
	 */
	private function validation_message( callable $callback ): string {
		try {
			$callback();
		} catch ( KerrytjLogisticsException $e ) {
			$this->assertSame( 'VALIDATION', $e->error_code->value );
			return $e->getMessage();
		}
		$this->fail( '預期拋出 VALIDATION' );
	}

	/**
	 * Happy：34 欄、全部字串、核心欄位正確
	 *
	 * @group happy
	 */
	public function test_34欄全字串(): void {
		$order = $this->make_kerrytj_order();
		$row   = $this->build( $order );

		$this->assertSame( self::SHIPMENT_KEYS, \array_keys( $row ) );
		foreach ( $row as $key => $value ) {
			$this->assertIsString( $value, $key );
		}
		$this->assertSame( '20261121', $row['ShipDate'] );
		$this->assertSame( self::$kerrytj_customer_no, $row['CustomerNo'] );
		$this->assertSame( '王小明', $row['Consignee'] );
		$this->assertSame( '407', $row['ConsigneePost'] );
		$this->assertSame( '台中市西屯區某路2號', $row['ConsigneeAdd'] );
		$this->assertSame( '0912345678', $row['ConsigneePhone'] );
		$this->assertSame( '40350718602', $row['BLN'] );
		$this->assertSame( '某某商行', $row['Shipper'] );
		$this->assertSame( '104', $row['ShipperPost'] );
		$this->assertSame( '台北市中山區某路1號', $row['ShipperAdd'] );
		$this->assertSame( '0223456789', $row['ShipperTel'] );
		$this->assertSame( (string) $order->get_order_number(), $row['CON'] );
		$this->assertSame( '1', $row['Temperature'] );
		$this->assertSame( '1', $row['Piece'] );
		$this->assertSame( '', $row['AC'] );
		$this->assertSame( '', $row['DeclaredValue'] );
		$this->assertSame( '', $row['PaymentType'] );
	}

	/**
	 * Edge：姓名超過 12 字 → 截斷，全名放備註；12 字以內原樣
	 *
	 * @group edge
	 */
	public function test_姓名截斷12字全名進備註(): void {
		$long = $this->build( $this->make_kerrytj_order( [ 'name_last' => '歐陽', 'name_first' => '小明同學會長與其他九位理事長' ] ) );
		$this->assertSame( '歐陽小明同學會長與其他九', $long['Consignee'] );
		$this->assertStringContainsString( '歐陽小明同學會長與其他九位理事長', $long['Remark'] );

		$short = $this->build( $this->make_kerrytj_order( [ 'name_last' => '歐陽', 'name_first' => '小明同學會長' ] ) );
		$this->assertSame( '歐陽小明同學會長', $short['Consignee'] );
		$this->assertStringNotContainsString( '收件人', $short['Remark'] );
	}

	/**
	 * Error：電話 > 15 碼 / 地址 > 60 字 → 拒絕
	 *
	 * @group error
	 */
	public function test_電話地址超長拒絕(): void {
		$this->assertStringContainsString( '電話', $this->validation_message( fn() => $this->build( $this->make_kerrytj_order( [ 'phone' => '0900000123#12345678' ] ) ) ) );
		$this->assertStringContainsString( '地址', $this->validation_message( fn() => $this->build( $this->make_kerrytj_order( [ 'address' => \str_repeat( '台', 61 ) ] ) ) ) );
	}

	/**
	 * Happy：無郵遞區號 → 縣市 + 鄉鎮市區查表（台 / 臺不分）；「東區」不吃掉「東勢區」
	 *
	 * @group happy
	 */
	public function test_郵遞區號由地址推導(): void {
		$this->assertSame(
			'100',
			$this->build(
				$this->make_kerrytj_order(
					[
						'postcode' => '',
						'city'     => '台北市中正區',
						'address'  => '新生南路一段50號',
					]
				)
			)['ConsigneePost']
		);
		$dongshi = $this->build(
			$this->make_kerrytj_order(
				[
					'postcode' => '',
					'city'     => '臺中市東勢區',
				]
			)
		)['ConsigneePost'];
		$dong    = $this->build(
			$this->make_kerrytj_order(
				[
					'postcode' => '',
					'city'     => '臺中市東區',
				]
			)
		)['ConsigneePost'];
		$this->assertSame( '423', $dongshi );
		$this->assertSame( '401', $dong );
	}

	/**
	 * Error：查不到郵遞區號 → 拒絕
	 *
	 * @group error
	 */
	public function test_無法判斷郵遞區號拒絕(): void {
		$message = $this->validation_message(
			fn() => $this->build(
				$this->make_kerrytj_order(
					[
						'postcode' => '',
						'city'     => '',
						'address'  => '新生南路一段50號',
					]
				)
			)
		);
		$this->assertStringContainsString( '郵遞區號', $message );
	}

	/**
	 * Error：溫層與單號格式不符 → 拒絕
	 *
	 * @group error
	 */
	public function test_溫層與單號不符拒絕(): void {
		$order = $this->make_kerrytj_order();
		$this->assertStringContainsString( '溫層', $this->validation_message( fn() => $this->build( $order, KerrytjTemperature::FROZEN, '40350718602' ) ) );
		$this->assertStringContainsString( '溫層', $this->validation_message( fn() => $this->build( $order, KerrytjTemperature::AMBIENT, '6670000002' ) ) );
	}

	/**
	 * Edge：低溫 CON 上限 10 字；放不下 → CON 空 + 備註
	 *
	 * @group edge
	 */
	public function test_低溫CON上限10字(): void {
		$order = $this->make_kerrytj_order();
		$cold  = $this->build( $order, KerrytjTemperature::FROZEN, '6670000002' );
		$this->assertSame( '3', $cold['Temperature'] );
		$this->assertSame( (string) $order->get_order_number(), $cold['CON'] );

		\add_filter( 'woocommerce_order_number', [ $this, 'long_order_number' ] );
		try {
			$long = $this->build( $order, KerrytjTemperature::CHILLED, '6670000002' );
		} finally {
			\remove_filter( 'woocommerce_order_number', [ $this, 'long_order_number' ] );
		}
		$this->assertSame( '', $long['CON'] );
		$this->assertStringContainsString( 'A1029001234', $long['Remark'] );
	}

	/** @return string 11 字訂單編號（低溫放不下） */
	public function long_order_number(): string {
		return 'A1029001234';
	}

	/**
	 * Happy：標籤 16 欄、託運明細 26 欄逐字取自 34 欄
	 *
	 * @group happy
	 */
	public function test_標籤16欄與明細26欄(): void {
		$row    = $this->build( $this->make_kerrytj_order() );
		$label  = ShipmentDeliveryParams::label_fields( $row );
		$detail = ShipmentDeliveryParams::detail_fields( $row );

		$this->assertCount( 16, $label );
		$this->assertSame( [ 'ShipDate', 'CustomerNo', 'Consignee', 'ConsigneePost', 'ConsigneeAdd', 'ConsigneePhone', 'BLN', 'Shipper', 'ShipperPost', 'ShipperAdd', 'ShipperTel', 'Piece', 'CON', 'AC', 'Remark', 'Temperature' ], \array_keys( $label ) );
		$this->assertSame( $row['BLN'], $label['BLN'] );
		$this->assertCount( 26, $detail );
		foreach ( [ 'Weight', 'Volume', 'ETA', 'DeclaredValue', 'SpecialService', 'StationEnable', 'Receipt', 'HolidayId', 'PaymentType', 'ETP' ] as $key ) {
			$this->assertArrayHasKey( $key, $detail );
		}
		$this->assertArrayNotHasKey( 'MemberId', $detail );
	}

	/**
	 * Happy：PickupV2 17 欄、方向依官方欄位名、PickupNo = 訂單編號 + 單號末 5 碼
	 *
	 * @group happy
	 */
	public function test_取件17欄方向與取件編號(): void {
		$order = $this->make_kerrytj_order();
		$row   = PickupParams::from_order( $order, KerrytjLogisticsSettingsDTO::instance(), '40350718603' )->to_api_row();

		$this->assertCount( 17, $row );
		$this->assertSame( '王小明', $row['Shipper'] );
		$this->assertSame( '台中市西屯區某路2號', $row['ShipperAdd'] );
		$this->assertSame( '407', $row['ShipperPost'] );
		$this->assertSame( '0912345678', $row['ShipperTel1'] );
		$this->assertSame( '某某商行', $row['Consignee'] );
		$this->assertSame( '台北市中山區某路1號', $row['ConsigneeAdd'] );
		$this->assertSame( '104', $row['ConsigneePost'] );
		$this->assertSame( '0223456789', $row['ConsigneeTel1'] );
		$this->assertSame( '1', $row['Piece'] );
		$this->assertSame( (string) $order->get_order_number() . '-18603', $row['PickupNo'] );
		$this->assertLessThanOrEqual( 30, \mb_strlen( $row['PickupNo'] ) );
	}

	/**
	 * Error：取件只收常溫號段
	 *
	 * @group error
	 */
	public function test_取件只收常溫號段(): void {
		$order = $this->make_kerrytj_order();
		$this->assertStringContainsString( '常溫', $this->validation_message( fn() => PickupParams::from_order( $order, KerrytjLogisticsSettingsDTO::instance(), '6670000002' ) ) );
	}

	/**
	 * Error：寄件人未設定 → 拒絕並列出欄位（不回顯值）
	 *
	 * @group error
	 */
	public function test_寄件人未設定拒絕(): void {
		$this->setup_kerrytj_logistics( [ 'sender_phone' => '' ] );
		$message = $this->validation_message( fn() => $this->build( $this->make_kerrytj_order() ) );
		$this->assertStringContainsString( '寄件人', $message );
	}
}
