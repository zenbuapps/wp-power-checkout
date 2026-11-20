<?php
/**
 * 嘉里大榮「測試連線」ping 測試（P7：kerrytj_logistics）
 *
 * 藍圖 §6.7 原定 ping 回 UNSUPPORTED（501）；本 Phase 依派發指示實作 ISupportsConnectionTest：
 * 以已儲存帳密 POST `UpdateShipmentDelivery` 空陣列（不含任何託運資料 → 不會修改 / 建立任何單）驗證 Basic Auth：
 *  - 401（2026-11-21 無憑證實打 golden：`{"Message":"The requested authorization has been rejected."}`）→ AUTH
 *  - 其他回應（帳密通過後對空陣列回 Result 03 等）→ 200 + 環境 / 號段摘要（不含帳密）
 *  - 帳號 / 密碼未填 → 422 VALIDATION，不打 API
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter KerrytjLogisticsPing --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\KerrytjLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsPingApiService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\KerrytjLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 嘉里大榮 ping
 *
 * @group integration
 * @group logistics
 * @group kerrytj_logistics
 */
final class KerrytjLogisticsPingTest extends TestCase {
	use KerrytjLogisticsTestTrait;

	/** 每個測試前：設定帳密（未啟用）+ 重建 REST server */
	protected function configure_dependencies(): void {
		$this->setup_kerrytj_logistics( [ 'enabled' => 'no' ] );
		ProviderUtils::$container = [];
		LogisticsPingApiService::instance();
		$GLOBALS['wp_rest_server'] = null;
		\rest_get_server();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_kerrytj_logistics();
		\wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/** @return \WP_REST_Response */
	private function ping(): \WP_REST_Response {
		return \rest_do_request( new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/' . KerrytjLogisticsProvider::ID . '/ping' ) );
	}

	/** @return void 以 admin 身分 */
	private function as_admin(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Happy：帳密通過 → 200 + 環境 / 號段摘要，不含帳密；送出空陣列
	 *
	 * @group happy
	 */
	public function test_admin測試連線成功(): void {
		$this->as_admin();
		$this->fake_kerrytj( 'UpdateShipmentDelivery', 'shipment_rejected' );

		$response = $this->ping();

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertSame( KerrytjLogisticsProvider::ID, $data['provider'] );
		$this->assertSame( 'test', $data['mode'] );
		$this->assertSame( 'https://api.kerrytj.com/EDIEntranceTest', $data['endpoint'] );
		$this->assertTrue( $data['customer_no_configured'] );
		$this->assertSame( [], $data['missing_sender_fields'] );
		$this->assertTrue( $data['ambient_waybill_status']['configured'] );
		$this->assertTrue( $data['cold_waybill_status']['configured'] );
		$this->assertSame( [], $this->kerrytj_request_json( 'UpdateShipmentDelivery' ) );
		$encoded = (string) \wp_json_encode( $response->get_data() );
		$this->assertStringNotContainsString( self::$kerrytj_password, $encoded );
	}

	/**
	 * Error：帳密錯誤（401）→ AUTH
	 *
	 * @group error
	 */
	public function test_帳密錯誤(): void {
		$this->as_admin();
		$this->fake_kerrytj(
			'UpdateShipmentDelivery',
			[
				'status' => 401,
				'body'   => $this->kerrytj_fixture( 'unauthorized' ),
			]
		);

		$response = $this->ping();

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertSame( 'AUTH', $response->get_data()['error_code'] );
		$this->assertStringContainsString( '帳號或密碼', $response->get_data()['message'] );
	}

	/**
	 * Error：未填帳密 → 422 VALIDATION，不打 API
	 *
	 * @group error
	 */
	public function test_未填帳密不打API(): void {
		$this->as_admin();
		$this->setup_kerrytj_logistics(
			[
				'enabled'  => 'no',
				'password' => '',
			]
		);
		$this->block_kerrytj_http();

		$response = $this->ping();

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'VALIDATION', $response->get_data()['error_code'] );
		$this->assertSame( [], $this->kerrytj_requests );
	}

	/**
	 * Security：一般會員不可 ping
	 *
	 * @group security
	 */
	public function test_一般會員不可ping(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'customer' ] ) );
		$this->block_kerrytj_http();

		$response = $this->ping();

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( [], $this->kerrytj_requests );
	}
}
