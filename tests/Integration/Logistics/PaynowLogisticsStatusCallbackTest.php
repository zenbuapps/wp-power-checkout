<?php
/**
 * PayNow 物流貨態通知 Callback 整合測試（TDD Red 階段 — A-Cycle 2）
 *
 * 測試目標（尚未存在 → Red 階段）：
 *   J7\PowerCheckout\Domains\Logistics\Paynow\Http\LogisticsCallback
 *     ↳ 貨態通知方法（status-callback endpoint）
 *
 * 規格依據：
 *   - specs/features/logistics/paynow-logistics-query.feature（R1 決策背景）
 *   - specs/open-issue/paynow-logistics-invoice-tdd-blueprint.md §A-Cycle 2（StatusCallbackTest）
 *
 * R1 裁決（照此寫斷言）：
 *   handle_status_callback() 解析推送 payload：
 *   - 推送欄位：orderno / PayNowLogisticCode / Detail_Status_Description / paymentno / StoreDate / StoreTime
 *   - 以 orderno 走 PaynowLogisticsMetaKeys::get_order_by_order_no() 反查訂單（R4）
 *   - 推送內容不可信（無簽章）：只當觸發，以 Get_Order_Info 主動查詢結果寫回；冪等以查詢到的貨態碼為 key
 *   - 節流：同一訂單 10 秒內只查一次
 *   - 恆回 HTTP 200（含 \Throwable 路徑）
 *   - COD + 查詢結果為取貨完成 → collection_paid=yes（偽造推送無效）
 *
 * R4 裁決：反查走 PaynowLogisticsMetaKeys（不依賴 shared LogisticsMetaKeys）
 *
 * REST endpoint：
 *   POST /wp-json/power-checkout/paynow/logistics/status-callback（permission __return_true）
 *
 * ★ 鐵律：所有路徑（含例外）一律回 HTTP 200，避免 PayNow 重送風暴。
 *
 * 執行指令：
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/power-checkout \
 *     bash -c "API_MODE=mock vendor/bin/phpunit tests/Integration/Logistics/ \
 *       --filter PaynowLogisticsStatusCallbackTest"
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\DTOs\PaynowLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Paynow\Http\LogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * PayNow 物流貨態通知 Callback 測試類別
 *
 * @group integration
 * @group logistics
 * @group paynow
 */
final class PaynowLogisticsStatusCallbackTest extends TestCase {

	/** Callback 處理物件（在 Green 階段才存在） */
	private LogisticsCallback $callback;

	/** 每次測試前設定環境 */
	public function set_up(): void {
		parent::set_up();
		\putenv( 'API_MODE=mock' );
		\update_option( 'woocommerce_currency', 'TWD' );
		PaynowLogisticsSettingsDTO::reset();
		$this->enable_paynow_logistics();
		$this->callback = new LogisticsCallback();
	}

