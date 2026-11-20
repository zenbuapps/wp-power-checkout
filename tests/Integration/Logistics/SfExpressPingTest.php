<?php
/**
 * 順豐「測試連線」ping 測試（P6：sf_express）
 *
 * 以已儲存的憑證走完整鏈路：取 token（appKey / appSecret）→ AES 加密 → signature → dispatch `IUOP_QUERY_TRACK`
 * 查一張不存在的運單（唯讀）→ 解密回應。
 * 依據：2026-11-21 以官方公開 sandbox 憑證唯讀實打 —— 查不存在運單回 success=true + trackSummary「訂單不存在或訂單已歸檔」；
 * 錯的 appSecret → token 端點 1005「App is not exist」；錯的 signature → 2001「Signature is invalid」。
 * 注意：IUOP_QUERY_TRACK / IUOP_QUERY_ORDER 對錯的 customerCode 也回相同結果，客戶編碼無法以唯讀接口驗證（回 customer_code_verified=false）。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter SfExpressPing --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsPingApiService;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\SfExpressProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\SfExpressTestTrait;
use Tests\Integration\TestCase;

/**
 * 順豐 ping
 *
 * @group integration
 * @group logistics
 * @group sf_express
 */
final class SfExpressPingTest extends TestCase {
	use SfExpressTestTrait;

	/** 每個測試前：設定憑證（未啟用）+ 重建 REST server */
	protected function configure_dependencies(): void {
		$this->setup_sf_express( [ 'enabled' => 'no' ] );
		ProviderUtils::$container = [];
		LogisticsPingApiService::instance();
		$GLOBALS['wp_rest_server'] = null;
		\rest_get_server();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_sf_express();
		\wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/** @return \WP_REST_Response */
	private function ping(): \WP_REST_Response {
		return \rest_do_request( new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/' . SfExpressProvider::ID . '/ping' ) );
	}

	/** @return void 以 admin 身分 */
	private function as_admin(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Happy：token + 加解密 + 簽章鏈路通 → 200 + 模式 / 端點摘要，不含任何憑證
	 *
	 * @group happy
	 */
	public function test_admin測試連線成功(): void {
		$this->as_admin();
		$this->fake_sf( 'IUOP_QUERY_TRACK', 'query_track_empty' );

		$response = $this->ping();

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertSame( SfExpressProvider::ID, $data['provider'] );
		$this->assertSame( 'test', $data['mode'] );
		$this->assertSame( 'https://api-ifsp-sit.sf.global', $data['endpoint'] );
		$this->assertFalse( $data['customer_code_verified'] );
		$this->assertTrue( $data['push_key_configured'] );
		$encoded = (string) \wp_json_encode( $response->get_data() );
		foreach ( [ self::$sf_app_secret, self::$sf_aes_key, self::$sf_token, self::$sf_push_key, self::$sf_month_card ] as $secret ) {
			$this->assertStringNotContainsString( $secret, $encoded );
		}
		$this->assertCount( 1, $this->sf_requests( 'token' ) );
		$this->assertCount( 1, $this->sf_requests( 'IUOP_QUERY_TRACK' ) );
		$this->assertSame( self::$sf_customer_code, $this->sf_request_body( 'IUOP_QUERY_TRACK' )['customerCode'] );
	}

	/**
	 * Error：appSecret 錯（token 端點 1005）→ AUTH
	 *
	 * @group error
	 */
	public function test_appSecret錯誤(): void {
		$this->as_admin();
		$this->fake_sf( 'token', 'token_app_not_exist' );

		$response = $this->ping();

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 'AUTH', $response->get_data()['error_code'] );
		$this->assertSame( '1005', $response->get_data()['raw_code'] );
	}

	/**
	 * Error：AES 金鑰 / 簽章不符（平台 2001）→ SIGNATURE
	 *
	 * @group error
	 */
	public function test_簽章錯誤(): void {
		$this->as_admin();
		$this->fake_sf( 'IUOP_QUERY_TRACK', [ 'envelope' => 'envelope_signature_invalid' ] );

		$response = $this->ping();

		$this->assertSame( 'SIGNATURE', $response->get_data()['error_code'] );
		$this->assertSame( '2001', $response->get_data()['raw_code'] );
	}

	/**
	 * Error：未填憑證 → 422 VALIDATION，不打 API
	 *
	 * @group error
	 */
	public function test_未填憑證不打API(): void {
		$this->as_admin();
		$this->setup_sf_express(
			[
				'enabled'    => 'no',
				'app_secret' => '',
			]
		);
		$this->block_sf_http();

		$response = $this->ping();

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'VALIDATION', $response->get_data()['error_code'] );
		$this->assertSame( [], $this->sf_requests );
	}

	/**
	 * Security：一般會員不可 ping
	 *
	 * @group security
	 */
	public function test_一般會員不可ping(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->block_sf_http();

		$response = $this->ping();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->sf_requests );
	}
}
