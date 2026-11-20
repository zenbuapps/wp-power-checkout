<?php
/**
 * 黑貓PAY API client 測試（P2）
 *
 * - Token：POST /Token（form-urlencoded），transient 快取；`/api/Collect` 帶 `Authorization: Bearer {token}`（含空格）。
 * - HTTP 401（sandbox 實測：`{"Message":"Authorization has been denied for this request."}`）→ 清快取重取 1 次；再 401 → AUTH。
 * - `cust_id(X)與 token(Y)不匹配` → AUTH；連線失敗 → NETWORK；業務 status=ERROR → PROVIDER。
 * - 錯誤訊息不得含 API 密碼。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter TcatPayApiClient --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\TcatPay\DTOs\TcatPaySettingsDTO;
use J7\PowerCheckout\Domains\Payment\TcatPay\Http\TcatPayApiClient;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Exceptions\TcatPayApiException;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\Payment\Doubles\TcatPayTestTrait;
use Tests\Integration\TestCase;

/**
 * 黑貓PAY API client 測試
 *
 * @group integration
 * @group payment
 * @group tcat_pay
 */
final class TcatPayApiClientTest extends TestCase {
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

	/** @return TcatPayApiClient client */
	private function client(): TcatPayApiClient {
		return new TcatPayApiClient( TcatPaySettingsDTO::instance() );
	}

	/**
	 * Happy：Token 取得後快取（兩次 collect 只取一次 token）；Bearer 格式含空格；cust_id 由 client 補上
	 *
	 * @group happy
	 */
	public function test_Token快取且Bearer格式正確(): void {
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $this->tcat_fixture( 'cocs_order_query_authorized', 'PCT1-AAAAA' ) );

		$client = $this->client();
		$client->collect( TcatPayApiClient::CMD_COCS_ORDER_QUERY, [ 'cust_order_no' => 'PCT1-AAAAA' ] );
		$this->client()->collect( TcatPayApiClient::CMD_COCS_ORDER_QUERY, [ 'cust_order_no' => 'PCT1-AAAAA' ] );

		$this->assertCount( 1, $this->tcat_token_requests(), '第二次應命中 token 快取' );

		$token_req = $this->tcat_token_requests()[0];
		$this->assertSame( 'https://test.4128888card.com.tw/app/Token', $token_req['url'] );
		$this->assertSame( 'application/x-www-form-urlencoded', $token_req['headers']['Content-Type'] ?? '' );
		\parse_str( $token_req['body'], $form );
		$this->assertSame( 'password', $form['grant_type'] ?? '' );
		$this->assertSame( self::$tcat_cust_id, $form['username'] ?? '' );
		$this->assertSame( self::$tcat_api_password, $form['password'] ?? '' );

		$collect = $this->tcat_collect_requests( TcatPayApiClient::CMD_COCS_ORDER_QUERY );
		$this->assertCount( 2, $collect );
		$this->assertSame( 'https://test.4128888card.com.tw/app/api/Collect', $collect[0]['url'] );
		$this->assertSame( 'Bearer tok_fixture_A', $collect[0]['headers']['Authorization'] ?? '' );
		$this->assertSame( 'application/json', $collect[0]['headers']['Content-Type'] ?? '' );

