<?php
/**
 * 黑貓PAY 驗章（checksum / chk）golden 測試（P2）
 *
 * golden 向量只取 `.claude/skills/tcat-pay/references/examples.md` §3 與
 * `references/callbacks-apn.md` §2（官方 PDF 已驗證範例）：
 *  - CvsIbonUpdateDate：MD5("20190402000001:250:21") = e309160d46bcefaa7dd8db18a23f179f
 *  - CVS APN：MD5("CV0000000000:550e8400e29b41d4a716446655440000:1250:D:1234569999") = 3579609ba3914a49441e98cb7e8a55de
 *  - COCS APN：MD5("CC0000000001:550e8400e29b41d4a716446655440000:1250:B:1234569999") = d09d5532767453ad4c6ba9b649034187
 *
 * COCS 導轉 chk：文件範例（callbacks-apn.md §1.1/§1.2）未公開 hash_base，無法取得官方 golden；
 * 改以文件公式（`$` 串接、成功 9 欄 / 失敗 6 欄）對假 hash_base 自行計算的 MD5 鎖定欄位順序。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter TcatPayChecksum --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayChecksum;
use Tests\Integration\TestCase;

/**
 * 黑貓PAY 驗章測試
 *
 * @group integration
 * @group payment
 * @group tcat_pay
 */
final class TcatPayChecksumTest extends TestCase {

	/** @var array<string, string> 導轉成功回報（callbacks-apn.md §1.1 範例欄位） */
	private const SUCCESS_QUERY = [
		'ret'           => 'OK',
		'cust_order_no' => 'C201709141001',
		'order_amount'  => '2',
		'send_time'     => '2017-09-14 10:31:25',
		'acquire_time'  => '2017-09-14 10:36:38',
		'auth_code'     => '951294',
		'card_no'       => '1849',
		'notify_time'   => '2017-09-14 10:37:08',
	];

	/** @var array<string, string> 導轉失敗回報（callbacks-apn.md §1.2 範例欄位） */
	private const FAIL_QUERY = [
		'ret'           => 'FAIL',
		'cust_order_no' => 'C201709141001',
		'order_amount'  => '2',
		'send_time'     => '2017-09-14 10:31:25',
		'notify_time'   => '2017-09-14 10:37:08',
	];

	/** @var string 假 hash_base */
	private const HASH_BASE = 'HB_TEST_FAKE_0001';

	/**
	 * Happy：官方 APN 兩組向量（examples.md §3）
	 *
	 * @group happy
	 */
	public function test_APN官方向量_CVS與COCS(): void {
		$this->assertSame(
			'3579609ba3914a49441e98cb7e8a55de',
			TcatPayChecksum::apn_checksum( 'CV0000000000', '550e8400e29b41d4a716446655440000', '1250', 'D', '1234569999' )
		);
		$this->assertSame(
			'd09d5532767453ad4c6ba9b649034187',
			TcatPayChecksum::apn_checksum( 'CC0000000001', '550e8400e29b41d4a716446655440000', '1250', 'B', '1234569999' )
		);
	}

	/**
	 * Happy：CvsIbonUpdateDate 官方向量（examples.md §3）
	 *
	 * @group happy
	 */
	public function test_ibon變更到期日官方向量(): void {
		$this->assertSame(
			'e309160d46bcefaa7dd8db18a23f179f',
			TcatPayChecksum::ibon_update_date_checksum( '20190402000001', '250', '21' )
		);
	}

	/**
	 * Happy：官方 APN 範例 payload 原樣（amount 為 JSON number）驗章通過
	 *
	 * @group happy
	 */
	public function test_官方APN範例payload驗章通過(): void {
		foreach ( [ 'apn_cocs_b', 'apn_cvs_d' ] as $fixture ) {
			$payload = \json_decode( (string) \file_get_contents( __DIR__ . "/Fixtures/tcat_pay/{$fixture}.json" ), true );
			$this->assertIsArray( $payload );
			$this->assertTrue( TcatPayChecksum::verify_apn( $payload ), "{$fixture} 應驗章通過" );
		}
	}

	/**
	 * Happy：COCS 導轉成功 / 失敗 chk 依文件公式（`$` 串接 + hash_base 為首段）
	 *
	 * @group happy
	 */
	public function test_COCS導轉chk成功與失敗公式(): void {
		$q = self::SUCCESS_QUERY;
		$this->assertSame(
			\md5( self::HASH_BASE . '$2$2017-09-14 10:31:25$OK$2017-09-14 10:36:38$951294$1849$2017-09-14 10:37:08$C201709141001' ),
			TcatPayChecksum::cocs_chk_success( self::HASH_BASE, $q )
		);

		$f = self::FAIL_QUERY;
		$this->assertSame(
			\md5( self::HASH_BASE . '$2$2017-09-14 10:31:25$FAIL$2017-09-14 10:37:08$C201709141001' ),
			TcatPayChecksum::cocs_chk_fail( self::HASH_BASE, $f )
		);
	}

