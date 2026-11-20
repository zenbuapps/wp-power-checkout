<?php
/**
 * 黑貓PAY COCS 授權導轉回報測試（P2）
 *
 * 顧客刷卡後瀏覽器帶 query string 導回 success_url（= order-received）：
 *  - 驗 chk（MD5(hash_base$…)）→ 交易編號必須屬於本訂單 → 平台 CocsOrderQuery 確認（source of truth）→ StatusManager
 *  - 導轉回報只是前景 UX；chk 錯 / 編號不符 → 不改狀態、不打平台
 *  - never-throw：thank-you 頁不得 500
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter TcatPayReturn --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\TcatPay\Http\TcatPayApiClient;
use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayChecksum;
use Tests\Integration\Payment\Doubles\TcatPayTestTrait;
use Tests\Integration\TestCase;

/**
 * 黑貓PAY 導轉回報測試
 *
 * @group integration
 * @group payment
 * @group tcat_pay
 */
final class TcatPayReturnTest extends TestCase {
	use TcatPayTestTrait;

	/** 每個測試前：啟用黑貓PAY */
	protected function configure_dependencies(): void {
		$this->setup_tcat_pay();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_tcat_pay_http();
		parent::tear_down();
	}

	/**
	 * 授權成功導轉 query（以測試 hash_base 計算 chk）
	 *
	 * @param string $trade_no 交易編號
	 * @param string $amount   金額
	 * @return array<string, string>
	 */
	private function success_query( string $trade_no, string $amount = '1000' ): array {
		$query        = [
			'ret'           => 'OK',
			'cust_order_no' => $trade_no,
			'order_amount'  => $amount,
			'send_time'     => '2026-11-21 10:00:00',
			'acquire_time'  => '2026-11-21 10:03:00',
			'auth_code'     => '951294',
			'card_no'       => '1849',
			'notify_time'   => '2026-11-21 10:03:05',
		];
		$query['chk'] = TcatPayChecksum::cocs_chk_success( self::$tcat_hash_base, $query );
		return $query;
	}

	/**
	 * Happy：chk 正確 + 平台查詢授權完成 → processing
	 *
	 * @group happy
	 */
	public function test_chk正確且平台已付_訂單processing(): void {
		$trade_no = 'PCT1-RETOK';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_authorized', $trade_no ) );

		( new TcatPayGateway() )->handle_return( $order, $this->success_query( $trade_no ) );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( $trade_no, $fresh->get_transaction_id() );
		$this->assertStringContainsString( '導轉回報', \implode( "\n", $this->tcat_order_notes( $order ) ) );
	}

	/**
	 * Security：chk 錯 → 不打平台、不改狀態
	 *
	 * @group security
	 */
	public function test_chk錯不改狀態(): void {
		$trade_no = 'PCT1-RETBAD';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->block_tcat_http();

		$query        = $this->success_query( $trade_no );
		$query['chk'] = \md5( 'forged' );
		( new TcatPayGateway() )->handle_return( $order, $query );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( [], $this->tcat_collect_requests() );
	}

	/**
	 * Security：chk 正確但平台查詢未授權 → 不改狀態（導轉回報不是 source of truth）
	 *
	 * @group security
	 */
	public function test_chk正確但平台未付不改狀態(): void {
		$trade_no = 'PCT1-RETUNPAID';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_unpaid', $trade_no ) );

		( new TcatPayGateway() )->handle_return( $order, $this->success_query( $trade_no ) );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Security：交易編號不屬於本訂單（換單攻擊）→ 不打平台、不改狀態
	 *
	 * @group security
	 */
	public function test_交易編號不屬於本訂單不處理(): void {
		$order = $this->make_tcat_order( 1000.0, 'pending', 'credit', 'PCT1-MINE' );
		$this->block_tcat_http();

		( new TcatPayGateway() )->handle_return( $order, $this->success_query( 'PCT2-OTHER' ) );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertSame( [], $this->tcat_collect_requests() );
	}

	/**
	 * Edge：授權失敗回報（ret=FAIL、chk 正確）→ 保持 pending + note
	 *
	 * @group edge
	 */
	public function test_授權失敗回報保持pending並留note(): void {
		$trade_no = 'PCT1-RETFAIL';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->block_tcat_http();

		$query        = [
			'ret'           => 'FAIL',
			'cust_order_no' => $trade_no,
			'order_amount'  => '1000',
			'send_time'     => '2026-11-21 10:00:00',
			'notify_time'   => '2026-11-21 10:03:05',
		];
		$query['chk'] = TcatPayChecksum::cocs_chk_fail( self::$tcat_hash_base, $query );
		( new TcatPayGateway() )->handle_return( $order, $query );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertStringContainsString( '授權失敗', \implode( "\n", $this->tcat_order_notes( $order ) ) );
		$this->assertSame( [], $this->tcat_collect_requests() );
	}

	/**
	 * Error：平台查詢逾時 → 不拋例外（thank-you 頁不得 500）、不改狀態
	 *
	 * @group error
	 */
	public function test_平台查詢逾時不拋例外(): void {
		$trade_no = 'PCT1-RETTIMEOUT';
		$order    = $this->make_tcat_order( 1000.0, 'pending', 'credit', $trade_no );
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_QUERY,
			new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )
		);

		( new TcatPayGateway() )->handle_return( $order, $this->success_query( $trade_no ) );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Edge：沒有導轉參數（單純重訪 order-received）→ 什麼都不做
	 *
	 * @group edge
	 */
	public function test_無導轉參數不處理(): void {
		$order = $this->make_tcat_order( 1000.0, 'pending', 'credit', 'PCT1-REVISIT' );
		$this->block_tcat_http();

		( new TcatPayGateway() )->handle_return( $order, [ 'key' => $order->get_order_key() ] );

		$this->assertSame( [], $this->tcat_requests );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}
}