		$body = $this->tcat_request_body( TcatPayApiClient::CMD_COCS_ORDER_QUERY );
		$this->assertSame( TcatPayApiClient::CMD_COCS_ORDER_QUERY, $body['cmd'] );
		$this->assertSame( self::$tcat_cust_id, $body['cust_id'] );
	}

	/**
	 * Happy：collect 成功回傳 decode 後陣列
	 *
	 * @group happy
	 */
	public function test_collect回傳平台回應陣列(): void {
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_CVS_ORDER_QUERY, $this->tcat_fixture( 'cvs_order_query_paid', 'PCT9-ZZZZZ' ) );

		$res = $this->client()->collect( TcatPayApiClient::CMD_CVS_ORDER_QUERY, [ 'cust_order_no' => 'PCT9-ZZZZZ' ] );

		$this->assertSame( 'OK', $res['status'] );
		$this->assertSame( 4, $res['process_code'] );
	}

	/**
	 * Error：401 → 清快取重取 token 1 次並重送成功
	 *
	 * @group error
	 */
	public function test_401清快取重取Token一次後成功(): void {
		$this->fake_tcat_token(
			$this->tcat_fixture( 'token' ),
			$this->tcat_fixture( 'token', '', [ 'access_token' => 'tok_fixture_B' ] )
		);
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_QUERY,
			[
				'__http' => 401,
				'body'   => $this->tcat_fixture( 'unauthorized' ),
			],
			$this->tcat_fixture( 'cocs_order_query_authorized', 'PCT1-AAAAA' )
		);

		$res = $this->client()->collect( TcatPayApiClient::CMD_COCS_ORDER_QUERY, [ 'cust_order_no' => 'PCT1-AAAAA' ] );

		$this->assertSame( 'OK', $res['status'] );
		$this->assertCount( 2, $this->tcat_token_requests() );
		$collect = $this->tcat_collect_requests();
		$this->assertCount( 2, $collect );
		$this->assertSame( 'Bearer tok_fixture_B', $collect[1]['headers']['Authorization'] ?? '' );
	}

	/**
	 * Error：重取後仍 401 → 拋 AUTH（只重試 1 次）
	 *
	 * @group error
	 */
	public function test_重取後仍401拋AUTH(): void {
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_QUERY,
			[
				'__http' => 401,
				'body'   => $this->tcat_fixture( 'unauthorized' ),
			]
		);

		try {
			$this->client()->collect( TcatPayApiClient::CMD_COCS_ORDER_QUERY, [ 'cust_order_no' => 'PCT1-AAAAA' ] );
			$this->fail( '應拋 TcatPayApiException' );
		} catch ( TcatPayApiException $e ) {
			$this->assertSame( ErrorCode::AUTH, $e->error_code );
		}
		$this->assertCount( 2, $this->tcat_collect_requests(), '只重試 1 次' );
		$this->assertCount( 2, $this->tcat_token_requests() );
	}

	/**
	 * Error：帳密錯（invalid_grant）→ AUTH，訊息不含 API 密碼
	 *
	 * @group error
	 */
	public function test_帳密錯拋AUTH且不洩漏密碼(): void {
		$this->fake_tcat_token(
			[
				'__http' => 400,
				'body'   => $this->tcat_fixture( 'token_invalid_grant' ),
			]
		);

		try {
			$this->client()->get_token();
			$this->fail( '應拋 TcatPayApiException' );
		} catch ( TcatPayApiException $e ) {
			$this->assertSame( ErrorCode::AUTH, $e->error_code );
			$this->assertStringNotContainsString( self::$tcat_api_password, $e->getMessage() );
		}
	}

	/**
	 * Error：cust_id 與 token 不匹配 → AUTH
	 *
	 * @group error
	 */
	public function test_cust_id與token不匹配拋AUTH(): void {
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_QUERY,
			[
				'status' => 'ERROR',
				'msg'    => 'cust_id(TESTCUST01)與 token(OTHER)不匹配',
			]
		);

		try {
			$this->client()->collect( TcatPayApiClient::CMD_COCS_ORDER_QUERY, [ 'cust_order_no' => 'PCT1-AAAAA' ] );
			$this->fail( '應拋 TcatPayApiException' );
		} catch ( TcatPayApiException $e ) {
			$this->assertSame( ErrorCode::AUTH, $e->error_code );
		}
	}

	/**
	 * Error：業務錯誤 status=ERROR → PROVIDER，保留平台訊息
	 *
	 * @group error
	 */
	public function test_業務錯誤拋PROVIDER保留平台訊息(): void {
		$this->fake_tcat_cmd( TcatPayApiClient::CMD_CVS_ORDER_APPEND, $this->tcat_fixture( 'cvs_order_append_error' ) );

		try {
			$this->client()->collect( TcatPayApiClient::CMD_CVS_ORDER_APPEND, [ 'cust_order_no' => 'PCT1-AAAAA' ] );
			$this->fail( '應拋 TcatPayApiException' );
		} catch ( TcatPayApiException $e ) {
			$this->assertSame( ErrorCode::PROVIDER, $e->error_code );
			$this->assertStringContainsString( '新增訂單失敗!!', $e->getMessage() );
		}
	}

	/**
	 * Error：連線失敗 / 逾時 → NETWORK；非 JSON（維護頁）→ PROVIDER
	 *
	 * @group error
	 */
	public function test_連線失敗拋NETWORK_非JSON拋PROVIDER(): void {
		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_QUERY,
			new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 15001 milliseconds' )
		);
		try {
			$this->client()->collect( TcatPayApiClient::CMD_COCS_ORDER_QUERY, [ 'cust_order_no' => 'PCT1-AAAAA' ] );
			$this->fail( '應拋 TcatPayApiException' );
		} catch ( TcatPayApiException $e ) {
			$this->assertSame( ErrorCode::NETWORK, $e->error_code );
		}

		$this->fake_tcat_cmd(
			TcatPayApiClient::CMD_COCS_ORDER_QUERY,
			[
				'__http' => 503,
				'body'   => '<html>maintenance</html>',
			]
		);
		try {
			$this->client()->collect( TcatPayApiClient::CMD_COCS_ORDER_QUERY, [ 'cust_order_no' => 'PCT1-AAAAA' ] );
			$this->fail( '應拋 TcatPayApiException' );
		} catch ( TcatPayApiException $e ) {
			$this->assertSame( ErrorCode::PROVIDER, $e->error_code );
			$this->assertStringNotContainsString( '<html>', $e->getMessage(), '不得把第三方頁面內容帶進訊息' );
		}
	}

	/**
	 * Error：憑證未設定 → 不打 API，拋 AUTH
	 *
	 * @group error
	 */
	public function test_憑證未設定不打API(): void {
		$this->setup_tcat_pay( [ 'api_password' => '' ] );
		$this->block_tcat_http();

		try {
			$this->client()->get_token();
			$this->fail( '應拋 TcatPayApiException' );
		} catch ( TcatPayApiException $e ) {
			$this->assertSame( ErrorCode::AUTH, $e->error_code );
		}
		$this->assertSame( [], $this->tcat_requests );
	}
}
