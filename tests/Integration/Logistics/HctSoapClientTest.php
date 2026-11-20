<?php
/**
 * 新竹物流 SOAP client 測試（P5）
 *
 * 新竹 EDI 是 ASP.NET `.asmx` SOAP：`_Json` 方法仍是 SOAP 呼叫，JSON 字串塞進 `<json>` 參數、回傳包在 `<{method}Result>`。
 * namespace / SOAPAction 以 2026-11-21 唯讀實抓 `service1.asmx?wsdl` 為準（skill version-notes.md §4 一致）：
 *   targetNamespace = http://tempuri.org/、SOAPAction = "http://tempuri.org/{method}"（無尾斜線）、參數 company / password / json。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter HctSoapClient --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Hct\DTOs\HctLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Hct\Http\HctSoapClient;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Exceptions\HctLogisticsException;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\Logistics\Doubles\HctLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 新竹物流 SOAP client
 *
 * @group integration
 * @group logistics
 * @group hct_logistics
 */
final class HctSoapClientTest extends TestCase {
	use HctLogisticsTestTrait;

	/** 每個測試前：設定假帳密 */
	protected function configure_dependencies(): void {
		$this->setup_hct_logistics();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_hct_logistics();
		parent::tear_down();
	}

	/** @return HctSoapClient */
	private function client(): HctSoapClient {
		return new HctSoapClient( HctLogisticsSettingsDTO::instance() );
	}

	/**
	 * 斷言拋 HctLogisticsException 並回傳之
	 *
	 * @param callable $callback 執行內容
	 * @return HctLogisticsException
	 */
	private function catch_hct( callable $callback ): HctLogisticsException {
		try {
			$callback();
		} catch ( HctLogisticsException $e ) {
			return $e;
		}
		$this->fail( '預期拋出 HctLogisticsException' );
	}

	/**
	 * Happy：envelope golden——POST DEV 端點、Content-Type、SOAPAction、namespace、參數順序 company / password / json
	 *
	 * @group happy
	 */
	public function test_envelope格式(): void {
		$this->fake_hct( 'TransData_Json', 'trans_data_ok' );

		$this->client()->trans_data( [ [ 'epino' => 'A00001' ] ] );

		$request = $this->hct_requests( 'TransData_Json' )[0];
		$this->assertSame( 'https://hctrt.hct.com.tw/edi_webservice2_test/service1.asmx', $request['url'] );
		$this->assertSame( 'text/xml; charset=utf-8', $request['headers']['Content-Type'] );
		$this->assertSame( '"http://tempuri.org/TransData_Json"', $request['headers']['SOAPAction'] );
		$this->assertStringContainsString( '<soap:Body><TransData_Json xmlns="http://tempuri.org/"><company>', $request['body'] );
		$this->assertMatchesRegularExpression( '#<company>.*</company><password>.*</password><json>.*</json>#s', $request['body'] );
		$this->assertSame( self::$hct_company, $request['params']['company'] );
		$this->assertSame( self::$hct_password, $request['params']['password'] );
		$this->assertSame( [ [ 'epino' => 'A00001' ] ], $this->hct_request_json( 'TransData_Json' ) );
	}

	/**
	 * Happy：正式模式打 PROD 端點
	 *
	 * @group happy
	 */
	public function test_正式模式端點(): void {
		$this->setup_hct_logistics( [ 'mode' => 'prod' ] );
		$this->fake_hct( 'QueryEDELNO_Json', 'query_edelno_not_found' );

		$this->client()->query_edelno( [ 'PCPING' ] );

		$this->assertSame( 'https://hctrt.hct.com.tw/edi_webservice2/service1.asmx', $this->hct_requests( 'QueryEDELNO_Json' )[0]['url'] );
	}

