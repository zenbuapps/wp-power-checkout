<?php
/**
 * 黑貓宅急便直串 provider 測試（P4，ASM-6：只做宅配）
 *
 * - create_shipment = PrintOBT（寫 _pc_tcat_logistics_ref + FileNo）；已有 ref → 冪等、不打 API
 * - print_document = DownloadOBT（回 PDF bytes）；FileNo 24 小時期限
 * - query_shipment = OBTStatus（同單號 2 小時快取，R8）→ unified_state（ShippingState）
 * - create_return = ReserveAdd；cancel_shipment / 選店 → UNSUPPORTED
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter TcatLogisticsProvider --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\TcatLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Exceptions\TcatLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatLogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatStatusMapper;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\Logistics\Doubles\TcatLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 黑貓宅急便 provider 測試
 *
 * @group integration
 * @group logistics
 * @group tcat_logistics
 */
final class TcatLogisticsProviderTest extends TestCase {
	use TcatLogisticsTestTrait;

	/** 每個測試前：啟用黑貓宅急便 */
	protected function configure_dependencies(): void {
		$this->setup_tcat_logistics();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_tcat_logistics();
		parent::tear_down();
	}

	/** @return TcatLogisticsProvider */
	private function provider(): TcatLogisticsProvider {
		return TcatLogisticsProvider::instance();
	}

	/**
	 * 狀態查詢回應（替換貨態碼）
	 *
	 * @param string $status      StatusId
	 * @param string $status_name StatusName
	 * @return array{body: string}
	 */
	private function status_response( string $status, string $status_name ): array {
		return [ 'body' => $this->tcat_fixture( 'obt_status_ok', [ '__STATUS__' => $status, '__STATUS_NAME__' => $status_name, '__ORDER_ID__' => 'A100' ] ) ];
	}

	/**
	 * 建立已有託運單的訂單
	 *
	 * @param int $file_no_created_at FileNo 建立時間戳
	 * @return \WC_Order
	 */
	private function make_shipped_order( int $file_no_created_at = 0 ): \WC_Order {
		$order = $this->make_tcat_order();
		( new TcatLogisticsMetaKeys( $order ) )->save_shipment( self::$tcat_obt, self::$tcat_file_no, $file_no_created_at ?: \time() );
		return \wc_get_order( $order->get_id() );
	}

	// ========== create_shipment ==========

	/**
	 * Happy：建單 → PrintOBT → 寫 _pc_tcat_logistics_ref / FileNo，回 logistics_id
	 *
	 * @group happy
	 */
	public function test_建單成功寫入託運單號與FileNo(): void {
		$order = $this->make_tcat_order();
		$this->fake_tcat( 'ParsingAddress', 'parsing_address_ok' );
		$this->fake_tcat( 'PrintOBT', 'print_obt_ok' );

		$result = $this->provider()->create_shipment( $order );

		$this->assertSame( self::$tcat_obt, $result['logistics_id'] );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( self::$tcat_obt, $fresh->get_meta( '_pc_tcat_logistics_ref' ) );
		$this->assertSame( self::$tcat_file_no, $fresh->get_meta( '_pc_tcat_logistics_file_no' ) );
		$this->assertGreaterThan( 0, (int) $fresh->get_meta( '_pc_tcat_logistics_file_no_created_at' ) );
		$this->assertSame( TcatLogisticsProvider::ID, $fresh->get_meta( '_pc_tcat_logistics_provider_id' ) );
		$this->assert_order_note_contains( $fresh, self::$tcat_obt );
	}