	/** 每次測試後清理 */
	public function tear_down(): void {
		\putenv( 'API_MODE=mock' );
		PaynowLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( PaynowLogisticsProvider::ID ) );
		parent::tear_down();
	}

	/**
	 * 啟用 paynow_logistics（test 模式）
	 */
	private function enable_paynow_logistics(): void {
		$this->enable_provider(
			PaynowLogisticsProvider::ID,
			[
				'mode'            => 'test',
				'user_account'    => 'TEST_LOGISTICS_ACCT',
				'apicode'         => 'TEST_LOGISTICS_CODE',
				'enabled_methods' => [ 'SEVEN', 'FAMI', 'HILIFE', 'TCAT' ],
				'sender_name'     => '測試寄件人',
				'sender_mobile'   => '0912345678',
				'sender_address'  => '台北市信義區測試路1號',
			]
		);
		PaynowLogisticsSettingsDTO::reset();
	}

	/**
	 * 建立貨態通知 WP_REST_Request
	 *
	 * @param array<string, mixed> $params POST 參數（PayNow 貨態推送 payload）
	 * @return \WP_REST_Request
	 */
	private function build_status_request( array $params ): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/paynow/logistics/status-callback' );
		$request->set_body_params( $params );
		return $request;
	}

	/**
	 * 建立標準貨態通知 payload
	 *
	 * @param string $order_no       OrderNo（PCN{order_id}）
	 * @param string $logistic_code  貨態碼
	 * @param string $description    貨態描述
	 * @return array<string, mixed>
	 */
	private function build_standard_payload(
		string $order_no,
		string $logistic_code = '3000',
		string $description = '物流配送中'
	): array {
		return [
			'orderno'                   => $order_no,
			'PayNowLogisticCode'        => $logistic_code,
			'Detail_Status_Description' => $description,
			'paymentno'                 => 'PAYNO_' . $order_no,
			'StoreDate'                 => '20260610',
			'StoreTime'                 => '123456',
		];
	}

	/**
	 * 建立已建物流單（有 LogisticNumber）的訂單
	 *
	 * @param array<string, mixed> $args create_wc_order 參數
	 * @return array{0: \WC_Order, 1: string} [訂單, orderno]
	 */
	private function create_shipped_order( array $args = [ 'status' => 'processing' ] ): array {
		$order     = $this->create_wc_order( $args );
		$order_no  = 'PCN' . $order->get_id();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_order_no( $order_no );
		$meta_keys->update_ref( 'LN_' . $order->get_id() );
		$order->save();
		return [ $order, $order_no ];
	}

	/**
	 * 重讀訂單的物流 meta
	 *
	 * @param int $order_id 訂單 ID
	 * @return PaynowLogisticsMetaKeys
	 */
	private function fresh_meta( int $order_id ): PaynowLogisticsMetaKeys {
		$fresh = \wc_get_order( $order_id );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		return new PaynowLogisticsMetaKeys( $fresh );
	}

	/**
	 * 清除節流 transient（模擬節流時間已過）
	 *
	 * @param int $order_id 訂單 ID
	 */
	private function clear_throttle( int $order_id ): void {
		\delete_transient( 'pc_paynow_logistics_sync_' . $order_id );
	}

	// =====================================================================
	// region: 推播只當觸發 — 貨態以主動查詢結果為準
	// =====================================================================

	/**
	 * 合法推送 → 200 + 以主動查詢（mock Get_Order_Info 回 0001）結果寫入貨態 meta
	 *
	 * @group happy
	 */
	public function test_push_triggers_query_and_writes_queried_status(): void {
		[ $order, $order_no ] = $this->create_shipped_order();

		$response = $this->callback->handle_status_callback(
			$this->build_status_request( $this->build_standard_payload( $order_no, '3000', '物流配送中' ) )
		);

		$this->assertSame( 200, $response->get_status(), '貨態通知應恆回 HTTP 200' );

		$meta = $this->fresh_meta( $order->get_id() );
		$this->assertSame( '0001', $meta->get_logistic_code(), 'logistic_code 應為主動查詢結果，而非推送值 3000' );
		$this->assertSame( '處理中（MOCK）', $meta->get_delivery_status(), 'delivery_status 應為主動查詢結果' );
		$this->assertTrue( $meta->is_processed( $order_no, '0001' ), '應以查詢到的貨態碼標記已處理' );
	}

	/**
	 * 推送帶的 paymentno / 描述不被信任、不寫入
	 *
	 * @group security
	 */
	public function test_push_fields_are_not_trusted(): void {
		[ $order, $order_no ] = $this->create_shipped_order();

		$payload              = $this->build_standard_payload( $order_no, '9999', '偽造描述' );
		$payload['paymentno'] = 'FORGED_PAYMENT_NO';
		$this->callback->handle_status_callback( $this->build_status_request( $payload ) );

		$meta = $this->fresh_meta( $order->get_id() );
		$this->assertNotSame( 'FORGED_PAYMENT_NO', $meta->get_payment_no(), '推送的 paymentno 不應寫入' );
		$this->assertNotSame( '9999', $meta->get_logistic_code(), '推送的貨態碼不應寫入' );
		$this->assertNotSame( '偽造描述', $meta->get_delivery_status(), '推送的描述不應寫入' );
	}

	/**
	 * 訂單尚未建立物流單（無 LogisticNumber）→ 不查詢、不寫 meta
	 *
	 * @group security
	 */
	public function test_push_for_order_without_shipment_is_ignored(): void {
		$order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$order_no  = 'PCN' . $order->get_id();
		$meta_keys = new PaynowLogisticsMetaKeys( $order );
		$meta_keys->update_order_no( $order_no );
		$order->save();

		$response = $this->callback->handle_status_callback(
			$this->build_status_request( $this->build_standard_payload( $order_no, '8000', '取貨完成' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$meta = $this->fresh_meta( $order->get_id() );
		$this->assertEmpty( $meta->get_logistic_code(), '無物流單時不應寫入貨態' );
		$this->assertEmpty( $meta->get_delivery_status() );
	}

	/**
	 * orderno 對應不到任何訂單時 → 恆回 200 不拋例外
	 *
	 * @group security
	 */
	public function test_callback_returns_200_when_order_not_found(): void {
		$response = $this->callback->handle_status_callback(
			$this->build_status_request( $this->build_standard_payload( 'PCN9999999', '3000', '偽造推送' ) )
		);

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), '找不到訂單時應恆回 200（不拋例外）' );
	}

	// endregion

	// =====================================================================
	// region: 節流 + 冪等
	// =====================================================================

	/**
	 * 節流：同一訂單節流時間內的第二次推送不再查詢
	 *
	 * 第一次查到 0001；第二次推送前把 mock 切成「取貨完成」，因節流不查詢 → 貨態維持 0001。
	 *
	 * @group edge
	 * @group security
	 */
	public function test_second_push_within_throttle_window_does_not_query(): void {
		[ $order, $order_no ] = $this->create_shipped_order( [ 'status' => 'processing', 'payment_method' => 'cod' ] );

		$this->callback->handle_status_callback( $this->build_status_request( $this->build_standard_payload( $order_no ) ) );

		\putenv( 'PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE=PICKUP_COMPLETE' );
		try {
			$this->callback->handle_status_callback(
				$this->build_status_request( $this->build_standard_payload( $order_no, '8000', '取貨完成' ) )
			);
		} finally {
			\putenv( 'PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE' );
		}

		$meta = $this->fresh_meta( $order->get_id() );
		$this->assertSame( '0001', $meta->get_logistic_code(), '節流期間不應再次查詢' );
		$this->assertNotSame( 'yes', $meta->get_collection_paid() );
	}

	/**
	 * 節流時間過後，下一次推送會重新查詢並寫入新貨態
	 *
	 * @group edge
	 */
	public function test_push_after_throttle_window_queries_again(): void {
		[ $order, $order_no ] = $this->create_shipped_order();

		$this->callback->handle_status_callback( $this->build_status_request( $this->build_standard_payload( $order_no ) ) );
		$this->clear_throttle( $order->get_id() );

		\putenv( 'PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE=PICKUP_COMPLETE' );
		try {
			$this->callback->handle_status_callback(
				$this->build_status_request( $this->build_standard_payload( $order_no, '8000', '取貨完成' ) )
			);
		} finally {
			\putenv( 'PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE' );
		}

		$meta = $this->fresh_meta( $order->get_id() );
		$this->assertSame( '8000', $meta->get_logistic_code(), '節流過後應以新的查詢結果更新' );
		$this->assertTrue( $meta->is_processed( $order_no, '0001' ) );
		$this->assertTrue( $meta->is_processed( $order_no, '8000' ) );
	}

	/**
	 * 攻擊者預先「標記」某貨態碼為已處理，不影響真實貨態寫入
	 *
	 * 舊實作以推送值做冪等，攻擊者可先送偽造的 8000 讓真實的 8000 推送被吞掉。
	 *
	 * @group security
	 */
	public function test_forged_push_cannot_poison_idempotency(): void {
		[ $order, $order_no ] = $this->create_shipped_order( [ 'status' => 'processing', 'payment_method' => 'cod' ] );

		// 偽造推送 8000（實際查詢仍是 0001）
		$this->callback->handle_status_callback(
			$this->build_status_request( $this->build_standard_payload( $order_no, '8000', '取貨完成' ) )
		);
		$this->assertFalse( $this->fresh_meta( $order->get_id() )->is_processed( $order_no, '8000' ) );

		// 真的取貨完成後 PayNow 推送 → 正常寫入
		$this->clear_throttle( $order->get_id() );
		\putenv( 'PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE=PICKUP_COMPLETE' );
		try {
			$this->callback->handle_status_callback(
				$this->build_status_request( $this->build_standard_payload( $order_no, '8000', '取貨完成' ) )
			);
		} finally {
			\putenv( 'PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE' );
		}

		$this->assertSame( 'yes', $this->fresh_meta( $order->get_id() )->get_collection_paid() );
	}

	// endregion

	// =====================================================================
	// region: COD 取貨完成
	// =====================================================================

	/**
	 * 偽造「取貨完成」推送，但 PayNow 查詢結果不是取貨完成 → 不標記 collection_paid
	 *
	 * @group security
	 */
	public function test_forged_pickup_complete_does_not_set_collection_paid(): void {
		[ $order, $order_no ] = $this->create_shipped_order( [ 'status' => 'processing', 'payment_method' => 'cod' ] );

		$response = $this->callback->handle_status_callback(
			$this->build_status_request( $this->build_standard_payload( $order_no, '8000', '取貨完成' ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertNotSame( 'yes', $this->fresh_meta( $order->get_id() )->get_collection_paid(), '偽造取貨完成不應標記已收款' );
	}

	/**
	 * COD 訂單 + PayNow 查詢結果為取貨完成 → collection_paid=yes
	 *
	 * @group happy
	 */
	public function test_cod_pickup_complete_sets_collection_paid(): void {
		[ $order, $order_no ] = $this->create_shipped_order( [ 'status' => 'processing', 'payment_method' => 'cod' ] );

		\putenv( 'PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE=PICKUP_COMPLETE' );
		try {
			$response = $this->callback->handle_status_callback(
				$this->build_status_request( $this->build_standard_payload( $order_no, '8000', '取貨完成' ) )
			);
		} finally {
			\putenv( 'PAYNOW_LOGISTICS_MOCK_QUERY_RESPONSE' );
		}

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'yes', $this->fresh_meta( $order->get_id() )->get_collection_paid(), 'COD 取貨完成應標記 collection_paid=yes' );
	}

	// endregion

	// =====================================================================
	// region: 恆回 200（含例外路徑）
	// =====================================================================

	/**
	 * 推送 payload 完全為空時 → 恆回 200（不拋例外）
	 *
	 * @group edge
	 * @group security
	 */
	public function test_empty_payload_still_returns_200(): void {
		$request  = $this->build_status_request( [] );
		$response = $this->callback->handle_status_callback( $request );

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), '空 payload 應恆回 200（不拋例外）' );
	}

	/**
	 * 推送 orderno 為空字串 → 恆回 200（不拋例外，不寫 meta）
	 *
	 * @group edge
	 */
	public function test_empty_orderno_still_returns_200(): void {
		$payload = $this->build_standard_payload( '', '3000', '偽造' );
		$request = $this->build_status_request( $payload );

		$response = $this->callback->handle_status_callback( $request );

		$this->assertSame( 200, $response->get_status(), 'orderno 為空時應恆回 200' );
	}

	/**
	 * 推送帶 \Throwable 的場景（強迫回呼路徑拋例外）仍回 200
	 *
	 * 在 callback 內以 SQL 無效操作觸發例外路徑（Green 時實作捕捉）。
	 * 此測試驗「含 \Throwable 路徑」Iron Rule。
	 *
	 * @group edge
	 * @group security
	 */
	public function test_callback_always_returns_200_even_on_exception(): void {
		// 推送一個 orderno 存在的訂單，但在 callback 中觸發例外：
		// 以超長 orderno（DB meta_key 查詢無效）模擬
		$long_order_no = str_repeat( 'X', 300 );
		$payload       = $this->build_standard_payload( $long_order_no );
		$request       = $this->build_status_request( $payload );

		$response = null;
		try {
			$response = $this->callback->handle_status_callback( $request );
		} catch ( \Throwable $e ) {
			$this->fail( '貨態 callback 不應向外拋出例外（\Throwable 應被吞掉並回 200）：' . $e->getMessage() );
		}

		$this->assertInstanceOf( \WP_REST_Response::class, $response );
		$this->assertSame( 200, $response->get_status(), '\Throwable 路徑應恆回 200' );
	}

	// endregion

	// =====================================================================
	// region: 安全性 — 偽造推送
	// =====================================================================

	/**
	 * 偽造 orderno（非 PCN 格式）推送 → 恆回 200，不修改任何訂單
	 *
	 * @group security
	 */
	public function test_forged_orderno_returns_200_without_side_effects(): void {
		// 建立一個真實訂單，確認它不被偽造推送影響
		$real_order     = $this->create_wc_order( [ 'status' => 'processing' ] );
		$real_order_no  = 'PCN' . $real_order->get_id();
		$real_meta_keys = new PaynowLogisticsMetaKeys( $real_order );
		$real_meta_keys->update_order_no( $real_order_no );

		// 偽造 orderno（不對應任何訂單）
		$payload = $this->build_standard_payload( 'FORGED_ORDER_NO_001', '9999', '偽造貨態' );
		$request = $this->build_status_request( $payload );

		$response = $this->callback->handle_status_callback( $request );

		$this->assertSame( 200, $response->get_status() );

		// 驗真實訂單未被汙染
		$fresh_real = \wc_get_order( $real_order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh_real );
		$fresh_real_meta = new PaynowLogisticsMetaKeys( $fresh_real );
		$this->assertEmpty( $fresh_real_meta->get_logistic_code(), '真實訂單不應被偽造推送汙染' );
	}

	// endregion
}
