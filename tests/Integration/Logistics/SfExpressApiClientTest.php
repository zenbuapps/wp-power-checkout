<?php
/**
 * 順豐 dispatch client 測試（P6：sf_express）
 *
 * - header：appKey / token / timestamp(ms) / nonce / signature / msgType / lang=zh-HK / Content-Type: application/json
 * - body：整串 base64 密文（不是 JSON / key-value），解密後為業務 JSON；空 body 送 `{}`
 * - 兩層成功判斷：apiResultCode=0 → 解密 apiResultData → success=true
 * - 錯誤分類：2001 Signature is invalid → SIGNATURE；504 系統繁忙 → NETWORK；success=false → 業務碼（123052 → CONFLICT、123002 → NOT_FOUND、其餘 PROVIDER）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter SfExpressApiClient --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\SfExpress\DTOs\SfExpressSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Http\SfApiClient;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Exceptions\SfExpressException;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\Logistics\Doubles\SfExpressTestTrait;
use Tests\Integration\TestCase;

/**
 * 順豐 dispatch client
 *
 * @group integration
 * @group logistics
 * @group sf_express
 */
final class SfExpressApiClientTest extends TestCase {
	use SfExpressTestTrait;

	/** 每個測試前：設定假憑證 */
	protected function configure_dependencies(): void {
		$this->setup_sf_express();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_sf_express();
		parent::tear_down();
	}

	/** @return SfApiClient */
	private function client(): SfApiClient {
		return new SfApiClient( SfExpressSettingsDTO::instance() );
	}

