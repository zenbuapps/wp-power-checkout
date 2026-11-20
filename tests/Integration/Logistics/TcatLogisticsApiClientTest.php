<?php
/**
 * 黑貓宅急便直串 API client 測試（P4）
 *
 * - 認證 = CustomerId + CustomerToken 放 JSON body 最上層；無簽章 / 無加密 / 無認證 header（skill SKILL.md §重要前提）
 * - 回應外殼 IsOK=Y|N；HTTP 200 不代表成功；Message 內嵌 Exxx 錯誤碼（2026-11-21 測試站實打確認格式）
 * - DownloadOBT 是唯一 binary 端點：200=PDF、400=JSON 錯誤（api-reference.md §5）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter TcatLogisticsApiClient --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Tcat\DTOs\TcatLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Tcat\Http\TcatLogisticsApiClient;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Exceptions\TcatLogisticsException;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\Logistics\Doubles\TcatLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 黑貓宅急便 API client 測試
 *
 * @group integration
 * @group logistics
 * @group tcat_logistics
 */
final class TcatLogisticsApiClientTest extends TestCase {
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

	/** @return TcatLogisticsApiClient */
	private function client(): TcatLogisticsApiClient {
		return new TcatLogisticsApiClient( TcatLogisticsSettingsDTO::instance() );
	}

	/**
	 * Happy：body 最上層含 CustomerId / CustomerToken，無簽章欄位、無認證 header；JSON POST 到測試站
	 *
	 * @group happy
	 */
	public function test_認證欄位放body最上層且無簽章(): void {
		$this->fake_tcat( 'ParsingAddress', 'parsing_address_ok' );

		$this->client()->parsing_address( [ '台北市重陽路200號' ] );

		$requests = $this->tcat_requests( 'ParsingAddress' );
		$this->assertCount( 1, $requests );
		$req  = $requests[0];
		$keys = \array_keys( $req['decoded'] );

		$this->assertSame( [ 'CustomerId', 'CustomerToken' ], \array_slice( $keys, 0, 2 ), '認證欄位必須在 body 最上層最前面' );
		$this->assertSame( self::$tcat_customer_id, $req['decoded']['CustomerId'] );
		$this->assertSame( self::$tcat_customer_token, $req['decoded']['CustomerToken'] );
		$this->assertSame( [ [ 'Search' => '台北市重陽路200號' ] ], $req['decoded']['Addresses'] );
		foreach ( [ 'Signature', 'CheckMacValue', 'Hash', 'Sign' ] as $forbidden ) {
			$this->assertArrayNotHasKey( $forbidden, $req['decoded'] );
		}
		$header_names = \array_map( 'strtolower', \array_keys( $req['headers'] ) );
		$this->assertNotContains( 'authorization', $header_names );
		$this->assertStringStartsWith( 'https://egs.suda.com.tw:8443/api/Egs/ParsingAddress', $req['url'] );
		$this->assertGreaterThanOrEqual( 120, (int) $req['timeout'], '官方要求連線逾時 ≥ 120 秒' );
	}

	/**
	 * Happy：prod 模式打正式站-1
	 *
	 * @group happy
	 */
	public function test_prod模式打正式站(): void {
		$this->setup_tcat_logistics( [ 'mode' => 'prod' ] );
		$this->fake_tcat( 'ParsingAddress', 'parsing_address_ok' );

		$this->client()->parsing_address( [ '台北市重陽路200號' ] );

		$this->assertStringStartsWith( 'https://api.suda.com.tw/api/Egs/ParsingAddress', $this->tcat_requests( 'ParsingAddress' )[0]['url'] );
	}

	/**
	 * Happy：PrintOBT 回 OBTNumber + FileNo（FileNo 在 Data 直屬，不在 Orders 內）
	 *
	 * @group happy
	 */
	public function test_PrintOBT回託運單號與FileNo(): void {
		$this->fake_tcat( 'PrintOBT', 'print_obt_ok' );

		$result = $this->client()->print_obt( '01', [ [ 'OrderId' => 'A100' ] ] );

		$this->assertSame( self::$tcat_obt, $result['obt_number'] );
		$this->assertSame( self::$tcat_file_no, $result['file_no'] );
		$this->assertSame( '20261121103000', $result['print_date_time'] );
		$this->assertSame( 'TN20261121000103', $result['srv_tran_id'] );

		$sent = $this->tcat_requests( 'PrintOBT' )[0]['decoded'];
		$this->assertSame( '01', $sent['PrintType'] );
		$this->assertSame( '01', $sent['PrintOBTType'] );
	}

