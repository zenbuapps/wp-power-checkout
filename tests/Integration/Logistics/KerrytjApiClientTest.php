<?php
/**
 * 嘉里大榮 API client 測試（P7：kerrytj_logistics）
 *
 * - Basic Auth（`Authorization: Basic base64(帳號:密碼)`）；test → EDIEntranceTest、prod → EDIEntrance；貨況固定 KerryTracingEDI
 * - 寫入類 body 一律 JSON 陣列；GetLabelPdf 唯一例外為單一物件；GetDetailPdf 陣列
 * - Result 01 成功（回 AreaData）；02 / 03 帶原因 → 例外 raw_code 為嘉里大榮原文（重複 → CONFLICT，其餘 → VALIDATION）；03 無原因 → VALIDATION
 * - 401（2026-11-21 無憑證實打 golden）→ AUTH；WP_Error → NETWORK；非 JSON → PROVIDER
 * - Report：以 %PDF- 檔頭判定成功；ModelState JSON（實打 golden）→ VALIDATION 並攤平訊息
 * - 貨況：Status 1 → 事件（同一 BLN 合併去重）；Status 0 → PROVIDER；長度決定端點（11 → BLNListTracing、10 → ColdBLNListTracing）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter KerrytjApiClient --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs\KerrytjLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Http\KerrytjApiClient;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Exceptions\KerrytjLogisticsException;
use Tests\Integration\Logistics\Doubles\KerrytjLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 嘉里大榮 API client
 *
 * @group integration
 * @group logistics
 * @group kerrytj_logistics
 */
final class KerrytjApiClientTest extends TestCase {
	use KerrytjLogisticsTestTrait;

	/** 每個測試前：設定 */
	protected function configure_dependencies(): void {
		$this->setup_kerrytj_logistics();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_kerrytj_logistics();
		parent::tear_down();
	}

	/** @return KerrytjApiClient */
	private function client(): KerrytjApiClient {
		return new KerrytjApiClient( KerrytjLogisticsSettingsDTO::instance() );
	}

	/** @return array<string, string> 最小託運資料 */
	private function row( string $bln = '40350718600' ): array {
		return [
			'BLN'         => $bln,
			'Temperature' => '1',
		];
	}

	/**
	 * 斷言拋 KerrytjLogisticsException 並回傳
	 *
	 * @param callable $callback 執行內容
	 * @return KerrytjLogisticsException
	 */
	private function catch_error( callable $callback ): KerrytjLogisticsException {
		try {
			$callback();
		} catch ( KerrytjLogisticsException $e ) {
			return $e;
		}
		$this->fail( '預期拋出 KerrytjLogisticsException' );
	}

	/**
	 * Happy：建單送 Basic Auth、測試 base URL、JSON 陣列；回 AreaData
	 *
	 * @group happy
	 */
	public function test_建單BasicAuth與測試端點(): void {
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_ok' );

		$area = $this->client()->shipment_delivery( $this->row() );

		$request = $this->kerrytj_requests( 'ShipmentDelivery' )[0];
		$this->assertSame( 'https://api.kerrytj.com/EDIEntranceTest/ShipmentDelivery', $request['url'] );
		$this->assertSame( 'Basic ' . \base64_encode( self::$kerrytj_username . ':' . self::$kerrytj_password ), $request['headers']['Authorization'] ?? '' );
		$this->assertStringContainsString( 'application/json', (string) ( $request['headers']['Content-Type'] ?? '' ) );
		$this->assertTrue( \array_is_list( $request['json'] ) );
		$this->assertSame( '40350718600', $request['json'][0]['BLN'] );
		$this->assertSame( '台中', $area['StationName'] ?? null );
	}

	/**
	 * Happy：prod → EDIEntrance
	 *
	 * @group happy
	 */
	public function test_正式端點(): void {
		$this->setup_kerrytj_logistics( [ 'mode' => 'prod' ] );
		$this->fake_kerrytj( 'UpdateShipmentDelivery', 'update_ok' );

		$this->client()->update_shipment_delivery( $this->row() );

		$this->assertSame( 'https://api.kerrytj.com/EDIEntrance/UpdateShipmentDelivery', $this->kerrytj_requests( 'UpdateShipmentDelivery' )[0]['url'] );
	}