	/**
	 * Happy：verify_return 依 ret 自動選公式
	 *
	 * @group happy
	 */
	public function test_verify_return依ret選公式(): void {
		$success        = self::SUCCESS_QUERY;
		$success['chk'] = TcatPayChecksum::cocs_chk_success( self::HASH_BASE, $success );
		$this->assertTrue( TcatPayChecksum::verify_return( self::HASH_BASE, $success ) );

		$fail        = self::FAIL_QUERY;
		$fail['chk'] = TcatPayChecksum::cocs_chk_fail( self::HASH_BASE, $fail );
		$this->assertTrue( TcatPayChecksum::verify_return( self::HASH_BASE, $fail ) );
	}

	/**
	 * Security：欄位大小寫被改 / 少一欄 / 竄改金額 → 驗章失敗
	 *
	 * @group security
	 */
	public function test_APN欄位大小寫或缺欄或竄改皆驗章失敗(): void {
		$payload = \json_decode( (string) \file_get_contents( __DIR__ . '/Fixtures/tcat_pay/apn_cocs_b.json' ), true );
		$this->assertIsArray( $payload );

		$lower           = $payload;
		$lower['status'] = 'b';
		$this->assertFalse( TcatPayChecksum::verify_apn( $lower ), 'status 大小寫不同應失敗' );

		$lower_id           = $payload;
		$lower_id['api_id'] = 'cc0000000001';
		$this->assertFalse( TcatPayChecksum::verify_apn( $lower_id ), 'api_id 大小寫不同應失敗' );

		foreach ( [ 'api_id', 'trans_id', 'status', 'nonce', 'checksum' ] as $key ) {
			$missing = $payload;
			unset( $missing[ $key ] );
			$this->assertFalse( TcatPayChecksum::verify_apn( $missing ), "缺 {$key} 應失敗" );
		}

		$tampered           = $payload;
		$tampered['amount'] = 1;
		$this->assertFalse( TcatPayChecksum::verify_apn( $tampered ), '竄改金額應失敗' );

		$this->assertFalse( TcatPayChecksum::verify_apn( [] ) );
	}

	/**
	 * Security：導轉 chk 少一欄 / 錯 hash_base / 空 chk → 失敗
	 *
	 * @group security
	 */
	public function test_導轉chk缺欄或錯金鑰皆失敗(): void {
		$query        = self::SUCCESS_QUERY;
		$query['chk'] = TcatPayChecksum::cocs_chk_success( self::HASH_BASE, $query );

		$this->assertFalse( TcatPayChecksum::verify_return( 'WRONG_HASH_BASE', $query ), '錯 hash_base 應失敗' );

		$missing = $query;
		unset( $missing['auth_code'] );
		$this->assertFalse( TcatPayChecksum::verify_return( self::HASH_BASE, $missing ), '缺 auth_code 應失敗' );

		$empty        = $query;
		$empty['chk'] = '';
		$this->assertFalse( TcatPayChecksum::verify_return( self::HASH_BASE, $empty ), '空 chk 應失敗' );

		$this->assertFalse( TcatPayChecksum::verify_return( '', $query ), '空 hash_base 一律失敗（fail-closed）' );

		$case_changed        = $query;
		$case_changed['ret'] = 'ok';
		$this->assertFalse( TcatPayChecksum::verify_return( self::HASH_BASE, $case_changed ), 'ret 大小寫不同應失敗' );
	}

	/**
	 * Edge：摘要比對大小寫不敏感（平台曾出現大寫範例），長度不同一律不相等
	 *
	 * @group edge
	 */
	public function test_摘要比對大小寫不敏感且長度不同不相等(): void {
		$this->assertTrue( TcatPayChecksum::equals( 'd09d5532767453ad4c6ba9b649034187', 'D09D5532767453AD4C6BA9B649034187' ) );
		$this->assertFalse( TcatPayChecksum::equals( 'd09d5532767453ad4c6ba9b649034187', 'd09d5532767453ad4c6ba9b64903418' ) );
		$this->assertFalse( TcatPayChecksum::equals( '', '' ) );
	}
}