	/**
	 * Error：IsOK=N + E009 → AUTH 例外，raw_code=E009，訊息不含授權碼
	 *
	 * @group error
	 */
	public function test_IsOK為N且E009對應AUTH例外(): void {
		$this->fake_tcat( 'ParsingAddress', 'error_e009' );

		try {
			$this->client()->parsing_address( [ '台北市重陽路200號' ] );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::AUTH, $e->error_code );
			$this->assertSame( 'E009', $e->raw_code );
			$this->assertStringContainsString( '契客資料不正確', $e->getMessage() );
			$this->assertStringNotContainsString( self::$tcat_customer_token, $e->getMessage() );
		}
	}

	/**
	 * Error：E001 欄位驗證 → VALIDATION
	 *
	 * @group error
	 */
	public function test_E001對應VALIDATION例外(): void {
		$this->fake_tcat( 'PrintOBT', 'error_e001' );

		try {
			$this->client()->print_obt( '01', [ [ 'OrderId' => 'A100' ] ] );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
			$this->assertSame( 'E001', $e->raw_code );
		}
	}

	/**
	 * Edge：錯誤碼分類（官方 §16.4 + 我方映射）
	 *
	 * @dataProvider error_code_cases
	 * @group edge
	 *
	 * @param string    $raw      E 碼
	 * @param ErrorCode $expected 正規化錯誤碼
	 */
	public function test_錯誤碼映射( string $raw, ErrorCode $expected ): void {
		$e = TcatLogisticsException::from_api_message( 'OBTStatus', "執行失敗 -> {$raw}-測試訊息" );

		$this->assertSame( $expected, $e->error_code );
		$this->assertSame( $raw, $e->raw_code );
	}

	/**
	 * 錯誤碼案例
	 *
	 * @return array<string, array{0: string, 1: ErrorCode}>
	 */
	public function error_code_cases(): array {
		return [
			'E007 契客代號長度' => [ 'E007', ErrorCode::AUTH ],
			'E015 授權碼不正確' => [ 'E015', ErrorCode::AUTH ],
			'E027 電話擇一'   => [ 'E027', ErrorCode::VALIDATION ],
			'E091 配達日上限'  => [ 'E091', ErrorCode::VALIDATION ],
			'E070 FileNo 逾期' => [ 'E070', ErrorCode::CONFLICT ],
			'E077 查無貨態'   => [ 'E077', ErrorCode::NOT_FOUND ],
			'E087 同單號限流'  => [ 'E087', ErrorCode::PROVIDER ],
			'E067 系統錯誤'   => [ 'E067', ErrorCode::PROVIDER ],
		];
	}

	/**
	 * Edge：訊息無 E 碼 → PROVIDER、raw_code null
	 *
	 * @group edge
	 */
	public function test_訊息無錯誤碼時為PROVIDER(): void {
		$e = TcatLogisticsException::from_api_message( 'PrintOBT', '執行失敗' );

		$this->assertSame( ErrorCode::PROVIDER, $e->error_code );
		$this->assertNull( $e->raw_code );
	}

	/**
	 * Error：HTTP 500 空 body（測試站假憑證打 ParsingAddress 的實際行為）→ PROVIDER
	 *
	 * @group error
	 */
	public function test_HTTP500空回應為PROVIDER例外(): void {
		$this->fake_tcat(
			'ParsingAddress',
			[
				'status' => 500,
				'body'   => '',
			]
		);

		try {
			$this->client()->parsing_address( [ '台北市重陽路200號' ] );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::PROVIDER, $e->error_code );
			$this->assertStringContainsString( '500', $e->getMessage() );
		}
	}

	/**
	 * Error：傳輸層失敗 → NETWORK
	 *
	 * @group error
	 */
	public function test_連線失敗為NETWORK例外(): void {
		$this->fake_tcat( 'OBTStatus', new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		try {
			$this->client()->obt_status( [ self::$tcat_obt ] );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::NETWORK, $e->error_code );
		}
	}

	/**
	 * Happy：OBTStatus 送字串陣列 OBTNumbers，回 OBTs 清單
	 *
	 * @group happy
	 */
	public function test_OBTStatus送字串陣列並回OBTs(): void {
		$this->fake_tcat( 'OBTStatus', static fn(): array => [ 'body' => \str_replace( [ '__STATUS__', '__STATUS_NAME__', '__ORDER_ID__' ], [ '301', '配完', 'A100' ], (string) \file_get_contents( __DIR__ . '/Fixtures/tcat_logistics/obt_status_ok.json' ) ) ] );

		$obts = $this->client()->obt_status( [ self::$tcat_obt ] );

		$this->assertSame( [ self::$tcat_obt ], $this->tcat_requests( 'OBTStatus' )[0]['decoded']['OBTNumbers'] );
		$this->assertCount( 1, $obts );
		$this->assertSame( '301', $obts[0]['StatusId'] );
	}

	/**
	 * Happy：DownloadOBT 200 → 回 PDF bytes（帶 FileNo 與指定單號）
	 *
	 * @group happy
	 */
	public function test_DownloadOBT回PDF位元組(): void {
		$pdf = $this->tcat_pdf();
		$this->fake_tcat(
			'DownloadOBT',
			[
				'body'         => $pdf,
				'content_type' => 'application/pdf',
			]
		);

		$result = $this->client()->download_obt( self::$tcat_file_no, [ self::$tcat_obt ] );

		$this->assertSame( $pdf, $result );
		$this->assertStringStartsWith( '%PDF', $result );
		$sent = $this->tcat_requests( 'DownloadOBT' )[0]['decoded'];
		$this->assertSame( self::$tcat_file_no, $sent['FileNo'] );
		$this->assertSame( [ [ 'OBTNumber' => self::$tcat_obt ] ], $sent['Orders'] );
	}

	/**
	 * Error：DownloadOBT 400 JSON（E070 FileNo 逾期）→ CONFLICT
	 *
	 * @group error
	 */
	public function test_DownloadOBT400為JSON錯誤(): void {
		$this->fake_tcat(
			'DownloadOBT',
			[
				'status' => 400,
				'body'   => $this->tcat_fixture( 'download_e070' ),
			]
		);

		try {
			$this->client()->download_obt( self::$tcat_file_no, [ self::$tcat_obt ] );
			$this->fail( '應拋 TcatLogisticsException' );
		} catch ( TcatLogisticsException $e ) {
			$this->assertSame( ErrorCode::CONFLICT, $e->error_code );
			$this->assertSame( 'E070', $e->raw_code );
		}
	}

	/**
	 * Error：DownloadOBT 200 但不是 PDF（空 body）→ PROVIDER
	 *
	 * @group error
	 */
	public function test_DownloadOBT回非PDF內容為PROVIDER(): void {
		$this->fake_tcat(
			'DownloadOBT',
			[
				'body'         => '',
				'content_type' => 'application/pdf',
			]
		);

		$this->expectException( TcatLogisticsException::class );
		$this->client()->download_obt( self::$tcat_file_no, [ self::$tcat_obt ] );
	}

	/**
	 * Happy：ReserveAdd 的 Order 是單一物件（非陣列），回配到的單號
	 *
	 * @group happy
	 */
	public function test_ReserveAdd送單一物件並回單號(): void {
		$this->fake_tcat( 'ReserveAdd', 'reserve_add_ok' );

		$result = $this->client()->reserve_add( [ 'OrderId' => 'R100' ] );

		$this->assertSame( '930005209999', $result['obt_number'] );
		$sent = $this->tcat_requests( 'ReserveAdd' )[0]['decoded'];
		$this->assertSame( '01', $sent['PrintType'] );
		$this->assertSame( [ 'OrderId' => 'R100' ], $sent['Order'] );
	}
}
