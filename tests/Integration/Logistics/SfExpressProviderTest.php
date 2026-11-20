<?php
/**
 * 順豐 provider 測試（P6：sf_express）
 *
 * - create_shipment = IUOP_CREATE_ORDER：ref = sfWaybillNo；customerOrderNo = 訂單編號（不加亂數，逾時重送才會得到 123052）
 *   - 123052 重複 → IUOP_QUERY_ORDER 反查，收件手機相符才採用既有運單；不符 → CONFLICT
 *   - 已有 ref → 冪等回既有、不打 API
 *   - 本地驗證（不打 API）：收件電話 / 區劃 / 貨到付款 / 憑證 / 寄件人
 * - print_document = IUOP_PRINT_ORDER → 下載面單 PDF 回傳（面單網址不落庫）
 * - query_shipment = IUOP_QUERY_TRACK → 取最新認得的軌跡 → ShippingState
 * - cancel_shipment = IUOP_CANCEL_ORDER（順豐收件前）；已收件 → CONFLICT 不打 API；成功後清 ref 以便重建
 * - create_return / get_store_selection → UNSUPPORTED
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter SfExpressProvider --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\SfExpressProvider;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Exceptions\SfExpressException;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfExpressMetaKeys;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\Logistics\Doubles\SfExpressTestTrait;
use Tests\Integration\TestCase;

/**
 * 順豐 provider
 *
 * @group integration
 * @group logistics
 * @group sf_express
 */
final class SfExpressProviderTest extends TestCase {
	use SfExpressTestTrait;

	/** 每個測試前：啟用順豐 */
	protected function configure_dependencies(): void {
		$this->setup_sf_express();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_sf_express();
		parent::tear_down();
	}

	/** @return SfExpressProvider */
	private function provider(): SfExpressProvider {
		return SfExpressProvider::instance();
	}

	/**
	 * 斷言拋出指定錯誤碼的 SfExpressException
	 *
	 * @param ErrorCode $code     錯誤碼
	 * @param callable  $callback 執行
	 * @return SfExpressException
	 */
	private function assert_sf_error( ErrorCode $code, callable $callback ): SfExpressException {
		try {
			$callback();
		} catch ( SfExpressException $e ) {
			$this->assertSame( $code, $e->error_code, $e->getMessage() );
			return $e;
		}
		$this->fail( "應拋 {$code->value}" );
	}

