<?php
/**
 * 嘉里大榮 provider 測試（P7：kerrytj_logistics）
 *
 *  - create_shipment = ShipmentDelivery：溫層（結帳 meta → 設定預設）決定號段；我方原子配號（P5 WaybillNumberAllocator，
 *    key 分環境 × 溫層）；配號後**先記在訂單**（pending BLN）再呼叫 API，失敗重試沿用同號（號碼不回收）；
 *    沿用號重送回「託運單號重複」= 上次其實已建成 → 視為成功。寫 ref / 快照（34 欄）/ 到著站
 *  - print_document  = Report/GetLabelPdf（單一物件，取自建單快照）→ PDF；print_detail = Report/GetDetailPdf
 *  - update_shipment = UpdateShipmentDelivery（同 BLN、送完整資料）；已交寄（貨況非 pending）→ CONFLICT 不打 API
 *  - query_shipment  = 常溫 BLNListTracing / 低溫 ColdBLNListTracing（依單號長度分流）→ ShippingState
 *  - create_return   = PickupV2（常溫取件；另配常溫號段新單號、PickupNo 帶單號末 5 碼）
 *  - cancel / 選店 → UNSUPPORTED（規格書無取消 API）；無貨態推送 → 200 no-op
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter KerrytjLogisticsProvider --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\KerrytjLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Exceptions\KerrytjLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjLogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\Shared\Exceptions\WaybillNumberException;
use Tests\Integration\Logistics\Doubles\KerrytjLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 嘉里大榮 provider
 *
 * @group integration
 * @group logistics
 * @group kerrytj_logistics
 */
final class KerrytjLogisticsProviderTest extends TestCase {
	use KerrytjLogisticsTestTrait;

	/** 每個測試前：啟用嘉里大榮 */
	protected function configure_dependencies(): void {
		$this->setup_kerrytj_logistics();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_kerrytj_logistics();
		parent::tear_down();
	}

	/** @return KerrytjLogisticsProvider */
	private function provider(): KerrytjLogisticsProvider {
		return KerrytjLogisticsProvider::instance();
	}

	/**
	 * 斷言拋例外並回傳
	 *
	 * @param callable $callback 執行內容
	 * @return \Throwable
	 */
	private function catch_error( callable $callback ): \Throwable {
		try {
			$callback();
		} catch ( \Throwable $e ) {
			return $e;
		}
		$this->fail( '預期拋出例外' );
	}