	/**
	 * Edge：JSON 參數內的 XML 特殊字元會被跳脫（不破壞 envelope），回傳 entity 會被解碼
	 *
	 * @group edge
	 */
	public function test_XML特殊字元跳脫與解碼(): void {
		$this->fake_hct( 'TransData_Json', 'trans_data_ok' );

		$rows = $this->client()->trans_data( [ [ 'epino' => 'A&B<1>' ] ] );

		$body = $this->hct_requests( 'TransData_Json' )[0]['body'];
		$this->assertStringNotContainsString( 'A&B<1>', $body );
		$this->assertStringContainsString( 'A&amp;B&lt;1&gt;', $body );
		$this->assertSame( 'A&B<1>', $this->hct_request_json( 'TransData_Json' )[0]['epino'] );
		$this->assertSame( 'Y', $rows[0]['success'] );
		$this->assertSame( self::$hct_edelno, $rows[0]['edelno'] );
		$this->assertSame( '^004900900^', $rows[0]['CODE7'] );
	}

	/**
	 * Happy：QueryEDELNO payload = 陣列包「單一」物件、epino 為逗號分隔（zenbu 對 DEV 實打結論）
	 *
	 * @group happy
	 */
	public function test_QueryEDELNO_payload形狀(): void {
		$this->fake_hct( 'QueryEDELNO_Json', 'query_edelno_not_found' );

		$rows = $this->client()->query_edelno( [ 'P002', 'S444' ] );

		$this->assertSame( [ [ 'epino' => 'P002,S444' ] ], $this->hct_request_json( 'QueryEDELNO_Json' ) );
		$this->assertSame( '查無資料', $rows[0]['ErrMsg'] );
	}

	/**
	 * Happy：逆物流回傳大寫 key（Success / Edelno / Erstno）正規化成小寫
	 *
	 * @group happy
	 */
	public function test_逆物流大寫key正規化(): void {
		$this->fake_hct( 'R_TransData_Json', 'r_trans_data_ok' );

		$rows = $this->client()->r_trans_data( [ [ 'epino' => 'R1001' ] ] );

		$this->assertSame( 'Y', $rows[0]['success'] );
		$this->assertSame( '2250446752', $rows[0]['edelno'] );
		$this->assertSame( '2060', $rows[0]['erstno'] );
		$this->assertSame( '', $rows[0]['ErrMsg'] );
	}

	/**
	 * Edge：null 欄位正規化為空字串
	 *
	 * @group edge
	 */
	public function test_null欄位轉空字串(): void {
		$this->fake_hct( 'QueryEDELNO_Json', 'query_edelno_auth' );

		$rows = $this->client()->query_edelno( [ 'X' ] );

		$this->assertSame( '', $rows[0]['edelno'] );
		$this->assertSame( '公司名稱或密碼錯誤', $rows[0]['ErrMsg'] );
	}

	/**
	 * Error：SOAP Fault → PROVIDER，訊息含 faultstring 但遮罩帳密
	 *
	 * @group error
	 */
	public function test_SOAP_Fault(): void {
		$this->fake_hct(
			'TransData_Json',
			[
				'status' => 500,
				'body'   => $this->hct_fixture( 'soap_fault.xml', [ '__COMPANY__' => self::$hct_company ] ),
			]
		);

		$e = $this->catch_hct( fn() => $this->client()->trans_data( [ [ 'epino' => 'A' ] ] ) );

		$this->assertSame( ErrorCode::PROVIDER, $e->error_code );
		$this->assertStringContainsString( 'Server was unable to process request', $e->getMessage() );
		$this->assertStringNotContainsString( self::$hct_company, $e->getMessage() );
	}

	/**
	 * Error：非 XML / 缺 Result wrapper → UNKNOWN
	 *
	 * @group error
	 */
	public function test_非XML回應(): void {
		$this->fake_hct(
			'TransData_Json',
			[
				'status' => 200,
				'body'   => '<html><body>Service Unavailable</body></html>',
			]
		);

		$e = $this->catch_hct( fn() => $this->client()->trans_data( [ [ 'epino' => 'A' ] ] ) );

		$this->assertSame( ErrorCode::UNKNOWN, $e->error_code );
	}

