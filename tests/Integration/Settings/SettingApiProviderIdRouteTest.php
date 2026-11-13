<?php
/**
 * 設定 REST：provider_id 只能取自路由 URL（不可被本文 / 查詢字串改寫）
 *
 * 背景：WP_REST_Request 的參數優先序為 JSON 本文 > POST > GET 查詢字串 > URL 參數，
 * 用 $request['provider_id'] 取值時，本文夾帶 provider_id 會讓請求改寫到別的
 * `woocommerce_{x}_settings` option。所有 /settings/{provider_id} 端點都必須只讀 URL 參數。
 *
 * 執行：bash "<scratchpad>/gate.sh" ut tests/Integration/Settings/SettingApiProviderIdRouteTest.php
 */

declare( strict_types=1 );

namespace Tests\Integration\Settings;

use Tests\Integration\TestCase;

/**
 * provider_id 路由目標測試
 *
 * @group settings
 * @group security
 */
final class SettingApiProviderIdRouteTest extends TestCase {

	/** 路由上的 provider */
	private const ROUTE_PROVIDER = 'pc_route_target';

	/** 本文試圖改寫的 provider */
	private const VICTIM_PROVIDER = 'pc_victim';

	public function set_up(): void {
		parent::set_up();
		\update_option(
			'woocommerce_' . self::ROUTE_PROVIDER . '_settings',
			[
				'enabled' => 'no',
				'marker'  => 'route',
			]
			);
		\update_option(
			'woocommerce_' . self::VICTIM_PROVIDER . '_settings',
			[
				'enabled' => 'no',
				'marker'  => 'victim',
			]
			);
		$GLOBALS['wp_rest_server'] = null;
		\wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tear_down(): void {
		\delete_option( 'woocommerce_' . self::ROUTE_PROVIDER . '_settings' );
		\delete_option( 'woocommerce_' . self::VICTIM_PROVIDER . '_settings' );
		\wp_set_current_user( 0 );
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * 送出 JSON 本文請求
	 *
	 * @param string               $method GET|POST
	 * @param string               $route  路由
	 * @param array<string, mixed> $body   JSON 本文
	 * @param array<string, mixed> $query  查詢字串
	 */
	private function send( string $method, string $route, array $body = [], array $query = [] ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		if ( [] !== $query ) {
			$request->set_query_params( $query );
		}
		if ( [] !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) \wp_json_encode( $body ) );
		}
		return \rest_do_request( $request );
	}

	/**
	 * 讀取 option
	 *
	 * @return array<string, mixed>
	 */
	private function option( string $provider_id ): array {
		$value = \get_option( "woocommerce_{$provider_id}_settings", [] );
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * POST 本文夾帶別的 provider_id 時，只寫入路由上的 provider
	 *
	 * @test
	 * @group security
	 */
	public function test_安全_儲存時本文provider_id不能改寫目標option(): void {
		$response = $this->send(
			'POST',
			'/power-checkout/v1/settings/' . self::ROUTE_PROVIDER,
			[
				'provider_id' => self::VICTIM_PROVIDER,
				'marker'      => 'hacked',
			]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'victim', $this->option( self::VICTIM_PROVIDER )['marker'] ?? null, '本文指定的 provider 不得被改寫' );
		$this->assertSame( 'hacked', $this->option( self::ROUTE_PROVIDER )['marker'] ?? null, '應寫入路由上的 provider' );
		$this->assertArrayNotHasKey( 'provider_id', $this->option( self::ROUTE_PROVIDER ), 'provider_id 不應寫入 option' );
	}

	/**
	 * toggle 本文夾帶別的 provider_id 時，只切換路由上的 provider
	 *
	 * @test
	 * @group security
	 */
	public function test_安全_開關時本文provider_id不能改寫目標(): void {
		$response = $this->send(
			'POST',
			'/power-checkout/v1/settings/' . self::ROUTE_PROVIDER . '/toggle',
			[ 'provider_id' => self::VICTIM_PROVIDER ]
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no', $this->option( self::VICTIM_PROVIDER )['enabled'] ?? null, '本文指定的 provider 不得被切換' );
		$this->assertSame( 'yes', $this->option( self::ROUTE_PROVIDER )['enabled'] ?? null, '應切換路由上的 provider' );
	}

	/**
	 * GET 查詢字串夾帶別的 provider_id 時，仍回傳路由上的 provider 設定
	 *
	 * @test
	 * @group security
	 */
	public function test_安全_讀取時查詢字串provider_id不能改寫目標(): void {
		$response = $this->send(
			'GET',
			'/power-checkout/v1/settings/' . self::ROUTE_PROVIDER,
			[],
			[ 'provider_id' => self::VICTIM_PROVIDER ]
		);

		$this->assertSame( 200, $response->get_status() );
		$payload = $response->get_data();
		$this->assertIsArray( $payload );
		$this->assertIsArray( $payload['data'] ?? null );
		$this->assertSame( 'route', $payload['data']['marker'] ?? null, '應回傳路由上的 provider 設定' );
	}
}
