<?php
/**
 * 黑貓PAY APN 主動通知測試（P2，R1 / R2）
 *
 * R1：APN checksum = MD5(api_id:trans_id:amount:status:nonce) 不含任何金鑰，任何人可偽造
 *     → APN 只當「觸發」：驗 checksum 後一律以平台查詢（CocsOrderQuery / CvsOrderQuery）確認才標已付款。
 * R2：平台只有收到純文字 `OK` 才停止重送（每 15 分鐘、同狀態最多 3 次）
 *     → 已收款觸發狀態但平台查詢尚未確認 / 平台查詢逾時 / 10 秒節流內 → 回 503 `RETRY` 讓平台重送；其餘一律回 `OK`。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter TcatPayApn --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\TcatPay\Http\TcatPayApiClient;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayMetaKeys;
use Tests\Integration\Payment\Doubles\TcatPayTestTrait;
use Tests\Integration\TestCase;

/**
 * 黑貓PAY APN 測試
 *
 * @group integration
 * @group payment
 * @group tcat_pay
 */
final class TcatPayApnTest extends TestCase {
	use TcatPayTestTrait;

	/** @var array<int, string> 擷取到的 log 訊息 */
	private array $logs = [];

	/** 每個測試前：啟用黑貓PAY、擷取 log */
	protected function configure_dependencies(): void {
		$this->setup_tcat_pay();
		\add_filter( 'woocommerce_logger_log_message', [ $this, 'capture_log' ], 10, 1 );
	}

	/** 清理 */
	public function tear_down(): void {
		\remove_filter( 'woocommerce_logger_log_message', [ $this, 'capture_log' ], 10 );
		$this->reset_tcat_pay_http();
		parent::tear_down();
	}

	/**
	 * 擷取 log
	 *
	 * @param string $message 訊息
	 * @return string
	 */
	public function capture_log( $message ): string {
		$this->logs[] = (string) $message;
		return (string) $message;
	}

