<?php
/**
 * 新竹物流 provider 測試（P5）
 *
 *  - create_shipment = TransData_Json：有號段 → 原子配號帶 edelno；無號段 → 新竹系統配號。寫 ref / 到著站 / 標籤
 *  - print_document  = 建單時存下的標籤圖（不再打 API）→ HTML（data URL）
 *  - query_shipment  = searchGoods（DES-CBC）→ status_code → ShippingState；未設定查貨參數 → degrade pending（不打 API）
 *  - cancel_shipment = TransDataCancel_Json；create_return = R_TransData_Json（大寫 key）
 *  - 選店 UNSUPPORTED；無貨態推送 → 200 no-op
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter HctLogisticsProvider --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Hct\Services\HctLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Exceptions\HctLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctLogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctStatusMapper;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers\HctTrackCrypto;
use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\Shared\Exceptions\WaybillNumberException;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\WaybillNumberAllocator;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\Logistics\Doubles\HctLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 新竹物流 provider
 *
 * @group integration
 * @group logistics
 * @group hct_logistics
 */
final class HctLogisticsProviderTest extends TestCase {
	use HctLogisticsTestTrait;

	/** 每個測試前：啟用新竹物流 */
	protected function configure_dependencies(): void {
		$this->setup_hct_logistics();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_hct_logistics();
		parent::tear_down();
	}

	/** @return HctLogisticsProvider */
	private function provider(): HctLogisticsProvider {
		return HctLogisticsProvider::instance();
	}