	/**
	 * Happy：建單內容（宅配 01、寄件郵號取 ParsingAddress 末六碼、收件手機、到宅、非代收）
	 *
	 * @group happy
	 */
	public function test_建單請求內容符合規格(): void {
		$order = $this->make_tcat_order();
		$this->fake_tcat( 'ParsingAddress', 'parsing_address_ok' );
		$this->fake_tcat( 'PrintOBT', 'print_obt_ok' );

		$this->provider()->create_shipment( $order );

		$sent = $this->tcat_requests( 'PrintOBT' )[0]['decoded'];
		$this->assertSame( '01', $sent['PrintType'] );
		$this->assertCount( 1, $sent['Orders'] );
		$o = $sent['Orders'][0];
		$this->assertSame( '', $o['OBTNumber'] );
		$this->assertSame( (string) $order->get_order_number(), $o['OrderId'] );
		$this->assertSame( '0001', $o['Thermosphere'] );
		$this->assertSame( '0001', $o['Spec'] );
		$this->assertSame( '01', $o['ReceiptLocation'] );
		$this->assertSame( '', $o['ReceiptStationNo'] );
		$this->assertSame( '王小明', $o['RecipientName'] );
		$this->assertSame( '0912345678', $o['RecipientMobile'] );
		$this->assertSame( '', $o['RecipientTel'] );
		$this->assertStringContainsString( '南京東路三段261號2樓', $o['RecipientAddress'] );
		$this->assertSame( '80224B', $o['SenderZipCode'], 'PostNumber 71-802-24-B 去連字號取末六碼' );
		$this->assertSame( 'N', $o['IsCollection'] );
		$this->assertSame( 0, $o['CollectionAmount'] );
		$this->assertSame( 'N', $o['IsDeclare'] );
		$this->assertSame( 0, $o['DeclareAmount'] );
		$this->assertSame( '澎湖XO干貝醬', $o['ProductName'] );
		$this->assertMatchesRegularExpression( '/^\d{8}$/', $o['ShipmentDate'] );
		$this->assertGreaterThan( $o['ShipmentDate'], $o['DeliveryDate'], '配達日不可與出貨日同一天（E090）' );
	}

	/**
	 * Happy：貨到付款（cod）→ 代收；設定寄件郵號時不打 ParsingAddress
	 *
	 * @group happy
	 */
	public function test_貨到付款代收且設定郵號不查郵號(): void {
		$this->setup_tcat_logistics( [ 'sender_zip_code' => '12-017-73-C' ] );
		$order = $this->make_tcat_order(
			[
				'payment_method' => 'cod',
				'total'          => 1500,
			]
		);
		$this->fake_tcat( 'PrintOBT', 'print_obt_ok' );

		$this->provider()->create_shipment( $order );

		$this->assertCount( 0, $this->tcat_requests( 'ParsingAddress' ) );
		$o = $this->tcat_requests( 'PrintOBT' )[0]['decoded']['Orders'][0];
		$this->assertSame( '01773C', $o['SenderZipCode'] );
		$this->assertSame( 'Y', $o['IsCollection'] );
		$this->assertSame( 1500, $o['CollectionAmount'] );
		$this->assertSame( 'N', $o['IsFreight'] );
	}

	/**
	 * Edge：已有 ref → 冪等回既有單號，不打 API
	 *
	 * @group edge
	 */
	public function test_已有託運單號冪等不打API(): void {
		$order = $this->make_shipped_order();
		$this->block_tcat_http();

		$result = $this->provider()->create_shipment( $order );

		$this->assertSame( self::$tcat_obt, $result['logistics_id'] );
		$this->assertTrue( $result['idempotent'] );
		$this->assertSame( [], $this->tcat_requests );
	}

	/**
	 * Error：收件人電話 / 手機皆缺 → VALIDATION，不打 PrintOBT
	 *
	 * @group error
	 */
	public function test_收件電話缺為VALIDATION(): void {
		$order = $this->make_tcat_order( [ 'phone' => '' ] );
		$this->fake_tcat( 'ParsingAddress', 'parsing_address_ok' );
		$this->fake_tcat( 'PrintOBT', 'print_obt_ok' );

		try {
			$this->provider()->create_shipment( $order );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
			$this->assertStringContainsString( '收件人', $e->getMessage() );
		}
		$this->assertCount( 0, $this->tcat_requests( 'PrintOBT' ) );
		$this->assertSame( '', \wc_get_order( $order->get_id() )->get_meta( '_pc_tcat_logistics_ref' ) );
	}

	/**
	 * Error：代收金額超過 10 萬 → VALIDATION
	 *
	 * @group error
	 */
	public function test_代收超過上限為VALIDATION(): void {
		$this->setup_tcat_logistics( [ 'sender_zip_code' => '80224B' ] );
		$order = $this->make_tcat_order(
			[
				'payment_method' => 'cod',
				'total'          => 100001,
			]
		);
		$this->block_tcat_http();

		try {
			$this->provider()->create_shipment( $order );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
		}
		$this->assertCount( 0, $this->tcat_requests( 'PrintOBT' ) );
	}