	/**
	 * Error：401 → AUTH（訊息不含帳密）
	 *
	 * @group error
	 */
	public function test_401回AUTH(): void {
		$this->fake_kerrytj(
			'ShipmentDelivery',
			[
				'status' => 401,
				'body'   => $this->kerrytj_fixture( 'unauthorized' ),
			]
		);

		$e = $this->catch_error( fn() => $this->client()->shipment_delivery( $this->row() ) );

		$this->assertSame( 'AUTH', $e->error_code->value );
		$this->assertStringNotContainsString( self::$kerrytj_password, $e->getMessage() );
	}

	/**
	 * Error：Result 02 → VALIDATION + 原文；Result 03 重複 → CONFLICT + is_duplicate_bln；03 無原因 → VALIDATION
	 *
	 * @group error
	 */
	public function test_寫入失敗分類(): void {
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_partial', 'shipment_duplicate', 'shipment_rejected' );

		$partial = $this->catch_error( fn() => $this->client()->shipment_delivery( $this->row() ) );
		$this->assertSame( 'VALIDATION', $partial->error_code->value );
		$this->assertSame( '客戶編號輸入錯誤', $partial->raw_code );
		$this->assertFalse( $partial->is_duplicate_bln() );

		$duplicate = $this->catch_error( fn() => $this->client()->shipment_delivery( $this->row() ) );
		$this->assertSame( 'CONFLICT', $duplicate->error_code->value );
		$this->assertTrue( $duplicate->is_duplicate_bln() );

		$rejected = $this->catch_error( fn() => $this->client()->shipment_delivery( $this->row() ) );
		$this->assertSame( 'VALIDATION', $rejected->error_code->value );
		$this->assertStringContainsString( '未提供原因', $rejected->getMessage() );
	}

	/**
	 * Edge：取件「取件編號重複 ,託運單號重複 ,」→ 仍認得是託運單號重複
	 *
	 * @group edge
	 */
	public function test_取件重複訊息判讀(): void {
		$this->fake_kerrytj( 'PickupV2', 'pickup_duplicate' );

		$e = $this->catch_error( fn() => $this->client()->pickup( $this->row() ) );

		$this->assertTrue( $e->is_duplicate_bln() );
	}

	/**
	 * Error：連線失敗 → NETWORK；非 JSON → PROVIDER
	 *
	 * @group error
	 */
	public function test_連線失敗與非JSON(): void {
		$this->fake_kerrytj(
			'ShipmentDelivery',
			new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ),
			[
				'status' => 500,
				'body'   => '<html>err</html>',
				'type'   => 'text/html',
			]
		);

