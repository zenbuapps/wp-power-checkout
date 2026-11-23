<?php
/**
 * 黑貓宅急便「測試連線」不可宣稱已驗證授權碼（P8 live 驗收發現）
 *
 * P8 錄影驗收：測試站以佔位契客代號 / 授權碼呼叫 ParsingAddress 仍回 200 + 郵號 →
 * ParsingAddress 不驗證授權碼，「連線成功」會讓管理員誤以為憑證正確。
 * 回應須明示 credentials_verified=false（比照順豐 customer_code_verified），設定頁據此提示以實際建單確認。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter TcatLogisticsPingCredentials --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsPingApiService;
use J7\PowerCheckout\Domains\Logistics\Tcat\Services\TcatLogisticsProvider;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\TcatLogisticsTestTrait;
use Tests\Integration\TestCase;

/**
 * 黑貓 ping 憑證驗證旗標
 *
 * @group integration
 * @group logistics
 * @group tcat_logistics
 */
final class TcatLogisticsPingCredentialsTest extends TestCase {
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
	 * Edge：ParsingAddress 成功 → 200，但 credentials_verified 必須為 false
	 *
	 * @group edge
	 */
	public function test_地址解析成功不代表授權碼已驗證(): void {
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->fake_tcat( 'ParsingAddress', 'parsing_address_ok' );

		$response = \rest_do_request( new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/' . TcatLogisticsProvider::ID . '/ping' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data()['data'];
		$this->assertArrayHasKey( 'credentials_verified', $data );
		$this->assertFalse( $data['credentials_verified'] );
	}
}