	/**
	 * Error：Result 不是 JSON → UNKNOWN
	 *
	 * @group error
	 */
	public function test_Result非JSON(): void {
		$this->fake_hct(
			'TransData_Json',
			[
				'status' => 200,
				'body'   => $this->hct_soap_envelope( 'TransData_Json', 'not json' ),
			]
		);

		$e = $this->catch_hct( fn() => $this->client()->trans_data( [ [ 'epino' => 'A' ] ] ) );

		$this->assertSame( ErrorCode::UNKNOWN, $e->error_code );
	}

	/**
	 * Error：HTTP 非 200 且無 Fault → PROVIDER
	 *
	 * @group error
	 */
	public function test_HTTP錯誤(): void {
		$this->fake_hct(
			'TransData_Json',
			[
				'status' => 503,
				'body'   => '',
			]
		);

		$e = $this->catch_hct( fn() => $this->client()->trans_data( [ [ 'epino' => 'A' ] ] ) );

		$this->assertSame( ErrorCode::PROVIDER, $e->error_code );
		$this->assertStringContainsString( '503', $e->getMessage() );
	}

	/**
	 * Error：傳輸層失敗（WP_Error）→ NETWORK
	 *
	 * @group error
	 */
	public function test_傳輸層失敗(): void {
		$this->fake_hct( 'TransData_Json', new \WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );

		$e = $this->catch_hct( fn() => $this->client()->trans_data( [ [ 'epino' => 'A' ] ] ) );

		$this->assertSame( ErrorCode::NETWORK, $e->error_code );
	}

	/**
	 * Happy：ErrMsg 分類——帳密錯 / 測試環境用正式帳密 → AUTH；重複 → CONFLICT；查無 → NOT_FOUND；其他 → VALIDATION
	 *
	 * @group happy
	 */
	public function test_ErrMsg分類(): void {
		$this->assertSame( ErrorCode::AUTH, HctLogisticsException::from_err_msg( 'TransData', '公司名稱或密碼錯誤' )->error_code );
		$this->assertSame( ErrorCode::AUTH, HctLogisticsException::from_err_msg( 'TransData', '「測試環境」請勿使用正式公司名稱或密碼' )->error_code );
		$this->assertSame( ErrorCode::CONFLICT, HctLogisticsException::from_err_msg( 'TransData', '訂單編號重複' )->error_code );
		$this->assertSame( ErrorCode::CONFLICT, HctLogisticsException::from_err_msg( 'TransData', '一個月內貨號重複' )->error_code );
		$this->assertSame( ErrorCode::NOT_FOUND, HctLogisticsException::from_err_msg( 'TransDataCancel', '查無貨號和訂單編號' )->error_code );
		$this->assertSame( ErrorCode::VALIDATION, HctLogisticsException::from_err_msg( 'TransData', '收貨人地址錯誤' )->error_code );

		$e = HctLogisticsException::from_err_msg( 'TransData', '收貨人地址錯誤' );
		$this->assertSame( '收貨人地址錯誤', $e->raw_code );
		$this->assertStringContainsString( '收貨人地址錯誤', $e->getMessage() );
	}

	/**
	 * Security：帳密不出現在例外訊息（含 SOAP fault 回吐帳密的情境）
	 *
	 * @group security
	 */
	public function test_例外訊息不含密碼(): void {
		$this->fake_hct(
			'TransData_Json',
			[
				'status' => 500,
				'body'   => $this->hct_fixture( 'soap_fault.xml', [ '__COMPANY__' => self::$hct_password ] ),
			]
		);

		$e = $this->catch_hct( fn() => $this->client()->trans_data( [ [ 'epino' => 'A' ] ] ) );

		$this->assertStringNotContainsString( self::$hct_password, $e->getMessage() );
		$this->assertStringContainsString( '***', $e->getMessage() );
	}
}
