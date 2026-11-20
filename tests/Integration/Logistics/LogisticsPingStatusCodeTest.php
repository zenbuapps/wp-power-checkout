<?php
/**
 * 物流「測試連線」ping 失敗的 HTTP 狀態碼不可為 5xx（P8 live 驗收發現）
 *
 * P8 錄影驗收（站點經 Cloudflare tunnel）：ping 對 provider 回報的失敗回 502，
 * Cloudflare 會把 origin 的 502 回應本文換成自家錯誤頁 → 設定頁只看到「發生未預期的錯誤」，
 * 管理員看不到「帳號或密碼錯誤」等診斷訊息。站點掛在 CDN / 反向代理後很常見，
 * 因此 provider 端失敗（AUTH / PROVIDER / NETWORK）改回 4xx（424 Failed Dependency），
 * VALIDATION 維持 422；本文 error_code / raw_code / message 不變。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter LogisticsPingStatusCode --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\KerrytjLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsPingApiService;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\KerrytjLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 物流 ping 失敗狀態碼
 *
 * @group integration
 * @group logistics
 */
final class LogisticsPingStatusCodeTest extends TestCase {
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
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		return \rest_do_request( new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/' . KerrytjLogisticsProvider::ID . '/ping' ) );
	}

	/**
	 * Error：provider 拒絕帳密（AUTH）→ 424，不回 5xx（避免 CDN 改寫本文），訊息保留
	 *
	 * @group error
	 */
	public function test_帳密被拒回424且保留診斷訊息(): void {
		$this->fake_kerrytj(
			'UpdateShipmentDelivery',
			[
				'status' => 401,
				'body'   => $this->kerrytj_fixture( 'unauthorized' ),
			]
		);

		$response = $this->ping();

		$this->assertSame( 424, $response->get_status() );
		$this->assertSame( 'AUTH', $response->get_data()['error_code'] );
		$this->assertSame( '401', $response->get_data()['raw_code'] );
		$this->assertStringContainsString( '帳號或密碼', $response->get_data()['message'] );
	}

	/**
	 * Edge：連線失敗（NETWORK）→ 仍回 4xx（< 500）
	 *
	 * @group edge
	 */
	public function test_連線失敗也不回5xx(): void {
		$this->fake_kerrytj( 'UpdateShipmentDelivery', new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ) );

		$response = $this->ping();

		$this->assertGreaterThanOrEqual( 400, $response->get_status() );
		$this->assertLessThan( 500, $response->get_status() );
		$this->assertSame( 'error', $response->get_data()['code'] );
	}
}