	/**
	 * 建立已建單訂單
	 *
	 * @param array<string, mixed> $args 訂單覆寫
	 * @return \WC_Order
	 */
	private function make_shipped_order( array $args = [] ): \WC_Order {
		$order = $this->make_hct_order( $args );
		( new HctLogisticsMetaKeys( $order ) )->save_shipment( self::$hct_edelno, (string) $order->get_order_number(), '20261121', '4004', \base64_encode( (string) \hex2bin( $this->hct_label_hex() ) ) );
		return \wc_get_order( $order->get_id() );
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

	// region create_shipment

	/**
	 * Happy：建單（無號段 → 新竹配號）→ ref = edelno、到著站、標籤存檔、送出欄位正確
	 *
	 * @group happy
	 */
	public function test_建單成功寫入貨號與標籤(): void {
		$order = $this->make_hct_order( [ 'weight' => '2.3' ] );
		$this->fake_hct( 'TransData_Json', 'trans_data_ok' );

		$result = $this->provider()->create_shipment( $order );

		$this->assertSame( self::$hct_edelno, $result['logistics_id'] );
		$this->assertSame( '4004', $result['arrival_station'] );
		$this->assertTrue( $result['has_label'] );

		$sent = $this->hct_request_json( 'TransData_Json' )[0];
		$this->assertSame( (string) $order->get_order_number(), $sent['epino'] );
		$this->assertSame( '王小明', $sent['ercsig'] );
		$this->assertSame( '0912345678', $sent['ertel1'] );
		$this->assertSame( '台中市大雅區中清路三段513號', $sent['eraddr'] );
		$this->assertSame( '1', $sent['ejamt'] );
		$this->assertSame( '3', $sent['eqamt'] );
		$this->assertSame( '11', $sent['eprdct'] );
		$this->assertSame( '001', $sent['eprdcl2'] );
		$this->assertMatchesRegularExpression( '/^\d{8}$/', $sent['esdate'] );
		$this->assertArrayNotHasKey( 'edelno', $sent );
		$this->assertArrayNotHasKey( 'eqmny', $sent );

		$fresh = \wc_get_order( $order->get_id() );
		$meta  = new HctLogisticsMetaKeys( $fresh );
		$this->assertSame( self::$hct_edelno, $meta->get_ref() );
		$this->assertSame( (string) \hex2bin( $this->hct_label_hex() ), \base64_decode( $meta->get_label() ) );
		$this->assertStringContainsString( self::$hct_edelno, $this->notes( $fresh ) );
	}

	/**
	 * Happy：有號段 → 原子配號並帶 edelno（9 碼本體 + MOD 7）
	 *
	 * @group happy
	 */
	public function test_有號段時配號帶入edelno(): void {
		$this->setup_hct_logistics(
			[
				'waybill_start' => '100173483',
				'waybill_end'   => '100173490',
			]
		);
		$order = $this->make_hct_order();
		$this->fake_hct( 'TransData_Json', 'trans_data_ok' );

		$result = $this->provider()->create_shipment( $order );

		$this->assertSame( '1001734834', $this->hct_request_json( 'TransData_Json' )[0]['edelno'] );
		$this->assertSame( '1001734834', $result['logistics_id'] );
	}

	/**
	 * Happy：貨到付款 → 傳票類別 41 + 代收金額
	 *
	 * @group happy
	 */
	public function test_貨到付款帶代收(): void {
		$order = $this->make_hct_order(
			[
				'payment_method' => 'cod',
				'total'          => 1280,
			]
		);
		$this->fake_hct( 'TransData_Json', 'trans_data_ok' );

		$this->provider()->create_shipment( $order );

		$sent = $this->hct_request_json( 'TransData_Json' )[0];
		$this->assertSame( '41', $sent['eprdct'] );
		$this->assertSame( '1280', $sent['eqmny'] );
	}

	/**
	 * Happy：結帳選的商品種類（冷藏 008）與寄件人設定帶入
	 *
	 * @group happy
	 */
	public function test_商品種類與寄件人帶入(): void {
		$this->setup_hct_logistics(
			[
				'sender_name'    => '新竹測試商店',
				'sender_tel'     => '0223456789',
				'sender_address' => '新竹縣新豐鄉建興路100號',
				'customer_code'  => '12345678900',
			]
		);
		$order = $this->make_hct_order();
		( new HctLogisticsMetaKeys( $order ) )->save_checkout_choice( '008' );
		$this->fake_hct( 'TransData_Json', 'trans_data_ok' );

		$this->provider()->create_shipment( \wc_get_order( $order->get_id() ) );

		$sent = $this->hct_request_json( 'TransData_Json' )[0];
		$this->assertSame( '008', $sent['eprdcl2'] );
		$this->assertSame( '新竹測試商店', $sent['etcsig'] );
		$this->assertSame( '0223456789', $sent['ettel1'] );
		$this->assertSame( '新竹縣新豐鄉建興路100號', $sent['etaddr'] );
		$this->assertSame( '12345678900', $sent['escsno'] );
	}

	/**
	 * Edge：已建單 → 冪等回既有貨號，不打 API、不配號
	 *
	 * @group edge
	 */
	public function test_已建單冪等(): void {
		$order = $this->make_shipped_order();
		$this->block_hct_http();

		$result = $this->provider()->create_shipment( $order );

		$this->assertSame( self::$hct_edelno, $result['logistics_id'] );
		$this->assertTrue( $result['idempotent'] );
		$this->assertSame( [], $this->hct_requests );
	}

	/**
	 * Error：收件電話缺 → VALIDATION，不打 API、不配號
	 *
	 * @group error
	 */
	public function test_收件電話缺不打API(): void {
		$this->setup_hct_logistics(
			[
				'waybill_start' => '100',
				'waybill_end'   => '200',
			]
		);
		$order = $this->make_hct_order( [ 'phone' => '' ] );
		$this->block_hct_http();

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $order ) );