		$this->assertSame( 'NETWORK', $this->catch_error( fn() => $this->client()->shipment_delivery( $this->row() ) )->error_code->value );
		$this->assertSame( 'PROVIDER', $this->catch_error( fn() => $this->client()->shipment_delivery( $this->row() ) )->error_code->value );
	}

	/**
	 * Happy：GetLabelPdf body 為單一物件、回 PDF bytes；GetDetailPdf body 為陣列
	 *
	 * @group happy
	 */
	public function test_標籤與明細PDF(): void {
		$this->fake_kerrytj( 'Report/GetLabelPdf', 'pdf' );
		$this->fake_kerrytj( 'Report/GetDetailPdf', 'pdf' );

		$label  = $this->client()->label_pdf( $this->row() );
		$detail = $this->client()->detail_pdf( [ $this->row() ] );

		$this->assertStringStartsWith( '%PDF-', $label );
		$this->assertStringStartsWith( '%PDF-', $detail );
		$label_json = $this->kerrytj_request_json( 'Report/GetLabelPdf' );
		$this->assertFalse( \array_is_list( $label_json ) );
		$this->assertSame( '40350718600', $label_json['BLN'] );
		$this->assertTrue( \array_is_list( $this->kerrytj_request_json( 'Report/GetDetailPdf' ) ) );
		$this->assertArrayHasKey( 'Authorization', $this->kerrytj_requests( 'Report/GetLabelPdf' )[0]['headers'] );
	}

	/**
	 * Error：Report ModelState → VALIDATION 並攤平訊息；明細空陣列不打 API
	 *
	 * @group error
	 */
	public function test_標籤ModelState與空明細(): void {
		$this->fake_kerrytj(
			'Report/GetLabelPdf',
			[
				'status' => 400,
				'body'   => $this->kerrytj_fixture( 'label_model_state' ),
			]
		);

		$e = $this->catch_error( fn() => $this->client()->label_pdf( $this->row() ) );
		$this->assertSame( 'VALIDATION', $e->error_code->value );
		$this->assertStringContainsString( '收貨人郵區必填', $e->getMessage() );
		$this->assertStringContainsString( '寄貨人電話必填', $e->getMessage() );

		$empty = $this->catch_error( fn() => $this->client()->detail_pdf( [] ) );
		$this->assertSame( 'VALIDATION', $empty->error_code->value );
		$this->assertSame( [], $this->kerrytj_requests( 'Report/GetDetailPdf' ) );
	}

	/**
	 * Happy：常溫 11 碼 → BLNListTracing；低溫 10 碼 → ColdBLNListTracing（同 BLN 合併去重）
	 *
	 * @group happy
	 */
	public function test_貨況依長度分流(): void {
		$this->fake_kerrytj( 'BLNListTracing', 'tracing_ambient_ok' );
		$this->fake_kerrytj( 'ColdBLNListTracing', 'tracing_cold_ok' );

		$ambient = $this->client()->tracing( '40350718600' );
		$cold    = $this->client()->tracing( '6670000002' );

		$request = $this->kerrytj_requests( 'BLNListTracing' )[0];
		$this->assertSame( 'https://KerryTracingEDI.kerrytj.com/api/Tracing/BLNListTracing', $request['url'] );
		$this->assertSame( [ [ 'BLN' => '40350718600' ] ], $request['json'] );
		$this->assertCount( 3, $ambient );
		$this->assertSame( 1, $ambient[0]['status_code_type'] );
		$this->assertSame( [ [ 'BLN' => '6670000002' ] ], $this->kerrytj_request_json( 'ColdBLNListTracing' ) );
		$this->assertCount( 2, $cold );
		$this->assertNull( $cold[0]['status_code_type'] );
	}

	/**
	 * Error：貨況 Status 0 → PROVIDER；非法單號不打 API
	 *
	 * @group error
	 */
	public function test_貨況失敗與非法單號(): void {
		$this->fake_kerrytj( 'BLNListTracing', 'tracing_fail' );

		$failed = $this->catch_error( fn() => $this->client()->tracing( '40350718600' ) );
		$this->assertSame( 'PROVIDER', $failed->error_code->value );
		$this->assertStringContainsString( '查詢失敗', $failed->getMessage() );

		$invalid = $this->catch_error( fn() => $this->client()->tracing( 'ABC' ) );
		$this->assertSame( 'VALIDATION', $invalid->error_code->value );
		$this->assertCount( 1, $this->kerrytj_requests( 'BLNListTracing' ) );
	}

	/**
	 * Happy：verify_credentials 以 UpdateShipmentDelivery 空陣列驗證帳密（不寫入任何資料）；401 → AUTH
	 *
	 * @group happy
	 */
	public function test_驗證帳密(): void {
		$this->fake_kerrytj(
			'UpdateShipmentDelivery',
			'shipment_rejected',
			[
				'status' => 401,
				'body'   => $this->kerrytj_fixture( 'unauthorized' ),
			]
		);

		$this->client()->verify_credentials();
		$this->assertSame( [], $this->kerrytj_request_json( 'UpdateShipmentDelivery' ) );

		$e = $this->catch_error( fn() => $this->client()->verify_credentials() );
		$this->assertSame( 'AUTH', $e->error_code->value );
	}
}
