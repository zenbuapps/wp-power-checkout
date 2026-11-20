<?php
/**
 * 物流「測試連線」ping 端點測試（P4：tcat_logistics = ParsingAddress）
 *
 * POST power-checkout/v1/logistics/{provider}/ping
 *  - 需 admin（manage_options / manage_woocommerce）；未登入 401、一般會員 403
 *  - 不需 provider 已啟用（設定頁儲存後、啟用前即可測試）
 *  - provider 未實作 ISupportsConnectionTest → 501 UNSUPPORTED；未知 provider → 404
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter TcatLogisticsPing --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsPingApiService;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\TcatLogisticsProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\TcatLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 物流 ping 端點
 *
 * @group integration
 * @group logistics
 * @group tcat_logistics
 */
final class TcatLogisticsPingTest extends TestCase {
	use TcatLogisticsTestTrait;

	/** 每個測試前：設定憑證（未啟用）+ 重建 REST server */
	protected function configure_dependencies(): void {
		$this->setup_tcat_logistics( [ 'enabled' => 'no' ] );
		ProviderUtils::$container = [];
		LogisticsPingApiService::instance();
		$GLOBALS['wp_rest_server'] = null;
		\rest_get_server();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_tcat_logistics();
		\wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * 送 ping
	 *
	 * @param string $provider provider id
	 * @return \WP_REST_Response
	 */
	private function ping( string $provider = TcatLogisticsProvider::ID ): \WP_REST_Response {
		return \rest_do_request( new \WP_REST_Request( 'POST', "/power-checkout/v1/logistics/{$provider}/ping" ) );
	}

	/** @return void 以 admin 身分 */
	private function as_admin(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Smoke：路由已註冊
	 *
	 * @group smoke
	 */
	public function test_冒煙_ping路由已註冊(): void {
		$routes = \rest_get_server()->get_routes();
		$matched = \array_filter( \array_keys( $routes ), static fn( string $route ): bool => \str_ends_with( $route, '/ping' ) && \str_starts_with( $route, '/power-checkout/v1/logistics/' ) );
		$this->assertNotEmpty( $matched );
	}

	/**
	 * Happy：admin + 憑證正確 → ParsingAddress 成功 → 200 + 郵號
	 *
	 * @group happy
	 */
	public function test_admin測試連線成功(): void {
		$this->as_admin();
		$this->fake_tcat( 'ParsingAddress', 'parsing_address_ok' );

		$response = $this->ping();

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'success', $data['code'] );
		$this->assertSame( TcatLogisticsProvider::ID, $data['data']['provider'] );
		$this->assertSame( '80224B', $data['data']['zip_code'] );
		$this->assertSame( '台北市重陽路200號', $this->tcat_requests( 'ParsingAddress' )[0]['decoded']['Addresses'][0]['Search'] );
	}

	/**
	 * Error：憑證錯誤（E009）→ 非 200 + error_code + 黑貓原始訊息
	 *
	 * @group error
	 */
	public function test_憑證錯誤回錯誤碼(): void {
		$this->as_admin();
		$this->fake_tcat( 'ParsingAddress', 'error_e009' );

		$response = $this->ping();

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'AUTH', $data['error_code'] );
		$this->assertSame( 'E009', $data['raw_code'] );
		$this->assertStringContainsString( '契客資料不正確', $data['message'] );
	}

	/**
	 * Error：未填憑證 → 422 VALIDATION，不打 API
	 *
	 * @group error
	 */
	public function test_未填憑證不打API(): void {
		$this->as_admin();
		$this->setup_tcat_logistics(
			[
				'enabled'     => 'no',
				'customer_id' => '',
			]
		);
		$this->block_tcat_http();

		$response = $this->ping();

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'VALIDATION', $response->get_data()['error_code'] );
		$this->assertSame( [], $this->tcat_requests );
	}

	/**
	 * Error：未實作測試連線的 provider（PayNow）→ 501 UNSUPPORTED；未知 provider → 404
	 *
	 * @group error
	 */
	public function test_不支援與未知provider(): void {
		$this->as_admin();

		$unsupported = $this->ping( PaynowLogisticsProvider::ID );
		$unknown     = $this->ping( 'no_such_logistics' );

		$this->assertSame( 501, $unsupported->get_status() );
		$this->assertSame( 'UNSUPPORTED', $unsupported->get_data()['error_code'] );
		$this->assertSame( 404, $unknown->get_status() );
	}

	/**
	 * Security：未登入不可 ping
	 *
	 * @group security
	 */
	public function test_未登入不可ping(): void {
		\wp_set_current_user( 0 );
		$this->block_tcat_http();

		$response = $this->ping();

		$this->assertContains( $response->get_status(), [ 401, 403 ] );
		$this->assertSame( [], $this->tcat_requests );
	}

	/**
	 * Security：一般會員不可 ping
	 *
	 * @group security
	 */
	public function test_一般會員不可ping(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->block_tcat_http();

		$response = $this->ping();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->tcat_requests );
	}
}