	/**
	 * 取最新一筆 order note
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private function last_note( \WC_Order $order ): string {
		$notes = \wc_get_order_notes(
			[
				'order_id' => $order->get_id(),
				'limit'    => 1,
			]
		);
		return '' !== ( $notes[0]->content ?? '' ) ? (string) $notes[0]->content : '';
	}

	// region create_shipment

	/**
	 * Happy：建單 → ref = sfWaybillNo、order note、回 logistics_id；body 依規格組成
	 *
	 * @group happy
	 */
	public function test_建單成功(): void {
		$this->fake_sf( 'IUOP_CREATE_ORDER', 'create_ok' );
		$order = $this->make_sf_order(
			[
				'phone'          => '+886 912-345-678',
				'total'          => 1000,
				'shipping_total' => 100,
				'weight'         => '1.5',
				'qty'            => 2,
			]
		);

		$result = $this->provider()->create_shipment( $order );

		$this->assertSame( self::$sf_waybill, $result['logistics_id'] );
		$order = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( self::$sf_waybill, $order->get_meta( SfExpressMetaKeys::REF_KEY ) );
		$this->assertSame( SfExpressProvider::ID, $order->get_meta( SfExpressMetaKeys::PROVIDER_ID_KEY ) );
		$this->assertSame( ShippingState::PENDING->value, $order->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
		$this->assertStringContainsString( self::$sf_waybill, $this->last_note( $order ) );
		$this->assertSame( '', (string) $order->get_meta( '_pc_sf_express_label_url' ), '面單網址不落庫' );

		$body = $this->sf_request_body( 'IUOP_CREATE_ORDER' );
		$this->assertSame( self::$sf_customer_code, $body['customerCode'] );
		$this->assertSame( (string) $order->get_order_number(), $body['customerOrderNo'] );
		$this->assertSame( '1', $body['orderOperateType'] );
		$this->assertSame( '0000', $body['erpCode'] );
		$this->assertSame( '0000', $body['erpName'] );
		$this->assertSame( 'INT0005', $body['interProductCode'] );
		$this->assertSame( 'NTD', $body['declaredCurrency'] );
		$this->assertSame( '0', $body['pickupType'] );
		$this->assertSame( 1, $body['parcelQuantity'] );
		$this->assertSame(
			[
				'payMethod'    => '1',
				'payMonthCard' => self::$sf_month_card,
				'taxPayMethod' => '2',
			],
			$body['paymentInfo']
		);

		$sender = $body['senderInfo'];
		$this->assertSame( '王大明', $sender['contact'] );
		$this->assertSame( 'TW', $sender['country'] );
		$this->assertSame( 'Taipei City', $sender['regionFirst'] );
		$this->assertSame( 'Zhongzheng District', $sender['regionSecond'] );
		$this->assertSame( '886', $sender['phoneAreaCode'] );
		$this->assertSame( '0912000111', $sender['phoneNo'] );
		$this->assertSame( '2', $sender['cargoType'] );
		$this->assertArrayNotHasKey( 'certType', $sender, '未填統編不帶證件' );

		$receiver = $body['receiverInfo'];
		$this->assertSame( '王小明', $receiver['contact'] );
		$this->assertSame( 'TW', $receiver['country'] );
		$this->assertSame( '428', $receiver['postCode'] );
		$this->assertSame( 'Taichung City', $receiver['regionFirst'] );
		$this->assertSame( 'Daya District', $receiver['regionSecond'] );
		$this->assertStringContainsString( '中清路三段513號', $receiver['address'] );
		$this->assertSame( '0912345678', $receiver['phoneNo'], '+886 國碼換回 0 開頭' );
		$this->assertSame( '1', $receiver['cargoType'] );

		$parcel = $body['parcelInfoList'][0];
		$this->assertSame( 1000, $parcel['amount'], '申報價值 = 商品小計，不含運費' );
		$this->assertSame( 1, $parcel['quantity'] );
		$this->assertSame( 'TW', $parcel['originCountry'] );
		$this->assertSame( 3.0, (float) $body['parcelTotalWeight'] );
		$this->assertSame( 'KG', $body['parcelWeightUnit'] );
	}

	/**
	 * Happy：公司件填統編 → 帶 certType 009；商品無重量 → 不送 parcelTotalWeight（交順豐秤重）
	 *
	 * @group happy
	 */
	public function test_建單_統編與無重量(): void {
		$this->setup_sf_express( [ 'sender_tax_id' => '12345678' ] );
		$this->fake_sf( 'IUOP_CREATE_ORDER', 'create_ok' );

		$this->provider()->create_shipment( $this->make_sf_order() );

		$body = $this->sf_request_body( 'IUOP_CREATE_ORDER' );
		$this->assertSame( '009', $body['senderInfo']['certType'] );
		$this->assertSame( '12345678', $body['senderInfo']['certCardNo'] );
		$this->assertArrayNotHasKey( 'parcelTotalWeight', $body );
	}

	/**
	 * Edge：已有 ref → 冪等回既有、不打 API
	 *
	 * @group edge
	 */
	public function test_已建單冪等(): void {
		$order = $this->make_sf_order();
		$order->update_meta_data( SfExpressMetaKeys::REF_KEY, self::$sf_waybill );
		$order->save();
		$this->block_sf_http();

		$result = $this->provider()->create_shipment( $order );

		$this->assertSame( self::$sf_waybill, $result['logistics_id'] );
		$this->assertTrue( $result['idempotent'] );
		$this->assertSame( [], $this->sf_requests );
	}

	/**
	 * Edge：123052 重複 → 反查收件手機相符 → 採用既有運單
	 *
	 * @group edge
	 */
	public function test_重複訂單號採用既有單(): void {
		$this->fake_sf( 'IUOP_CREATE_ORDER', 'create_duplicate' );
		$this->fake_sf(
			'IUOP_QUERY_ORDER',
			fn(): array => [
				'status' => 200,
				'body'   => $this->business_envelope( $this->sf_fixture( 'query_order_ok', [ '__PHONE__' => '0912345678' ] ) ),
			]
		);
		$order = $this->make_sf_order();

		$result = $this->provider()->create_shipment( $order );

		$this->assertSame( self::$sf_waybill, $result['logistics_id'] );
		$this->assertSame( (string) $order->get_order_number(), $this->sf_request_body( 'IUOP_QUERY_ORDER' )['customerOrderNo'] );
		$this->assertSame( self::$sf_waybill, \wc_get_order( $order->get_id() )->get_meta( SfExpressMetaKeys::REF_KEY ) );
		$this->assertStringContainsString( '123052', $this->last_note( \wc_get_order( $order->get_id() ) ) );
	}

	/**
	 * Security：123052 但反查收件手機不符（別人的單）→ CONFLICT，不寫 ref
	 *
	 * @group security
	 */
	public function test_重複訂單號手機不符不採用(): void {
		$this->fake_sf( 'IUOP_CREATE_ORDER', 'create_duplicate' );
		$this->fake_sf(
			'IUOP_QUERY_ORDER',
			fn(): array => [
				'status' => 200,
				'body'   => $this->business_envelope( $this->sf_fixture( 'query_order_ok', [ '__PHONE__' => '0987654321' ] ) ),
			]
		);
		$order = $this->make_sf_order();

		$e = $this->assert_sf_error( ErrorCode::CONFLICT, fn() => $this->provider()->create_shipment( $order ) );

		$this->assertStringContainsString( self::$sf_waybill, $e->getMessage() );
		$this->assertSame( '', (string) \wc_get_order( $order->get_id() )->get_meta( SfExpressMetaKeys::REF_KEY ) );
	}

	/**
	 * Security：123052 但反查手機被遮罩 → CONFLICT（無從比對，不猜）
	 *
	 * @group security
	 */
	public function test_重複訂單號手機遮罩不採用(): void {
		$this->fake_sf( 'IUOP_CREATE_ORDER', 'create_duplicate' );
		$this->fake_sf(
			'IUOP_QUERY_ORDER',
			fn(): array => [
				'status' => 200,
				'body'   => $this->business_envelope( $this->sf_fixture( 'query_order_ok', [ '__PHONE__' => '0912***678' ] ) ),
			]
		);

		$this->assert_sf_error( ErrorCode::CONFLICT, fn() => $this->provider()->create_shipment( $this->make_sf_order() ) );
	}

	/**
	 * Error：業務失敗（123057）→ 例外含 raw code、寫 order note
	 *
	 * @group error
	 */
	public function test_建單業務失敗(): void {
		$this->fake_sf( 'IUOP_CREATE_ORDER', 'create_cargo_type_required' );
		$order = $this->make_sf_order();

		$e = $this->assert_sf_error( ErrorCode::PROVIDER, fn() => $this->provider()->create_shipment( $order ) );

		$this->assertSame( '123057', $e->raw_code );
		$this->assertStringContainsString( '順豐建單失敗', $this->last_note( \wc_get_order( $order->get_id() ) ) );
		$this->assertSame( '', (string) \wc_get_order( $order->get_id() )->get_meta( SfExpressMetaKeys::REF_KEY ) );
	}

	/**
	 * Error：回 success 卻沒有 sfWaybillNo → PROVIDER（不掛空 ref）
	 *
	 * @group error
	 */
	public function test_建單回應缺運單號(): void {
		$this->fake_sf( 'IUOP_CREATE_ORDER', 'create_no_waybill' );
		$order = $this->make_sf_order();

		$this->assert_sf_error( ErrorCode::PROVIDER, fn() => $this->provider()->create_shipment( $order ) );
		$this->assertSame( '', (string) \wc_get_order( $order->get_id() )->get_meta( SfExpressMetaKeys::REF_KEY ) );
	}

	/**
	 * Data：本地驗證失敗（不打 API）
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
	 */
	public function local_validation_cases(): array {
		return [
			'收件電話缺'   => [ [ 'phone' => '' ], [] ],
			'區劃換算不出'  => [
				[
					'city'     => '火星市',
					'postcode' => '999',
				],
				[],
			],
			'貨到付款'    => [ [ 'payment_method' => 'cod' ], [] ],
			'月結卡號缺'   => [ [], [ 'month_card' => '' ] ],
			'寄件人缺'    => [ [], [ 'sender_name' => '' ] ],
			'AES 金鑰缺' => [ [], [ 'encoding_aes_key' => '' ] ],
		];
	}

	/**
	 * Error：本地驗證失敗 → VALIDATION，不打任何 API
	 *
	 * @dataProvider local_validation_cases
	 * @group error
	 *
	 * @param array<string, mixed> $order_args    訂單覆寫
	 * @param array<string, mixed> $settings_args 設定覆寫
	 */
	public function test_建單本地驗證( array $order_args, array $settings_args ): void {
		if ( [] !== $settings_args ) {
			$this->setup_sf_express( $settings_args );
		}
		$this->block_sf_http();
		$order = $this->make_sf_order( $order_args );

		$this->assert_sf_error( ErrorCode::VALIDATION, fn() => $this->provider()->create_shipment( $order ) );
		$this->assertSame( [], $this->sf_requests );
	}

	// endregion

	// region print / query / cancel

	/**
	 * Happy：列印 → IUOP_PRINT_ORDER 取面單網址 → 下載 PDF 回傳
	 *
	 * @group happy
	 */
	public function test_列印面單(): void {
		$order = $this->make_shipped_order();
		$this->fake_sf( 'IUOP_PRINT_ORDER', 'print_ok' );
		$this->fake_sf(
			'download',
			[
				'status' => 200,
				'body'   => "%PDF-1.4\nfake label",
			]
		);

		$document = $this->provider()->print_document( $order );

		$this->assertStringStartsWith( '%PDF', $document );
		$body = $this->sf_request_body( 'IUOP_PRINT_ORDER' );
		$this->assertSame( self::$sf_customer_code, $body['customerCode'] );
		$this->assertSame( '1', $body['printType'] );
		$this->assertSame( 'PDF', $body['fileFormat'] );
		$this->assertSame( self::$sf_waybill, $body['printWaybillNoDtoList'][0]['sfWaybillNo'] );
		$this->assertSame( 1, $body['printWaybillNoDtoList'][0]['isPrintSubParent'] );
		$this->assertNotSame( '', (string) $body['requestId'] );
		$this->assertCount( 1, $this->sf_requests( 'download' ) );
	}

	/**
	 * Error：面單網址不是 http(s) → PROVIDER；尚未建單 → CONFLICT
	 *
	 * @group error
	 */
	public function test_列印失敗(): void {
		$this->block_sf_http();
		$this->assert_sf_error( ErrorCode::CONFLICT, fn() => $this->provider()->print_document( $this->make_sf_order() ) );

		$this->fake_sf( 'IUOP_PRINT_ORDER', 'print_bad_url' );
		$this->assert_sf_error( ErrorCode::PROVIDER, fn() => $this->provider()->print_document( $this->make_shipped_order() ) );
		$this->assertSame( [], $this->sf_requests( 'download' ) );
	}

	/**
	 * Happy：查詢 → 無序軌跡取最新（80 已簽收）→ delivered，寫 meta + order note
	 *
	 * @group happy
	 */
	public function test_查詢貨態(): void {
		$order = $this->make_shipped_order();
		$this->fake_sf( 'IUOP_QUERY_TRACK', 'query_track_ok' );

		$result = $this->provider()->query_shipment( $order );

		$this->assertSame( self::$sf_waybill, $result['logistics_id'] );
		$this->assertSame( '80', $result['status'] );
		$this->assertSame( '已簽收', $result['status_name'] );
		$this->assertSame( ShippingState::DELIVERED->value, $result['unified_state'] );
		$this->assertCount( 3, $result['history'] );
		$this->assertSame(
			[
				'customerCode' => self::$sf_customer_code,
				'sfWaybillNo'  => self::$sf_waybill,
			],
			$this->sf_request_body( 'IUOP_QUERY_TRACK' )
		);

		$order = \wc_get_order( $order->get_id() );
		$this->assertSame( ShippingState::DELIVERED->value, $order->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
		$this->assertSame( '80', $order->get_meta( SfExpressMetaKeys::STATUS_KEY ) );
		$this->assertStringContainsString( '已簽收', $this->last_note( $order ) );
	}

	/**
	 * Edge：查無軌跡（順豐尚未收件）→ pending + trackSummary，不視為失敗
	 *
	 * @group edge
	 */
	public function test_查詢無軌跡(): void {
		$this->fake_sf( 'IUOP_QUERY_TRACK', 'query_track_empty' );

		$result = $this->provider()->query_shipment( $this->make_shipped_order() );

		$this->assertSame( ShippingState::PENDING->value, $result['unified_state'] );
		$this->assertSame( [], $result['history'] );
		$this->assertStringContainsString( '訂單不存在', $result['status_name'] );
	}

	/**
	 * Error：尚未建單查詢 → CONFLICT；API 失敗 → 例外含 raw code
	 *
	 * @group error
	 */
	public function test_查詢失敗(): void {
		$this->block_sf_http();
		$this->assert_sf_error( ErrorCode::CONFLICT, fn() => $this->provider()->query_shipment( $this->make_sf_order() ) );

		$this->fake_sf( 'IUOP_QUERY_TRACK', [ 'envelope' => 'envelope_signature_invalid' ] );
		$e = $this->assert_sf_error( ErrorCode::SIGNATURE, fn() => $this->provider()->query_shipment( $this->make_shipped_order() ) );
		$this->assertSame( '2001', $e->raw_code );
	}

	/**
	 * Happy：取消 → IUOP_CANCEL_ORDER；成功後狀態 cancelled、清 ref（可重建）、保留已取消運單號
	 *
	 * @group happy
	 */
	public function test_取消託運單(): void {
		$order = $this->make_shipped_order();
		$this->fake_sf( 'IUOP_CANCEL_ORDER', 'cancel_ok' );

		$result = $this->provider()->cancel_shipment( $order );

		$this->assertTrue( $result['cancelled'] );
		$this->assertSame( self::$sf_waybill, $result['logistics_id'] );
		$this->assertSame(
			[
				'customerCode' => self::$sf_customer_code,
				'sfWaybillNo'  => self::$sf_waybill,
			],
			$this->sf_request_body( 'IUOP_CANCEL_ORDER' )
		);
		$order = \wc_get_order( $order->get_id() );
		$this->assertSame( '', (string) $order->get_meta( SfExpressMetaKeys::REF_KEY ) );
		$this->assertSame( self::$sf_waybill, $order->get_meta( SfExpressMetaKeys::CANCELLED_REF_KEY ) );
		$this->assertSame( ShippingState::CANCELLED->value, $order->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
		$this->assertStringContainsString( '取消', $this->last_note( $order ) );
	}

	/**
	 * Edge：已由軌跡得知順豐收件（accepted 以後）→ CONFLICT，不打 API
	 *
	 * @group edge
	 */
	public function test_已收件不可取消(): void {
		$order = $this->make_shipped_order( ShippingState::IN_TRANSIT );
		$this->block_sf_http();

		$this->assert_sf_error( ErrorCode::CONFLICT, fn() => $this->provider()->cancel_shipment( $order ) );
		$this->assertSame( [], $this->sf_requests );
	}

	/**
	 * Error：順豐拒絕取消 → 例外含 raw code，ref 保留
	 *
	 * @group error
	 */
	public function test_取消失敗保留ref(): void {
		$order = $this->make_shipped_order();
		$this->fake_sf( 'IUOP_CANCEL_ORDER', 'cancel_fail' );

		$e = $this->assert_sf_error( ErrorCode::NOT_FOUND, fn() => $this->provider()->cancel_shipment( $order ) );

		$this->assertSame( '123002', $e->raw_code );
		$this->assertSame( self::$sf_waybill, \wc_get_order( $order->get_id() )->get_meta( SfExpressMetaKeys::REF_KEY ) );
	}

	/**
	 * Error：退貨 / 選店 → UNSUPPORTED
	 *
	 * @group error
	 */
	public function test_不支援的操作(): void {
		$order = $this->make_shipped_order();

		$this->assert_sf_error( ErrorCode::UNSUPPORTED, fn() => $this->provider()->create_return( $order ) );
		$this->assert_sf_error( ErrorCode::UNSUPPORTED, fn() => $this->provider()->get_store_selection( $order ) );
		$this->assert_sf_error( ErrorCode::UNSUPPORTED, fn() => $this->provider()->parse_store_selection( [] ) );
	}

	// endregion

	// region helpers

	/**
	 * 已建單的訂單
	 *
	 * @param ShippingState $state 目前統一狀態
	 * @return \WC_Order
	 */
	private function make_shipped_order( ShippingState $state = ShippingState::PENDING ): \WC_Order {
		$order = $this->make_sf_order();
		$order->update_meta_data( SfExpressMetaKeys::PROVIDER_ID_KEY, SfExpressProvider::ID );
		$order->update_meta_data( SfExpressMetaKeys::REF_KEY, self::$sf_waybill );
		$order->update_meta_data( SfExpressMetaKeys::UNIFIED_STATE_KEY, $state->value );
		$order->save();
		return $order;
	}

	/**
	 * 業務 JSON → 加密外殼
	 *
	 * @param string $business 業務 JSON
	 * @return string
	 */
	private function business_envelope( string $business ): string {
		return (string) \wp_json_encode(
			[
				'apiResultCode' => 0,
				'apiErrorMsg'   => null,
				'apiResultData' => $this->sf_test_encrypt( $business ),
				'apiTimestamp'  => 1795198063063,
			]
		);
	}

	// endregion
}
