<?php
/**
 * 新竹物流「測試連線」ping 測試（P5：hct_logistics）
 *
 * 以 QueryEDELNO_Json（唯讀，用公司名稱 + 密碼查一個不存在的訂單編號）驗證帳密：
 *  - 回「查無資料」= 帳密正確 → 200
 *  - 回「公司名稱或密碼錯誤」/「「測試環境」請勿使用正式公司名稱或密碼」→ AUTH（非 200）
 * （藍圖寫 addrCompare，但 addrCompare 用另一套帳號體系（USER=TEST2）無法驗證出貨帳密，故改 QueryEDELNO；訊息 golden 取自 2026-11-21 唯讀實打）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter HctLogisticsPing --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Hct\Services\HctLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsPingApiService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\HctLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 新竹物流 ping
 *
 * @group integration
 * @group logistics
 * @group hct_logistics
 */
final class HctLogisticsPingTest extends TestCase {
	use HctLogisticsTestTrait;

	/** 每個測試前：設定帳密（未啟用）+ 重建 REST server */
	protected function configure_dependencies(): void {
		$this->setup_hct_logistics( [ 'enabled' => 'no' ] );
		ProviderUtils::$container = [];
		LogisticsPingApiService::instance();
		$GLOBALS['wp_rest_server'] = null;
		\rest_get_server();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_hct_logistics();
		\wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/** @return \WP_REST_Response */
	private function ping(): \WP_REST_Response {
		return \rest_do_request( new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/' . HctLogisticsProvider::ID . '/ping' ) );
	}

	/** @return void 以 admin 身分 */
	private function as_admin(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Happy：帳密正確（查無資料）→ 200 + 模式 / 號段 / 查貨狀態摘要，不含帳密
	 *
	 * @group happy
	 */
	public function test_admin測試連線成功(): void {
		$this->as_admin();
		$this->fake_hct( 'QueryEDELNO_Json', 'query_edelno_not_found' );

		$response = $this->ping();

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertSame( HctLogisticsProvider::ID, $data['provider'] );
		$this->assertSame( 'test', $data['mode'] );
		$this->assertFalse( $data['track_query_configured'] );
		$this->assertArrayHasKey( 'waybill_status', $data );
		$encoded = (string) \wp_json_encode( $response->get_data() );
		$this->assertStringNotContainsString( self::$hct_password, $encoded );
		$this->assertCount( 1, $this->hct_requests( 'QueryEDELNO_Json' ) );
	}

	/**
	 * Error：帳密錯誤 → AUTH + 新竹原始訊息
	 *
	 * @group error
	 */
	public function test_帳密錯誤(): void {
		$this->as_admin();
		$this->fake_hct( 'QueryEDELNO_Json', 'query_edelno_auth' );

		$response = $this->ping();

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 'AUTH', $response->get_data()['error_code'] );
		$this->assertStringContainsString( '公司名稱或密碼錯誤', $response->get_data()['message'] );
	}

	/**
	 * Error：測試環境用了正式帳密 → AUTH 並提示切換環境
	 *
	 * @group error
	 */
	public function test_測試環境用正式帳密(): void {
		$this->as_admin();
		$this->fake_hct( 'QueryEDELNO_Json', 'query_edelno_test_env' );

		$response = $this->ping();

		$this->assertSame( 'AUTH', $response->get_data()['error_code'] );
		$this->assertStringContainsString( '測試環境', $response->get_data()['message'] );
	}

	/**
	 * Error：未填帳密 → 422 VALIDATION，不打 API
	 *
	 * @group error
	 */
	public function test_未填帳密不打API(): void {
		$this->as_admin();
		$this->setup_hct_logistics(
			[
				'enabled'  => 'no',
				'password' => '',
			]
		);
		$this->block_hct_http();

		$response = $this->ping();

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'VALIDATION', $response->get_data()['error_code'] );
		$this->assertSame( [], $this->hct_requests );
	}

	/**
	 * Security：一般會員不可 ping
	 *
	 * @group security
	 */
	public function test_一般會員不可ping(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->block_hct_http();

		$response = $this->ping();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->hct_requests );
	}
}