	/**
	 * 斷言回應為純文字 OK
	 *
	 * @param \WP_REST_Response $response 回應
	 * @return void
	 */
	private function assert_ok_ack( \WP_REST_Response $response ): void {
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'OK', $response->get_data() );
		$this->assertStringContainsString( 'text/plain', (string) ( $response->get_headers()['Content-Type'] ?? '' ) );
	}

	/**
	 * 斷言回應不是 OK（平台會重送）
	 *
	 * @param \WP_REST_Response $response 回應
	 * @return void
	 */
	private function assert_retry_ack( \WP_REST_Response $response ): void {
		$this->assertNotSame( 'OK', $response->get_data(), '必須回非 OK 讓平台重送' );
		$this->assertSame( 503, $response->get_status() );
		$this->assertSame( 'RETRY', $response->get_data() );
	}

	/**
	 * 冒煙：APN 路由無條件註冊（POST /power-checkout/tcat-pay/apn）
	 *
	 * @group smoke
	 */
	public function test_冒煙_APN路由已註冊(): void {
		$routes = \rest_get_server()->get_routes();
		$this->assertArrayHasKey( '/power-checkout/tcat-pay/apn', $routes );
	}

	/**
	 * Happy：COCS APN B + 平台查詢授權完成 → processing、回純文字 OK
	 *
	 * @group happy
	 */
	public function test_COCS授權完成且平台確認_訂單processing回OK(): void {
		$trade_no = 'PCT1-APNOK';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_authorized', $trade_no ) );

		$response = $this->post_tcat_apn( $this->tcat_apn_payload( $trade_no, 'B' ) );

		$this->assert_ok_ack( $response );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( $trade_no, $fresh->get_transaction_id() );
		$this->assertCount( 1, $this->tcat_collect_requests( TcatPayApiClient::CMD_COCS_ORDER_QUERY ) );
		$this->assertNotSame( [], ( new TcatPayMetaKeys( $fresh ) )->get_payment_detail() );
	}

	/**
	 * Happy：CVS APN B + 平台查詢已繳納（process_code 4、pay_amount 相符）→ processing
	 *
	 * @group happy
	 */
	public function test_CVS已繳納且平台確認_訂單processing(): void {
		$trade_no = 'PCT1-CVSOK';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'cvs_ibon', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_CVS_ORDER_QUERY, $this->tcat_fixture( 'cvs_order_query_paid', $trade_no ) );

		$response = $this->post_tcat_apn( $this->tcat_apn_payload( $trade_no, 'B', true ) );

		$this->assert_ok_ack( $response );
		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Happy：舊嘗試的交易編號（歷史）付款仍可認列本訂單
	 *
	 * @group happy
	 */
	public function test_歷史交易編號付款仍可認列(): void {
		$order = $this->make_tcat_order( 1000.0, 'pending', 'cvs_ibon', 'PCT1-OLD01' );
		( new TcatPayMetaKeys( $order ) )->start_attempt( 'PCT1-NEW02', 1000 );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_CVS_ORDER_QUERY, $this->tcat_fixture( 'cvs_order_query_paid', 'PCT1-OLD01' ) );

		$this->assert_ok_ack( $this->post_tcat_apn( $this->tcat_apn_payload( 'PCT1-OLD01', 'B', true ) ) );

		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( 'PCT1-OLD01', $this->tcat_request_body( TcatPayApiClient::CMD_CVS_ORDER_QUERY )['cust_order_no'] );
	}

	/**
	 * Security：checksum 正確但平台查詢回未付 → 不改狀態、回 RETRY（503，平台約 15 分鐘後重送）、log、不寫冪等
	 *
	 * 平台同一狀態只重送約 3 次：若在平台尚未落庫時就回 OK，真正的付款通知就不會再來。
	 *
	 * @group security
	 */
	public function test_checksum正確但平台未付_不改狀態並回RETRY(): void {
		$trade_no = 'PCT1-FORGE';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_unpaid', $trade_no ) );

		$response = $this->post_tcat_apn( $this->tcat_apn_payload( $trade_no, 'B' ) );

		$this->assert_retry_ack( $response );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'pending', $fresh->get_status() );
		$this->assertSame( [], ( new TcatPayMetaKeys( $fresh ) )->get_processed_status(), '未確認不可寫冪等鍵' );
		$this->assertStringContainsString( '平台查詢未確認', \implode( "\n", $this->logs ) );
	}

	/**
	 * Happy：首次 APN 時平台尚未確認（RETRY）→ 平台重送時已確認 → 認列 processing、回 OK
	 *
	 * @group happy
	 */
	public function test_平台於重送時確認_認列並回OK(): void {
		$trade_no = 'PCT1-RESEND';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'cvs_ibon', $trade_no );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_CVS_ORDER_QUERY,
			$this->tcat_fixture( 'cvs_order_query_unpaid', $trade_no ),
			$this->tcat_fixture( 'cvs_order_query_paid', $trade_no )
		);
		$payload = $this->tcat_apn_payload( $trade_no, 'B', true );

		$this->assert_retry_ack( $this->post_tcat_apn( $payload ) );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );

		// 平台約 15 分鐘後重送同一則通知（節流早已過期）
		\delete_transient( 'pc_tcat_pay_apn_' . $order->get_id() );
		$this->assert_ok_ack( $this->post_tcat_apn( $payload ) );

		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 2, $this->tcat_collect_requests( TcatPayApiClient::CMD_CVS_ORDER_QUERY ) );
	}

	/**
	 * Security：checksum 錯 → 不打平台查詢、不改狀態、回 OK
	 *
	 * @group security
	 */
	public function test_checksum錯_不打平台查詢回OK(): void {
		$trade_no = 'PCT1-BADSUM';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->block_tcat_http();

		$payload             = $this->tcat_apn_payload( $trade_no, 'B' );
		$payload['checksum'] = \str_repeat( '0', 32 );
		$response            = $this->post_tcat_apn( $payload );

		$this->assert_ok_ack( $response );
		$this->assertSame( [], $this->tcat_collect_requests() );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Security：偽造 APN 先到（平台查詢未付）不可污染冪等，之後真 APN 仍可認列
	 *
	 * @group security
	 */
	public function test_偽造APN不污染冪等_真APN仍可認列(): void {
		$trade_no = 'PCT1-POISON';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_QUERY,
			$this->tcat_fixture( 'cocs_order_query_unpaid', $trade_no ),
			$this->tcat_fixture( 'cocs_order_query_authorized', $trade_no )
		);

		$this->assert_retry_ack( $this->post_tcat_apn( $this->tcat_apn_payload( $trade_no, 'B' ) ) );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );

		// 節流過後真正的 APN（同狀態）到達
		\delete_transient( 'pc_tcat_pay_apn_' . $order->get_id() );
		$this->assert_ok_ack( $this->post_tcat_apn( $this->tcat_apn_payload( $trade_no, 'B' ) ) );
		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Edge：同狀態重送 → 冪等（不再查詢、不重複 note）
	 *
	 * @group edge
	 */
	public function test_同狀態重送冪等(): void {
		$trade_no = 'PCT1-DUP';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_authorized', $trade_no ) );

		$payload = $this->tcat_apn_payload( $trade_no, 'B' );
		$this->assert_ok_ack( $this->post_tcat_apn( $payload ) );
		$notes_after_first = \count( $this->tcat_order_notes( $order ) );

		\delete_transient( 'pc_tcat_pay_apn_' . $order->get_id() );
		$this->assert_ok_ack( $this->post_tcat_apn( $payload ) );

		$this->assertCount( 1, $this->tcat_collect_requests( TcatPayApiClient::CMD_COCS_ORDER_QUERY ), '重送不再查詢' );
		$this->assertSame( $notes_after_first, \count( $this->tcat_order_notes( $order ) ) );
		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Edge：10 秒節流內第二次 → 不打平台查詢（回非 OK 讓平台稍後重送，避免漏認列真通知）
	 *
	 * @group edge
	 */
	public function test_10秒節流內不打平台查詢(): void {
		$trade_no = 'PCT1-THROT';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_unpaid', $trade_no ) );

		$this->assert_retry_ack( $this->post_tcat_apn( $this->tcat_apn_payload( $trade_no, 'B' ) ) );
		$second =$this->post_tcat_apn( $this->tcat_apn_payload( $trade_no, 'B' ) );

		$this->assertCount( 1, $this->tcat_collect_requests( TcatPayApiClient::CMD_COCS_ORDER_QUERY ) );
		$this->assert_retry_ack( $second );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Error：平台查詢逾時 → 回非 OK（讓平台 15 分鐘後重送）、不標已付款
	 *
	 * @group error
	 */
	public function test_平台查詢逾時回非OK(): void {
		$trade_no = 'PCT1-TIMEOUT';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_QUERY,
			new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds' )
		);

		$response = $this->post_tcat_apn( $this->tcat_apn_payload( $trade_no, 'B' ) );

		$this->assert_retry_ack( $response );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( [], ( new TcatPayMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_processed_status() );
	}

	/**
	 * Edge：pay_amount ≠ 訂單金額（疑似重複繳款）→ 不完成、note 金額不符
	 *
	 * @group edge
	 */
	public function test_實收金額不符不完成並留note(): void {
		$trade_no = 'PCT1-AMT';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'cvs_ibon', $trade_no );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_CVS_ORDER_QUERY,
			$this->tcat_fixture( 'cvs_order_query_paid', $trade_no, [ 'pay_amount' => 2000 ] )
		);

		$response = $this->post_tcat_apn( $this->tcat_apn_payload( $trade_no, 'B', true, 1000, [ 'pay_amount' => '2000' ] ) );

		$this->assert_ok_ack( $response );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( '金額不符', \implode( "\n", $this->tcat_order_notes( $order ) ) );
	}

	/**
	 * Edge：CVS 逾期（D）→ 保持 pending（不自動取消）+ note，且不打平台查詢
	 *
	 * @group edge
	 */
	public function test_CVS過期保持pending並留note(): void {
		$trade_no = 'PCT1-EXPIRE';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'cvs_ibon', $trade_no );
		$this->block_tcat_http();

		$this->assert_ok_ack( $this->post_tcat_apn( $this->tcat_apn_payload( $trade_no, 'D', true ) ) );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( [], $this->tcat_collect_requests() );
		$this->assertStringContainsString( '逾期', \implode( "\n", $this->tcat_order_notes( $order ) ) );
	}

	/**
	 * Edge：COCS 授權失敗（F）→ 保持 pending + note
	 *
	 * @group edge
	 */
	public function test_COCS授權失敗保持pending並留note(): void {
		$trade_no = 'PCT1-FAILED';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->block_tcat_http();

		$this->assert_ok_ack( $this->post_tcat_apn( $this->tcat_apn_payload( $trade_no, 'F' ) ) );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( '授權失敗', \implode( "\n", $this->tcat_order_notes( $order ) ) );
	}

	/**
	 * Edge：找不到訂單 / 非黑貓PAY訂單 / 非 JSON → 回 OK、不打平台查詢
	 *
	 * @group edge
	 */
	public function test_找不到訂單或非JSON回OK(): void {
		$this->block_tcat_http();

		$this->assert_ok_ack( $this->post_tcat_apn( $this->tcat_apn_payload( 'PCT999999-NOPE', 'B' ) ) );

		// 交易編號存在但訂單已改用其他付款方式（bacs）→ 不認列
		$bacs = $this->make_tcat_order( 1000.0, 'pending', 'credit', 'PCT1-BACS', 'bacs' );
		$this->assert_ok_ack( $this->post_tcat_apn( $this->tcat_apn_payload( 'PCT1-BACS', 'B' ) ) );

		$this->assert_ok_ack( $this->post_tcat_apn( 'not-json' ) );

		$this->assertSame( [], $this->tcat_collect_requests() );
		$this->assertSame( 'pending', \wc_get_order( $bacs->get_id() )->get_status() );
	}

	/**
	 * Security：log 不記 APN body 原文（不含 trans_id）
	 *
	 * @group security
	 */
	public function test_log不記body原文(): void {
		$trade_no = 'PCT1-LOG';
		$this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_authorized', $trade_no ) );

		$payload = $this->tcat_apn_payload( $trade_no, 'B' );
		$this->post_tcat_apn( $payload );

		$this->assertStringNotContainsString( (string) $payload['trans_id'], \implode( "\n", $this->logs ) );
	}
}