	/**
	 * Error：寄件地址不可配送（PostNumber=X）→ VALIDATION
	 *
	 * @group error
	 */
	public function test_寄件地址不可配送為VALIDATION(): void {
		$order = $this->make_tcat_order();
		$this->fake_tcat( 'ParsingAddress', 'parsing_address_x' );

		$this->expectException( TcatLogisticsException::class );
		$this->provider()->create_shipment( $order );
	}

	/**
	 * Error：API 回 E009 → 例外上拋 + order note 記錄失敗
	 *
	 * @group error
	 */
	public function test_建單API失敗寫note並上拋(): void {
		$this->setup_tcat_logistics( [ 'sender_zip_code' => '80224B' ] );
		$order = $this->make_tcat_order();
		$this->fake_tcat( 'PrintOBT', 'error_e009' );

		try {
			$this->provider()->create_shipment( $order );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::AUTH, $e->error_code );
		}
		$this->assert_order_note_contains( \wc_get_order( $order->get_id() ), 'E009' );
	}

	/**
	 * Error：未設定憑證 → VALIDATION，不打 API
	 *
	 * @group error
	 */
	public function test_未設定憑證不打API(): void {
		$this->setup_tcat_logistics( [ 'customer_token' => '' ] );
		$order = $this->make_tcat_order();
		$this->block_tcat_http();

		try {
			$this->provider()->create_shipment( $order );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
		}
		$this->assertSame( [], $this->tcat_requests );
	}

	// ========== print_document ==========

	/**
	 * Happy：print_document → DownloadOBT 回 PDF bytes
	 *
	 * @group happy
	 */
	public function test_列印回PDF(): void {
		$order = $this->make_shipped_order();
		$this->fake_tcat(
			'DownloadOBT',
			[
				'body'         => $this->tcat_pdf(),
				'content_type' => 'application/pdf',
			]
		);

		$pdf = $this->provider()->print_document( $order );

		$this->assertStringStartsWith( '%PDF', $pdf );
		$sent = $this->tcat_requests( 'DownloadOBT' )[0]['decoded'];
		$this->assertSame( self::$tcat_file_no, $sent['FileNo'] );
		$this->assertSame( [ [ 'OBTNumber' => self::$tcat_obt ] ], $sent['Orders'] );
	}

	/**
	 * Edge：FileNo 超過 24 小時 → CONFLICT，不打 API
	 *
	 * @group edge
	 */
	public function test_FileNo逾24小時不下載(): void {
		$order = $this->make_shipped_order( \time() - DAY_IN_SECONDS - 60 );
		$this->block_tcat_http();

		try {
			$this->provider()->print_document( $order );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::CONFLICT, $e->error_code );
			$this->assertStringContainsString( '24', $e->getMessage() );
		}
		$this->assertSame( [], $this->tcat_requests );
	}

	/**
	 * Error：尚未建單即列印 → 例外
	 *
	 * @group error
	 */
	public function test_尚未建單不可列印(): void {
		$order = $this->make_tcat_order();
		$this->block_tcat_http();

		$this->expectException( TcatLogisticsException::class );
		$this->expectExceptionMessage( '尚未成立物流單' );
		$this->provider()->print_document( $order );
	}

	// ========== query_shipment ==========

	/**
	 * Happy：查詢 → 寫 status / unified_state，回 cached=false
	 *
	 * @group happy
	 */
	public function test_查詢貨態寫入狀態(): void {
		$order = $this->make_shipped_order();
		$this->fake_tcat( 'OBTStatus', $this->status_response( '301', '配完' ) );

		$result = $this->provider()->query_shipment( $order );

		$this->assertSame( self::$tcat_obt, $result['logistics_id'] );
		$this->assertSame( '301', $result['status'] );
		$this->assertSame( '配完', $result['status_name'] );
		$this->assertSame( ShippingState::DELIVERED->value, $result['unified_state'] );
		$this->assertFalse( $result['cached'] );
		$this->assertCount( 2, $result['history'] );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( '301', $fresh->get_meta( '_pc_tcat_logistics_status' ) );
		$this->assertSame( 'delivered', $fresh->get_meta( '_pc_tcat_logistics_unified_state' ) );
	}

	/**
	 * Edge：2 小時內第二次查詢 → 回快取 cached=true，不觸發 pre_http_request
	 *
	 * @group edge
	 */
	public function test_兩小時內第二次查詢回快取不打API(): void {
		$order = $this->make_shipped_order();
		$this->fake_tcat( 'OBTStatus', $this->status_response( '151', '配送中' ) );
		$this->provider()->query_shipment( $order );
		$this->assertCount( 1, $this->tcat_requests( 'OBTStatus' ) );

		$second = $this->provider()->query_shipment( \wc_get_order( $order->get_id() ) );

		$this->assertTrue( $second['cached'] );
		$this->assertSame( '151', $second['status'] );
		$this->assertCount( 1, $this->tcat_requests( 'OBTStatus' ), '快取命中不可再打 OBTStatus（同單號 2 小時限流 E087）' );
		$timeout = (int) \get_option( '_transient_timeout_pc_tcat_logistics_status_' . self::$tcat_obt );
		$this->assertEqualsWithDelta( \time() + 2 * HOUR_IN_SECONDS, $timeout, 60 );
	}

	/**
	 * Edge：查無貨態（E077，剛配號未集貨）不是錯誤 → pending
	 *
	 * @group edge
	 */
	public function test_查無貨態視為pending(): void {
		$order = $this->make_shipped_order();
		$this->fake_tcat( 'OBTStatus', 'obt_status_e077' );

		$result = $this->provider()->query_shipment( $order );

		$this->assertSame( ShippingState::PENDING->value, $result['unified_state'] );
		$this->assertSame( '', $result['status'] );
	}

	/**
	 * Edge：平台限流（E087）→ 回上次結果 cached=true、throttled=true，不拋錯
	 *
	 * @group edge
	 */
	public function test_E087限流回上次結果(): void {
		$order = $this->make_shipped_order();
		$order->update_meta_data( '_pc_tcat_logistics_status', '151' );
		$order->update_meta_data( '_pc_tcat_logistics_status_name', '配送中' );
		$order->update_meta_data( '_pc_tcat_logistics_unified_state', 'in_transit' );
		$order->save();
		$this->fake_tcat( 'OBTStatus', 'obt_status_e087' );

		$result = $this->provider()->query_shipment( \wc_get_order( $order->get_id() ) );

		$this->assertTrue( $result['cached'] );
		$this->assertTrue( $result['throttled'] );
		$this->assertSame( '151', $result['status'] );
		$this->assertSame( 'in_transit', $result['unified_state'] );
	}

	/**
	 * Happy：貨態碼映射到 ShippingState（skill cargo-status.md 二代代碼；對齊 zenbu TCAT_STATUS_ID_STATE）
	 *
	 * @dataProvider status_cases
	 * @group happy
	 *
	 * @param string            $code     StatusId
	 * @param ShippingState|null $expected 預期
	 */
	public function test_貨態碼映射ShippingState( string $code, ?ShippingState $expected ): void {
		$this->assertSame( $expected, TcatStatusMapper::map( $code ) );
	}

	/**
	 * 貨態碼案例（每組代表碼）
	 *
	 * @return array<string, array{0: string, 1: ShippingState|null}>
	 */
	public function status_cases(): array {
		return [
			'111 轉運中（已集貨）'     => [ '111', ShippingState::ACCEPTED ],
			'151 配送中'           => [ '151', ShippingState::IN_TRANSIT ],
			'159 暫置營業所'         => [ '159', ShippingState::IN_TRANSIT ],
			'211 不在家（仍會再配）'     => [ '211', ShippingState::IN_TRANSIT ],
			'181 文件已移除仍接受'      => [ '181', ShippingState::IN_TRANSIT ],
			'208 轉交超商配達'        => [ '208', ShippingState::ARRIVED ],
			'308 超商通知取回'        => [ '308', ShippingState::ARRIVED ],
			'209 超商取回'          => [ '209', ShippingState::RETURNED ],
			'420 轉交超商配達刪除'      => [ '420', ShippingState::RETURNED ],
			'301 配完'            => [ '301', ShippingState::DELIVERED ],
			'161 一般退貨'          => [ '161', ShippingState::RETURNED ],
			'305 退貨配完'          => [ '305', ShippingState::RETURNED ],
			'183 地址錯誤'          => [ '183', ShippingState::FAILED ],
			'244 遺失（文件已移除）'     => [ '244', ShippingState::FAILED ],
			'帶空白也能比對'          => [ ' 301 ', ShippingState::DELIVERED ],
			'未知碼不拋錯回 null'      => [ '999', null ],
			'一代碼不混用（00003）'    => [ '00003', null ],
		];
	}

	/**
	 * Edge：未知貨態碼 → unified_state null，保留原始碼，不拋錯
	 *
	 * @group edge
	 */
	public function test_未知貨態碼保留原值(): void {
		$order = $this->make_shipped_order();
		$this->fake_tcat( 'OBTStatus', $this->status_response( '999', '新代碼' ) );

		$result = $this->provider()->query_shipment( $order );

		$this->assertSame( '999', $result['status'] );
		$this->assertNull( $result['unified_state'] );
	}

	// ========== create_return ==========

	/**
	 * Happy：create_return → ReserveAdd（收件人 = 商店、寄件人 = 顧客），寫 return_ref
	 *
	 * @group happy
	 */
	public function test_建立逆物流成功(): void {
		$order = $this->make_shipped_order();
		$this->fake_tcat( 'ReserveAdd', 'reserve_add_ok' );

		$result = $this->provider()->create_return( $order );

		$this->assertSame( '930005209999', $result['return_logistics_id'] );
		$this->assertSame( '930005209999', \wc_get_order( $order->get_id() )->get_meta( '_pc_tcat_logistics_return_ref' ) );
		$sent = $this->tcat_requests( 'ReserveAdd' )[0]['decoded'];
		$this->assertSame( '01', $sent['PrintType'] );
		$this->assertSame( '黑貓測試商店', $sent['Order']['RecipientName'] );
		$this->assertSame( '王小明', $sent['Order']['SenderName'] );
		$this->assertSame( '0912345678', $sent['Order']['SenderMobile'] );
		$this->assertMatchesRegularExpression( '/^\d{8}$/', $sent['Order']['TakeDate'] );
		$this->assertSame( 'N', $sent['Order']['IsFreight'] );
	}

	/**
	 * Error：指定取件日不在 D+1~D+7 → VALIDATION，不打 API
	 *
	 * @group error
	 */
	public function test_逆物流取件日不合法為VALIDATION(): void {
		$order = $this->make_shipped_order();
		$this->block_tcat_http();
		$today = ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Taipei' ) ) )->format( 'Ymd' );

		try {
			$this->provider()->create_return( $order, [ 'take_date' => $today ] );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
		}
		$this->assertSame( [], $this->tcat_requests );
	}

	/**
	 * Error：尚未建正向託運單 → 不可建逆物流
	 *
	 * @group error
	 */
	public function test_尚未建單不可建逆物流(): void {
		$order = $this->make_tcat_order();
		$this->block_tcat_http();

		$this->expectException( TcatLogisticsException::class );
		$this->provider()->create_return( $order );
	}

	// ========== 不支援的操作 ==========

	/**
	 * Error：選店 / 解析選店 / 取消 → UNSUPPORTED
	 *
	 * @group error
	 */
	public function test_選店與取消為UNSUPPORTED(): void {
		$order = $this->make_shipped_order();
		$calls = [
			'get_store_selection'   => fn() => $this->provider()->get_store_selection( $order ),
			'parse_store_selection' => fn() => $this->provider()->parse_store_selection( [] ),
			'cancel_shipment'       => fn() => $this->provider()->cancel_shipment( $order ),
		];

		foreach ( $calls as $name => $call ) {
			try {
				$call();
				$this->fail( "{$name} 應拋 UNSUPPORTED" );
			} catch ( TcatLogisticsException $e ) {
				$this->assertSame( ErrorCode::UNSUPPORTED, $e->error_code, $name );
			}
		}
	}

	/**
	 * Happy：無貨態推送 → handle_status_callback 回 200 no-op
	 *
	 * @group happy
	 */
	public function test_貨態callback回200不處理(): void {
		$response = $this->provider()->handle_status_callback( new \WP_REST_Request( 'POST', '/x' ) );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * Happy：get_supported_methods 只有宅配
	 *
	 * @group happy
	 */
	public function test_只支援宅配(): void {
		$this->assertSame( [ 'HOME' ], $this->provider()->get_supported_methods() );
	}
}