	/**
	 * @param \WC_Order $order 訂單
	 * @return string 全部 order note
	 */
	private function notes( \WC_Order $order ): string {
		return \implode( "\n", \array_map( static fn( $note ): string => (string) $note->content, \wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) ) );
	}

	/**
	 * 建立已建單（常溫）訂單
	 *
	 * @param array<string, mixed> $args 訂單覆寫
	 * @return \WC_Order
	 */
	private function make_shipped_order( array $args = [] ): \WC_Order {
		$order = $this->make_kerrytj_order( $args );
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_ok' );
		$this->provider()->create_shipment( $order );
		$this->kerrytj_requests = [];
		return \wc_get_order( $order->get_id() );
	}

	// region create_shipment

	/**
	 * Happy：常溫建單 → 配常溫號段第一號、寫 ref / 快照 / 到著站 / note
	 *
	 * @group happy
	 */
	public function test_常溫建單成功(): void {
		$order = $this->make_kerrytj_order();
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_ok' );

		$result = $this->provider()->create_shipment( $order );

		$this->assertSame( self::$kerrytj_ambient_first, $result['logistics_id'] );
		$this->assertSame( '台中', $result['station'] );
		$this->assertSame( '1', $result['temperature'] );
		$sent = $this->kerrytj_request_json( 'ShipmentDelivery' )[0];
		$this->assertSame( self::$kerrytj_ambient_first, $sent['BLN'] );
		$this->assertSame( '1', $sent['Temperature'] );

		$fresh = \wc_get_order( $order->get_id() );
		$meta  = new KerrytjLogisticsMetaKeys( $fresh );
		$this->assertSame( self::$kerrytj_ambient_first, $meta->get_ref() );
		$this->assertSame( '', $meta->get_pending_bln() );
		$this->assertSame( 'test', $meta->get_mode() );
		$this->assertSame( $sent, $meta->get_shipment() );
		$this->assertSame( KerrytjLogisticsProvider::ID, $fresh->get_meta( KerrytjLogisticsMetaKeys::PROVIDER_ID_KEY ) );
		$this->assertStringContainsString( self::$kerrytj_ambient_first, $this->notes( $fresh ) );
	}

	/**
	 * Happy：結帳選冷凍 → 低溫號段（9 碼 + MOD 7）
	 *
	 * @group happy
	 */
	public function test_冷凍建單用低溫號段(): void {
		$order = $this->make_kerrytj_order( [ 'order_meta' => [ KerrytjLogisticsMetaKeys::TEMPERATURE_KEY => '3' ] ] );
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_ok' );

		$result = $this->provider()->create_shipment( $order );

		$this->assertSame( self::$kerrytj_cold_first, $result['logistics_id'] );
		$this->assertSame( '3', $this->kerrytj_request_json( 'ShipmentDelivery' )[0]['Temperature'] );
	}

	/**
	 * Edge：已建單 → 冪等回既有，不打 API、不配號
	 *
	 * @group edge
	 */
	public function test_已建單冪等(): void {
		$order = $this->make_shipped_order();
		$this->block_kerrytj_http();

		$result = $this->provider()->create_shipment( $order );

		$this->assertTrue( $result['idempotent'] );
		$this->assertSame( self::$kerrytj_ambient_first, $result['logistics_id'] );
		$this->assertSame( [], $this->kerrytj_requests );
		$this->assertSame( '40350718601', KerrytjLogisticsProvider::waybill_allocator( \J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjBlnKind::AMBIENT, 'test' )->status()['next'] );
	}

	/**
	 * Edge：API 逾時 → 號碼不回收、記為 pending；重試沿用同號（不另配）
	 *
	 * @group edge
	 */
	public function test_失敗重試沿用同號(): void {
		$order = $this->make_kerrytj_order();
		$this->fake_kerrytj( 'ShipmentDelivery', new \WP_Error( 'http_request_failed', 'timeout' ), 'shipment_ok' );

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $order ) );
		$this->assertSame( 'NETWORK', $e->error_code->value );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( self::$kerrytj_ambient_first, ( new KerrytjLogisticsMetaKeys( $fresh ) )->get_pending_bln() );
		$this->assertStringContainsString( '嘉里大榮建單失敗', $this->notes( $fresh ) );

		$result = $this->provider()->create_shipment( $fresh );

		$this->assertSame( self::$kerrytj_ambient_first, $result['logistics_id'] );
		$this->assertSame( self::$kerrytj_ambient_first, $this->kerrytj_request_json( 'ShipmentDelivery', 1 )[0]['BLN'] );
	}

	/**
	 * Edge：沿用號重送回「託運單號重複」→ 視為上次已建成功
	 *
	 * @group edge
	 */
	public function test_沿用號重送回重複視為成功(): void {
		$order = $this->make_kerrytj_order();
		$this->fake_kerrytj( 'ShipmentDelivery', new \WP_Error( 'http_request_failed', 'timeout' ), 'shipment_duplicate' );
		$this->catch_error( fn() => $this->provider()->create_shipment( $order ) );

		$result = $this->provider()->create_shipment( \wc_get_order( $order->get_id() ) );

		$this->assertSame( self::$kerrytj_ambient_first, $result['logistics_id'] );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( self::$kerrytj_ambient_first, ( new KerrytjLogisticsMetaKeys( $fresh ) )->get_ref() );
		$this->assertStringContainsString( '託運單號重複', $this->notes( $fresh ) );
	}

	/**
	 * Security：新配的號（非沿用）回「託運單號重複」→ 不可當成功（可能撞到別人的單）
	 *
	 * @group security
	 */
	public function test_新號回重複不當成功(): void {
		$order = $this->make_kerrytj_order();
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_duplicate' );

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $order ) );

		$this->assertSame( 'CONFLICT', $e->error_code->value );
		$this->assertSame( '', ( new KerrytjLogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_ref() );
	}

	/**
	 * Error：貨到付款 → VALIDATION，不配號、不打 API
	 *
	 * @group error
	 */
	public function test_貨到付款拒絕(): void {
		$order = $this->make_kerrytj_order( [ 'payment_method' => 'cod' ] );
		$this->block_kerrytj_http();

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $order ) );

		$this->assertSame( 'VALIDATION', $e->error_code->value );
		$this->assertSame( [], $this->kerrytj_requests );
		$this->assertSame( '', ( new KerrytjLogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_pending_bln() );
	}

	/**
	 * Error：收件電話缺 → VALIDATION，不消耗號碼
	 *
	 * @group error
	 */
	public function test_欄位錯誤不消耗號碼(): void {
		$order = $this->make_kerrytj_order( [ 'phone' => '' ] );
		$this->block_kerrytj_http();

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $order ) );

		$this->assertSame( 'VALIDATION', $e->error_code->value );
		$this->assertSame( self::$kerrytj_ambient_first, KerrytjLogisticsProvider::waybill_allocator( \J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjBlnKind::AMBIENT, 'test' )->status()['next'] );
	}

	/**
	 * Error：未設定該溫層號段 → VALIDATION；號段用罄 → NUMBER_EXHAUSTED
	 *
	 * @group error
	 */
	public function test_號段未設定與用罄(): void {
		$this->setup_kerrytj_logistics(
			[
				'cold_waybill_start'    => '',
				'cold_waybill_end'      => '',
				'ambient_waybill_start' => '40350718600',
				'ambient_waybill_end'   => '40350718600',
			]
		);
		$cold = $this->make_kerrytj_order( [ 'order_meta' => [ KerrytjLogisticsMetaKeys::TEMPERATURE_KEY => '2' ] ] );
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_ok' );

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $cold ) );
		$this->assertSame( 'VALIDATION', $e->error_code->value );
		$this->assertStringContainsString( '低溫', $e->getMessage() );

		$this->provider()->create_shipment( $this->make_kerrytj_order() );
		$exhausted = $this->catch_error( fn() => $this->provider()->create_shipment( $this->make_kerrytj_order() ) );
		$this->assertInstanceOf( WaybillNumberException::class, $exhausted );
		$this->assertSame( 'NUMBER_EXHAUSTED', $exhausted->error_code->value );
	}

	/**
	 * Error：帳密未設定 → VALIDATION
	 *
	 * @group error
	 */
	public function test_帳密未設定(): void {
		$this->setup_kerrytj_logistics( [ 'password' => '' ] );
		$this->block_kerrytj_http();

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $this->make_kerrytj_order() ) );

		$this->assertSame( 'VALIDATION', $e->error_code->value );
		$this->assertSame( [], $this->kerrytj_requests );
	}

	// endregion

	// region print / detail / update

	/**
	 * Happy：列印標籤 → GetLabelPdf（單一物件、取自建單快照）回 PDF
	 *
	 * @group happy
	 */
	public function test_列印標籤PDF(): void {
		$order = $this->make_shipped_order();
		$this->fake_kerrytj( 'Report/GetLabelPdf', 'pdf' );

		$pdf = $this->provider()->print_document( $order );

		$this->assertStringStartsWith( '%PDF-', $pdf );
		$sent = $this->kerrytj_request_json( 'Report/GetLabelPdf' );
		$this->assertSame( self::$kerrytj_ambient_first, $sent['BLN'] );
		$this->assertCount( 16, $sent );
	}

	/**
	 * Happy：託運明細 → GetDetailPdf（陣列、26 欄）
	 *
	 * @group happy
	 */
	public function test_託運明細PDF(): void {
		$order = $this->make_shipped_order();
		$this->fake_kerrytj( 'Report/GetDetailPdf', 'pdf' );

		$pdf = $this->provider()->print_detail( $order );

		$this->assertStringStartsWith( '%PDF-', $pdf );
		$this->assertCount( 26, $this->kerrytj_request_json( 'Report/GetDetailPdf' )[0] );
	}

	/**
	 * Error：尚未建單列印 → CONFLICT；建單環境與目前環境不同 → CONFLICT
	 *
	 * @group error
	 */
	public function test_列印前置檢查(): void {
		$this->block_kerrytj_http();
		$e = $this->catch_error( fn() => $this->provider()->print_document( $this->make_kerrytj_order() ) );
		$this->assertSame( 'CONFLICT', $e->error_code->value );

		$order = $this->make_shipped_order();
		$this->setup_kerrytj_logistics( [ 'mode' => 'prod' ] );
		$mismatch = $this->catch_error( fn() => $this->provider()->print_document( $order ) );
		$this->assertSame( 'CONFLICT', $mismatch->error_code->value );
		$this->assertStringContainsString( '測試', $mismatch->getMessage() );
	}

	/**
	 * Happy：修改託運資料 → UpdateShipmentDelivery（同 BLN、新地址、快照更新）
	 *
	 * @group happy
	 */
	public function test_修改託運資料(): void {
		$order = $this->make_shipped_order();
		$order->set_shipping_address_1( '某路99號' );
		$order->save();
		$this->fake_kerrytj( 'UpdateShipmentDelivery', 'update_ok' );

		$result = $this->provider()->update_shipment( $order );

		$this->assertTrue( $result['updated'] );
		$sent = $this->kerrytj_request_json( 'UpdateShipmentDelivery' )[0];
		$this->assertCount( 34, $sent );
		$this->assertSame( self::$kerrytj_ambient_first, $sent['BLN'] );
		$this->assertSame( '台中市西屯區某路99號', $sent['ConsigneeAdd'] );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( '台中市西屯區某路99號', ( new KerrytjLogisticsMetaKeys( $fresh ) )->get_shipment()['ConsigneeAdd'] );
		$this->assertStringContainsString( '已修改', $this->notes( $fresh ) );
	}

	/**
	 * Error：已交寄（貨況非 pending）→ CONFLICT 不打 API；修改失敗 → 例外 + 快照不變
	 *
	 * @group error
	 */
	public function test_修改前置與失敗(): void {
		$order = $this->make_shipped_order();
		$this->fake_kerrytj( 'UpdateShipmentDelivery', 'update_fail' );
		$failed = $this->catch_error( fn() => $this->provider()->update_shipment( $order ) );
		$this->assertSame( 'VALIDATION', $failed->error_code->value );

		( new KerrytjLogisticsMetaKeys( $order ) )->save_status( '01', '集貨', ShippingState::ACCEPTED->value, '20261121 100000' );
		$this->kerrytj_requests = [];
		$e = $this->catch_error( fn() => $this->provider()->update_shipment( \wc_get_order( $order->get_id() ) ) );
		$this->assertSame( 'CONFLICT', $e->error_code->value );
		$this->assertSame( [], $this->kerrytj_requests );
	}

	// endregion

	// region query

	/**
	 * Happy：常溫查詢 → BLNListTracing、取時間最新（30 聯運配達）→ DELIVERED、寫 meta + note
	 *
	 * @group happy
	 */
	public function test_常溫查詢貨況(): void {
		$order = $this->make_shipped_order();
		$this->fake_kerrytj( 'BLNListTracing', 'tracing_ambient_ok' );

		$result = $this->provider()->query_shipment( $order );

		$this->assertSame( self::$kerrytj_ambient_first, $result['logistics_id'] );
		$this->assertSame( '30', $result['status'] );
		$this->assertSame( ShippingState::DELIVERED->value, $result['unified_state'] );
		$this->assertSame( '20200530 155211', $result['status_time'] );
		$this->assertCount( 3, $result['history'] );
		$this->assertSame( [], $this->kerrytj_requests( 'ColdBLNListTracing' ) );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( ShippingState::DELIVERED->value, ( new KerrytjLogisticsMetaKeys( $fresh ) )->get_status()['unified_state'] );
		$this->assertStringContainsString( '貨況更新', $this->notes( $fresh ) );
	}

	/**
	 * Happy：低溫查詢 → ColdBLNListTracing（91 = 配達）
	 *
	 * @group happy
	 */
	public function test_低溫查詢貨況分流(): void {
		$order = $this->make_kerrytj_order( [ 'order_meta' => [ KerrytjLogisticsMetaKeys::TEMPERATURE_KEY => '2' ] ] );
		$this->fake_kerrytj( 'ShipmentDelivery', 'shipment_ok' );
		$this->provider()->create_shipment( $order );
		$this->fake_kerrytj( 'ColdBLNListTracing', 'tracing_cold_ok' );

		$result = $this->provider()->query_shipment( \wc_get_order( $order->get_id() ) );

		$this->assertSame( ShippingState::DELIVERED->value, $result['unified_state'] );
		$this->assertSame( [], $this->kerrytj_requests( 'BLNListTracing' ) );
		$this->assertSame( [ [ 'BLN' => self::$kerrytj_cold_first ] ], $this->kerrytj_request_json( 'ColdBLNListTracing' ) );
	}

	/**
	 * Edge：查無貨況 → pending；最新是說明性 / 表 2 代碼 → 退回最新的認得事件
	 *
	 * @group edge
	 */
	public function test_查無貨況與說明性代碼(): void {
		$order = $this->make_shipped_order();
		$this->fake_kerrytj( 'BLNListTracing', 'tracing_empty', 'tracing_ambient_info_only_latest' );

		$empty = $this->provider()->query_shipment( $order );
		$this->assertSame( ShippingState::PENDING->value, $empty['unified_state'] );
		$this->assertSame( [], $empty['history'] );

		$info = $this->provider()->query_shipment( \wc_get_order( $order->get_id() ) );
		$this->assertSame( '01', $info['status'] );
		$this->assertSame( ShippingState::ACCEPTED->value, $info['unified_state'] );
	}

	/**
	 * Error：尚未建單查詢 → CONFLICT；貨況 401 → AUTH
	 *
	 * @group error
	 */
	public function test_查詢失敗(): void {
		$this->block_kerrytj_http();
		$this->assertSame( 'CONFLICT', $this->catch_error( fn() => $this->provider()->query_shipment( $this->make_kerrytj_order() ) )->error_code->value );

		$order = $this->make_shipped_order();
		$this->fake_kerrytj(
			'BLNListTracing',
			[
				'status' => 401,
				'body'   => '',
			]
		);
		$this->assertSame( 'AUTH', $this->catch_error( fn() => $this->provider()->query_shipment( $order ) )->error_code->value );
	}

	// endregion

	// region return / cancel / unsupported

	/**
	 * Happy：退貨取件 → PickupV2（新常溫單號、顧客為取件人）→ return_ref
	 *
	 * @group happy
	 */
	public function test_退貨取件(): void {
		$order = $this->make_shipped_order();
		$this->fake_kerrytj( 'PickupV2', 'pickup_ok' );

		$result = $this->provider()->create_return( $order );

		$this->assertSame( '40350718601', $result['return_logistics_id'] );
		$sent = $this->kerrytj_request_json( 'PickupV2' )[0];
		$this->assertSame( '40350718601', $sent['BLN'] );
		$this->assertSame( '王小明', $sent['Shipper'] );
		$this->assertSame( '某某商行', $sent['Consignee'] );
		$this->assertStringEndsWith( '-18601', $sent['PickupNo'] );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( '40350718601', ( new KerrytjLogisticsMetaKeys( $fresh ) )->get_return_ref() );
		$this->assertStringContainsString( '取件', $this->notes( $fresh ) );
	}

	/**
	 * Edge：退貨已建 → 冪等；尚未建單 → CONFLICT
	 *
	 * @group edge
	 */
	public function test_退貨冪等與前置(): void {
		$order = $this->make_shipped_order();
		$this->fake_kerrytj( 'PickupV2', 'pickup_ok' );
		$this->provider()->create_return( $order );
		$this->kerrytj_requests = [];

		$again = $this->provider()->create_return( \wc_get_order( $order->get_id() ) );

		$this->assertTrue( $again['idempotent'] );
		$this->assertSame( [], $this->kerrytj_requests );
		$this->assertSame( 'CONFLICT', $this->catch_error( fn() => $this->provider()->create_return( $this->make_kerrytj_order() ) )->error_code->value );
	}

	/**
	 * Edge：退貨失敗 → 重試沿用同一取件單號；沿用號回重複 → 視為成功
	 *
	 * @group edge
	 */
	public function test_退貨失敗重試沿用同號(): void {
		$order = $this->make_shipped_order();
		$this->fake_kerrytj( 'PickupV2', new \WP_Error( 'http_request_failed', 'timeout' ), 'pickup_duplicate' );
		$this->catch_error( fn() => $this->provider()->create_return( $order ) );

		$result = $this->provider()->create_return( \wc_get_order( $order->get_id() ) );

		$this->assertSame( '40350718601', $result['return_logistics_id'] );
		$this->assertSame( '40350718601', $this->kerrytj_request_json( 'PickupV2', 1 )[0]['BLN'] );
	}

	/**
	 * Error：取消 / 選店 → UNSUPPORTED；貨態推送 → 200 no-op
	 *
	 * @group error
	 */
	public function test_不支援的操作(): void {
		$order = $this->make_shipped_order();
		$this->block_kerrytj_http();

		foreach ( [
			fn() => $this->provider()->cancel_shipment( $order ),
			fn() => $this->provider()->get_store_selection( $order ),
			fn() => $this->provider()->parse_store_selection( [] ),
		] as $callback ) {
			$e = $this->catch_error( $callback );
			$this->assertInstanceOf( KerrytjLogisticsException::class, $e );
			$this->assertSame( 'UNSUPPORTED', $e->error_code->value );
		}

		$response = $this->provider()->handle_status_callback( new \WP_REST_Request( 'POST', '/x' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [], $this->kerrytj_requests );
	}

	// endregion
}