	/**
	 * Happy：header / body / URL 格式正確，回傳解密後業務 JSON
	 *
	 * @group happy
	 */
	public function test_dispatch封包格式(): void {
		$this->fake_sf( 'IUOP_QUERY_TRACK', 'query_track_empty' );

		$result = $this->client()->dispatch(
			'IUOP_QUERY_TRACK',
			[
				'customerCode' => self::$sf_customer_code,
				'sfWaybillNo'  => self::$sf_waybill,
			]
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( '訂單不存在或訂單已歸檔', $result['data'][0]['trackSummary'] );

		$request = $this->sf_requests( 'IUOP_QUERY_TRACK' )[0];
		$headers = $request['headers'];
		$this->assertSame( 'https://api-ifsp-sit.sf.global/openapi/api/dispatch', $request['url'] );
		$this->assertSame( 'POST', $request['method'] );
		$this->assertSame( self::$sf_app_key, $headers['appKey'] );
		$this->assertSame( self::$sf_token, $headers['token'] );
		$this->assertMatchesRegularExpression( '/^\d{13}$/', (string) $headers['timestamp'] );
		$this->assertNotSame( '', (string) $headers['nonce'] );
		$this->assertSame( 'IUOP_QUERY_TRACK', $headers['msgType'] );
		$this->assertSame( 'zh-HK', $headers['lang'] );
		$this->assertSame( 'application/json', $headers['Content-Type'] );

		$sorted = [ self::$sf_token, (string) $headers['timestamp'], (string) $headers['nonce'], $request['raw_body'] ];
		\sort( $sorted, SORT_STRING );
		$this->assertSame( \hash( 'sha256', \implode( '', $sorted ) ), $headers['signature'] );

		$this->assertMatchesRegularExpression( '#^[A-Za-z0-9+/]+=*$#', $request['raw_body'], 'body 為純 base64 密文' );
		$this->assertSame(
			[
				'customerCode' => self::$sf_customer_code,
				'sfWaybillNo'  => self::$sf_waybill,
			],
			$request['body']
		);
	}

	/**
	 * Happy：正式站 URL
	 *
	 * @group happy
	 */
	public function test_正式站URL(): void {
		$this->setup_sf_express( [ 'mode' => 'prod' ] );
		$this->fake_sf( 'IUOP_QUERY_TRACK', 'query_track_empty' );

		$this->client()->dispatch( 'IUOP_QUERY_TRACK', [ 'sfWaybillNo' => self::$sf_waybill ] );

		$this->assertStringStartsWith( 'https://api-ifsp.sf.global/openapi/api/token?', $this->sf_requests( 'token' )[0]['url'] );
		$this->assertSame( 'https://api-ifsp.sf.global/openapi/api/dispatch', $this->sf_requests( 'IUOP_QUERY_TRACK' )[0]['url'] );
	}

	/**
	 * Edge：空 body 送 `{}`（不是 `[]`）
	 *
	 * @group edge
	 */
	public function test_空body送大括號(): void {
		$this->fake_sf( 'IPE_QUERY_PRODUCT_LIST', 'cancel_ok' );

		$this->client()->dispatch( 'IPE_QUERY_PRODUCT_LIST', [] );

		$raw = $this->sf_test_decrypt( $this->sf_requests( 'IPE_QUERY_PRODUCT_LIST' )[0]['raw_body'] );
		$this->assertSame( '{}', $raw );
	}

	/**
	 * Error：業務層 success=false → 例外帶業務碼（123052 → CONFLICT）
	 *
	 * @group error
	 */
	public function test_業務失敗帶業務碼(): void {
		$this->fake_sf( 'IUOP_CREATE_ORDER', 'create_duplicate' );

		try {
			$this->client()->dispatch( 'IUOP_CREATE_ORDER', [ 'customerOrderNo' => '1' ] );
			$this->fail( '應拋例外' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::CONFLICT, $e->error_code );
			$this->assertSame( '123052', $e->raw_code );
			$this->assertStringContainsString( '重複', $e->getMessage() );
		}
	}

	/**
	 * Error：業務碼分類（123002 → NOT_FOUND、其他 → PROVIDER）
	 *
	 * @group error
	 */
	public function test_業務碼分類(): void {
		$this->fake_sf( 'IUOP_QUERY_ORDER', 'query_order_not_found' );
		$this->fake_sf( 'IUOP_CREATE_ORDER', 'create_cargo_type_required' );

		try {
			$this->client()->dispatch( 'IUOP_QUERY_ORDER', [ 'customerOrderNo' => '1' ] );
			$this->fail( '應拋例外' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::NOT_FOUND, $e->error_code );
		}

		try {
			$this->client()->dispatch( 'IUOP_CREATE_ORDER', [ 'customerOrderNo' => '1' ] );
			$this->fail( '應拋例外' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::PROVIDER, $e->error_code );
			$this->assertSame( '123057', $e->raw_code );
		}
	}

	/**
	 * Error：平台層 2001 簽章錯 → SIGNATURE；504 系統繁忙 → NETWORK
	 *
	 * @group error
	 */
	public function test_平台層錯誤分類(): void {
		$this->fake_sf( 'IUOP_QUERY_TRACK', [ 'envelope' => 'envelope_signature_invalid' ], [ 'envelope' => 'envelope_busy' ] );

		try {
			$this->client()->dispatch( 'IUOP_QUERY_TRACK', [] );
			$this->fail( '應拋例外' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::SIGNATURE, $e->error_code );
			$this->assertSame( '2001', $e->raw_code );
		}

		try {
			$this->client()->dispatch( 'IUOP_QUERY_TRACK', [] );
			$this->fail( '應拋例外' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::NETWORK, $e->error_code );
			$this->assertSame( '504', $e->raw_code );
		}
	}

	/**
	 * Error：連線失敗 / HTTP 非 200 / 非 JSON → NETWORK
	 *
	 * @group error
	 */
	public function test_傳輸層失敗(): void {
		$this->fake_sf(
			'IUOP_QUERY_TRACK',
			new \WP_Error( 'http_request_failed', 'cURL error 28' ),
			[
				'status' => 502,
				'body'   => '<html>Bad Gateway</html>',
			],
			[
				'status' => 200,
				'body'   => 'not json',
			]
		);

		for ( $i = 0; $i < 3; $i++ ) {
			try {
				$this->client()->dispatch( 'IUOP_QUERY_TRACK', [] );
				$this->fail( '應拋例外' );
			} catch ( SfExpressException $e ) {
				$this->assertSame( ErrorCode::NETWORK, $e->error_code, "第 {$i} 次" );
			}
		}
	}

	/**
	 * Security：apiResultData 無法解密（非本 appKey 加密）→ SIGNATURE，不回傳內容
	 *
	 * @group security
	 */
	public function test_回應無法解密(): void {
		$this->fake_sf(
			'IUOP_QUERY_TRACK',
			static function (): array {
				return [
					'status' => 200,
					'body'   => (string) \wp_json_encode(
						[
							'apiResultCode' => 0,
							'apiResultData' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
						]
					),
				];
			}
		);

		try {
			$this->client()->dispatch( 'IUOP_QUERY_TRACK', [] );
			$this->fail( '應拋例外' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::SIGNATURE, $e->error_code );
		}
	}

	/**
	 * Error：憑證不齊（未填 AES 金鑰）→ VALIDATION，不打任何 API
	 *
	 * @group error
	 */
	public function test_憑證不齊不打API(): void {
		$this->setup_sf_express( [ 'encoding_aes_key' => '' ] );
		$this->block_sf_http();

		try {
			$this->client()->dispatch( 'IUOP_QUERY_TRACK', [] );
			$this->fail( '應拋 VALIDATION' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::VALIDATION, $e->error_code );
		}
		$this->assertSame( [], $this->sf_requests );
	}
}