		$this->assertInstanceOf( HctLogisticsException::class, $e );
		$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
		$this->assertSame( [], $this->hct_requests );
		global $wpdb;
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", WaybillNumberAllocator::CURRENT_OPTION_PREFIX . HctLogisticsProvider::ID ) );
		$this->assertContains( $current, [ null, '99' ], '驗證失敗不可消耗號碼' );
	}

	/**
	 * Error：未填帳密 → VALIDATION
	 *
	 * @group error
	 */
	public function test_未填帳密(): void {
		$this->setup_hct_logistics( [ 'company' => '' ] );
		$order = $this->make_hct_order();
		$this->block_hct_http();

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $order ) );

		$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
		$this->assertSame( [], $this->hct_requests );
	}

	/**
	 * Error：新竹回 success=N（帳密錯）→ AUTH + order note
	 *
	 * @group error
	 */
	public function test_建單帳密錯誤(): void {
		$order = $this->make_hct_order();
		$this->fake_hct( 'TransData_Json', 'trans_data_auth' );

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $order ) );

		$this->assertSame( ErrorCode::AUTH, $e->error_code );
		$this->assertStringContainsString( '公司名稱或密碼錯誤', $this->notes( $order ) );
		$this->assertSame( '', ( new HctLogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_ref() );
	}

	/**
	 * Error：新竹回業務錯誤（地址錯誤）→ VALIDATION 帶原始 ErrMsg
	 *
	 * @group error
	 */
	public function test_建單業務錯誤(): void {
		$order = $this->make_hct_order();
		$this->fake_hct( 'TransData_Json', 'trans_data_invalid' );

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $order ) );

		$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
		$this->assertStringContainsString( '收貨人地址錯誤', $e->getMessage() );
	}

	/**
	 * Error：號段用罄 → NUMBER_EXHAUSTED，不打 API
	 *
	 * @group error
	 */
	public function test_號段用罄(): void {
		$this->setup_hct_logistics(
			[
				'waybill_start' => '100',
				'waybill_end'   => '100',
			]
		);
		$this->fake_hct( 'TransData_Json', 'trans_data_ok' );
		$this->provider()->create_shipment( $this->make_hct_order() );
		$this->hct_requests = [];

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $this->make_hct_order() ) );

		$this->assertInstanceOf( WaybillNumberException::class, $e );
		$this->assertSame( ErrorCode::NUMBER_EXHAUSTED, $e->error_code );
		$this->assertSame( [], $this->hct_requests );
	}

	/**
	 * Edge：API 逾時 → NETWORK，號碼不回收（下次建單配下一號，避免重號）
	 *
	 * @group edge
	 */
	public function test_API失敗號碼不回收(): void {
		$this->setup_hct_logistics(
			[
				'waybill_start' => '100173483',
				'waybill_end'   => '100173490',
			]
		);
		$this->fake_hct( 'TransData_Json', new \WP_Error( 'http_request_failed', 'timeout' ) );

		$e = $this->catch_error( fn() => $this->provider()->create_shipment( $this->make_hct_order() ) );
		$this->assertSame( ErrorCode::NETWORK, $e->error_code );

		$this->fake_hct( 'TransData_Json', 'trans_data_ok' );
		$this->provider()->create_shipment( $this->make_hct_order() );

		$this->assertSame( '1001734845', $this->hct_request_json( 'TransData_Json', 1 )[0]['edelno'] );
	}

	// endregion

	// region print

	/**
	 * Happy：列印 → HTML 內嵌建單時存下的標籤圖，不打 API
	 *
	 * @group happy
	 */
	public function test_列印回HTML標籤(): void {
		$order = $this->make_shipped_order();
		$this->block_hct_http();

		$html = $this->provider()->print_document( $order );

		$this->assertStringContainsString( '<img', $html );
		$this->assertStringContainsString( 'data:image/png;base64,', $html );
		$this->assertStringContainsString( self::$hct_edelno, $html );
		$this->assertSame( [], $this->hct_requests );
	}

	/**
	 * Error：未建單 → CONFLICT；建單時沒拿到標籤 → CONFLICT
	 *
	 * @group error
	 */
	public function test_列印前置錯誤(): void {
		$order = $this->make_hct_order();
		$e     = $this->catch_error( fn() => $this->provider()->print_document( $order ) );
		$this->assertSame( ErrorCode::CONFLICT, $e->error_code );

		( new HctLogisticsMetaKeys( $order ) )->save_shipment( self::$hct_edelno, 'X', '20261121', '4004', '' );
		$e = $this->catch_error( fn() => $this->provider()->print_document( \wc_get_order( $order->get_id() ) ) );
		$this->assertSame( ErrorCode::CONFLICT, $e->error_code );
	}

	// endregion

	// region query

	/**
	 * Happy：查貨 → 取最新一筆、status_code 映射、寫 meta、回 history
	 *
	 * @group happy
	 */
	public function test_查詢貨況(): void {
		if ( ! HctTrackCrypto::is_available() ) {
			$this->markTestSkipped( 'des-ede3-cbc 不可用' );
		}
		$this->setup_hct_track();
		$order = $this->make_shipped_order();
		$xml   = $this->hct_track_xml(
			[
				[
					'wrktime'     => '2025/03/11 05:03',
					'status'      => '貨件已抵達北投營業所，分貨中。',
					'status_code' => 'A04',
				],
				[
					'wrktime'     => '2025/03/11 12:21',
					'status'      => '貨件已由北投營業所送達。貨物件數共1件。',
					'status_code' => 'H01',
					'category'    => '配達',
				],
			]
		);
		$this->fake_hct(
			'searchGoods',
			[
				'status' => 200,
				'body'   => $this->hct_track_encrypt( $xml ),
			]
		);

		$result = $this->provider()->query_shipment( $order );

		$this->assertSame( 'H01', $result['status'] );
		$this->assertSame( ShippingState::DELIVERED->value, $result['unified_state'] );
		$this->assertSame( '2025/03/11 12:21', $result['status_time'] );
		$this->assertCount( 2, $result['history'] );
		$this->assertFalse( $result['cached'] );

		$request = $this->hct_requests( 'searchGoods' )[0];
		$this->assertStringStartsWith( 'https://hctapiweb.hct.com.tw/phone/searchGoods_Main_Xml.ashx?', $request['url'] );
		\parse_str( (string) \wp_parse_url( $request['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( self::$hct_track_v, $query['v'] );
		$this->assertStringContainsString( 'orderid="' . self::$hct_edelno . '"', HctTrackCrypto::decrypt( (string) $query['no'], HctTrackCrypto::key_date( 235 ), self::$hct_track_iv ) );

		$meta = ( new HctLogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_status();
		$this->assertSame( 'H01', $meta['status'] );
		$this->assertSame( 'delivered', $meta['unified_state'] );
	}

	/**
	 * Edge：查無貨況（<rlist />）→ pending
	 *
	 * @group edge
	 */
	public function test_查無貨況回pending(): void {
		if ( ! HctTrackCrypto::is_available() ) {
			$this->markTestSkipped( 'des-ede3-cbc 不可用' );
		}
		$this->setup_hct_track();
		$order = $this->make_shipped_order();
		$this->fake_hct(
			'searchGoods',
			[
				'status' => 200,
				'body'   => $this->hct_track_encrypt( $this->hct_track_xml( [] ) ),
			]
		);

		$result = $this->provider()->query_shipment( $order );

		$this->assertSame( ShippingState::PENDING->value, $result['unified_state'] );
		$this->assertSame( '', $result['status'] );
	}

	/**
	 * Edge：快取期間第二次查詢不打 API、回 cached=true
	 *
	 * @group edge
	 */
	public function test_查詢快取(): void {
		if ( ! HctTrackCrypto::is_available() ) {
			$this->markTestSkipped( 'des-ede3-cbc 不可用' );
		}
		$this->setup_hct_track();
		$order = $this->make_shipped_order();
		$this->fake_hct(
			'searchGoods',
			[
				'status' => 200,
				'body'   => $this->hct_track_encrypt( $this->hct_track_xml( [] ) ),
			]
		);

		$this->provider()->query_shipment( $order );
		$second = $this->provider()->query_shipment( $order );

		$this->assertTrue( $second['cached'] );
		$this->assertCount( 1, $this->hct_requests( 'searchGoods' ) );
	}

	/**
	 * Edge：未設定查貨參數 → degrade pending，不打 API（capability 仍宣告 true）
	 *
	 * @group edge
	 */
	public function test_未設定查貨參數degrade(): void {
		$order = $this->make_shipped_order();
		$this->block_hct_http();

		$result = $this->provider()->query_shipment( $order );

		$this->assertSame( ShippingState::PENDING->value, $result['unified_state'] );
		$this->assertTrue( $result['degraded'] );
		$this->assertSame( 'track_query_not_configured', $result['reason'] );
		$this->assertSame( [], $this->hct_requests );
	}

	/**
	 * Error：查貨網路失敗 → NETWORK（後台主動查詢，失敗要讓操作者看到）；未建單 → CONFLICT
	 *
	 * @group error
	 */
	public function test_查詢失敗(): void {
		$this->setup_hct_track();
		$order = $this->make_hct_order();
		$e     = $this->catch_error( fn() => $this->provider()->query_shipment( $order ) );
		$this->assertSame( ErrorCode::CONFLICT, $e->error_code );

		$shipped = $this->make_shipped_order();
		$this->fake_hct( 'searchGoods', new \WP_Error( 'http_request_failed', 'timeout' ) );
		$e = $this->catch_error( fn() => $this->provider()->query_shipment( $shipped ) );
		$this->assertSame( ErrorCode::NETWORK, $e->error_code );
	}

	/**
	 * Happy：貨況代碼 → ShippingState（skill cargo-tracking-decrypt.md §7 映射；未知碼 → pending）
	 *
	 * @dataProvider status_cases
	 * @group happy
	 *
	 * @param string       $code     status_code
	 * @param string       $text     status 文字
	 * @param ShippingState $expected 預期
	 */
	public function test_貨況映射( string $code, string $text, ShippingState $expected ): void {
		$this->assertSame( $expected, HctStatusMapper::map( $code, $text ) );
	}

	/** @return array<string, array{0: string, 1: string, 2: ShippingState}> */
	public function status_cases(): array {
		return [
			'A01 集貨'      => [ 'A01', '', ShippingState::ACCEPTED ],
			'A03 發送'      => [ 'A03', '', ShippingState::IN_TRANSIT ],
			'A04 到著'      => [ 'A04', '', ShippingState::ARRIVED ],
			'A05 配送'      => [ 'A05', '', ShippingState::IN_TRANSIT ],
			'H01 正常配交'    => [ 'H01', '', ShippingState::DELIVERED ],
			'C01 結案配交'    => [ 'C01', '', ShippingState::DELIVERED ],
			'H13 拒收'      => [ 'H13', '', ShippingState::FAILED ],
			'H17 拒收退回'    => [ 'H17', '', ShippingState::RETURNED ],
			'R19 派遣取消'    => [ 'R19', '', ShippingState::CANCELLED ],
			'H04 客戶不在'    => [ 'H04', '送達客戶不在，xx 保管中。', ShippingState::PENDING ],
			'小寫碼'         => [ 'h01', '', ShippingState::DELIVERED ],
			'未知碼'         => [ 'Z99', '貨件已由北投營業所送達。', ShippingState::PENDING ],
			'無碼 送達'       => [ '', '貨件已由北投營業所送達。貨物件數共1件。', ShippingState::DELIVERED ],
			'無碼 客戶不在保管中' => [ '', '送達客戶不在，北投營業所保管中。', ShippingState::IN_TRANSIT ],
			'無碼 分貨中'      => [ '', '貨件已抵達北投營業所，分貨中。', ShippingState::ARRIVED ],
			'無碼 取消退貨'     => [ '', '收件人確定保留商品，取消退貨。', ShippingState::CANCELLED ],
			'無碼 無資訊'      => [ '', '', ShippingState::PENDING ],
		];
	}

	// endregion

	// region cancel / return

	/**
	 * Happy：取消託運單 → TransDataCancel（edelno + epino）→ 狀態 cancelled
	 *
	 * @group happy
	 */
	public function test_取消託運單(): void {
		$order = $this->make_shipped_order();
		$this->fake_hct( 'TransDataCancel_Json', 'cancel_ok' );

		$result = $this->provider()->cancel_shipment( $order );

		$this->assertTrue( $result['cancelled'] );
		$this->assertSame(
			[
				[
					'edelno' => self::$hct_edelno,
					'epino'  => (string) $order->get_order_number(),
				],
			],
			$this->hct_request_json( 'TransDataCancel_Json' )
		);
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'cancelled', ( new HctLogisticsMetaKeys( $fresh ) )->get_status()['unified_state'] );
		$this->assertStringContainsString( '取消', $this->notes( $fresh ) );

		$this->block_hct_http();
		$this->hct_requests = [];
		$again              = $this->provider()->cancel_shipment( $fresh );
		$this->assertTrue( $again['idempotent'] );
		$this->assertSame( [], $this->hct_requests );
	}

	/**
	 * Error：取消失敗 → 例外帶原始訊息；未建單 → CONFLICT
	 *
	 * @group error
	 */
	public function test_取消失敗(): void {
		$order = $this->make_shipped_order();
		$this->fake_hct( 'TransDataCancel_Json', 'cancel_fail' );

		$e = $this->catch_error( fn() => $this->provider()->cancel_shipment( $order ) );
		$this->assertSame( ErrorCode::NOT_FOUND, $e->error_code );
		$this->assertStringContainsString( '查無貨號和訂單編號', $e->getMessage() );

		$e = $this->catch_error( fn() => $this->provider()->cancel_shipment( $this->make_hct_order() ) );
		$this->assertSame( ErrorCode::CONFLICT, $e->error_code );
	}

	/**
	 * Happy：退貨 → R_TransData（edelno2 = 原貨號、退貨人 = 顧客）→ return ref（2 開頭）
	 *
	 * @group happy
	 */
	public function test_建立退貨單(): void {
		$order = $this->make_shipped_order();
		$this->fake_hct( 'R_TransData_Json', 'r_trans_data_ok' );

		$result = $this->provider()->create_return( $order );

		$this->assertSame( '2250446752', $result['return_logistics_id'] );
		$sent = $this->hct_request_json( 'R_TransData_Json' )[0];
		$this->assertSame( self::$hct_edelno, $sent['edelno2'] );
		$this->assertSame( '王小明', $sent['ercsig'] );
		$this->assertSame( '0912345678', $sent['ertel1'] );
		$this->assertStringStartsWith( 'R', $sent['epino'] );
		$this->assertLessThanOrEqual( 20, \strlen( $sent['epino'] ) );
		$this->assertArrayNotHasKey( 'edelno', $sent );
		$this->assertSame( '2250446752', ( new HctLogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_return_ref() );

		$this->block_hct_http();
		$this->hct_requests = [];
		$again              = $this->provider()->create_return( \wc_get_order( $order->get_id() ) );
		$this->assertTrue( $again['idempotent'] );
		$this->assertSame( [], $this->hct_requests );
	}

	/**
	 * Error：退貨失敗（原貨號檢查碼錯誤）→ 例外 + note；未建單 → CONFLICT
	 *
	 * @group error
	 */
	public function test_退貨失敗(): void {
		$order = $this->make_shipped_order();
		$this->fake_hct( 'R_TransData_Json', 'r_trans_data_fail' );

		$e = $this->catch_error( fn() => $this->provider()->create_return( $order ) );
		$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
		$this->assertStringContainsString( '原貨號檢查碼錯誤', $this->notes( $order ) );

		$e = $this->catch_error( fn() => $this->provider()->create_return( $this->make_hct_order() ) );
		$this->assertSame( ErrorCode::CONFLICT, $e->error_code );
	}

	// endregion

	// region unsupported

	/**
	 * Error：選店 → UNSUPPORTED
	 *
	 * @group error
	 */
	public function test_選店不支援(): void {
		$order = $this->make_hct_order();

		$e = $this->catch_error( fn() => $this->provider()->get_store_selection( $order ) );
		$this->assertSame( ErrorCode::UNSUPPORTED, $e->error_code );
		$e = $this->catch_error( fn() => $this->provider()->parse_store_selection( [] ) );
		$this->assertSame( ErrorCode::UNSUPPORTED, $e->error_code );
	}

	/**
	 * Edge：無貨態推送 → 200 no-op
	 *
	 * @group edge
	 */
	public function test_貨態推送no_op(): void {
		$response = $this->provider()->handle_status_callback( new \WP_REST_Request( 'POST', '/x' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'HOME' ], $this->provider()->get_supported_methods() );
	}

	// endregion
}
