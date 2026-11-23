<?php
/**
 * 順豐 Access Token 快取測試（P6：sf_express）
 *
 * - token 存 transient，TTL = expireIn − 60 秒（R12）；同一 mode + appKey 共用（新 token 會讓舊 token 失效，多程序必須共享）
 * - dispatch 回 apiResultCode=500「Access denied, token invalid」→ 清快取、重取 1 次再送；仍失敗 → AUTH
 * - 500 但訊息不是 token invalid（系統異常）→ 不重取 token、不重送
 * - token 端點失敗（1005 App is not exist）→ AUTH，訊息不含 appSecret
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter SfAccessTokenStore --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\SfExpress\DTOs\SfExpressSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Http\SfApiClient;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Exceptions\SfExpressException;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfAccessTokenStore;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\Logistics\Doubles\SfExpressTestTrait;
use Tests\Integration\TestCase;

/**
 * 順豐 token 快取
 *
 * @group integration
 * @group logistics
 * @group sf_express
 */
final class SfAccessTokenStoreTest extends TestCase {
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

	/** @return SfAccessTokenStore */
	private function store(): SfAccessTokenStore {
		return new SfAccessTokenStore( SfExpressSettingsDTO::instance() );
	}

	/**
	 * Happy：第一次打 token 端點（GET + appKey/appSecret querystring + sandbox host），第二次命中快取
	 *
	 * @group happy
	 */
	public function test_快取命中不重取(): void {
		$this->fake_sf( 'token', 'token_ok' );

		$first  = $this->store()->get();
		$second = $this->store()->get();

		$this->assertSame( self::$sf_token, $first );
		$this->assertSame( $first, $second );
		$requests = $this->sf_requests( 'token' );
		$this->assertCount( 1, $requests );
		$this->assertSame( 'GET', $requests[0]['method'] );
		$this->assertStringStartsWith( 'https://api-ifsp-sit.sf.global/openapi/api/token?', $requests[0]['url'] );
		\parse_str( (string) \wp_parse_url( $requests[0]['url'], PHP_URL_QUERY ), $query );
		$this->assertSame( self::$sf_app_key, $query['appKey'] ?? null );
		$this->assertSame( self::$sf_app_secret, $query['appSecret'] ?? null );
	}

	/**
	 * Happy：TTL = expireIn(7200) − 60
	 *
	 * @group happy
	 */
	public function test_TTL為expireIn減60秒(): void {
		$this->fake_sf( 'token', 'token_ok' );

		$this->store()->get();

		$timeout = (int) \get_option( '_transient_timeout_' . SfAccessTokenStore::cache_key( 'test', self::$sf_app_key ) );
		$this->assertEqualsWithDelta( \time() + 7140, $timeout, 5 );
	}

	/**
	 * Edge：快取過期（transient 消失）→ 重新取號
	 *
	 * @group edge
	 */
	public function test_過期後重取(): void {
		$this->fake_sf( 'token', 'token_ok' );
		$this->store()->get();
		\delete_transient( SfAccessTokenStore::cache_key( 'test', self::$sf_app_key ) );

		$this->store()->get();

		$this->assertCount( 2, $this->sf_requests( 'token' ) );
	}

	/**
	 * Edge：快取以 mode + appKey 區隔（換正式站不沿用沙箱 token）
	 *
	 * @group edge
	 */
	public function test_快取依環境區隔(): void {
		$this->assertNotSame(
			SfAccessTokenStore::cache_key( 'test', self::$sf_app_key ),
			SfAccessTokenStore::cache_key( 'prod', self::$sf_app_key )
		);
		$this->assertNotSame(
			SfAccessTokenStore::cache_key( 'test', self::$sf_app_key ),
			SfAccessTokenStore::cache_key( 'test', 'other-app-key' )
		);
		$this->assertStringNotContainsString( self::$sf_app_key, SfAccessTokenStore::cache_key( 'test', self::$sf_app_key ) );
	}

	/**
	 * Error：dispatch 回 token invalid → 清快取重取 1 次後重送成功
	 *
	 * @group error
	 */
	public function test_token失效清快取重取一次(): void {
		$this->fake_sf( 'token', 'token_ok' );
		$this->fake_sf( 'IUOP_QUERY_TRACK', [ 'envelope' => 'envelope_token_invalid' ], 'query_track_empty' );

		$result = ( new SfApiClient( SfExpressSettingsDTO::instance() ) )->dispatch(
			'IUOP_QUERY_TRACK',
			[
				'customerCode' => self::$sf_customer_code,
				'sfWaybillNo'  => self::$sf_waybill,
			]
		);

		$this->assertTrue( $result['success'] );
		$this->assertCount( 2, $this->sf_requests( 'token' ), '第一次取號 + token invalid 後強制重取' );
		$this->assertCount( 2, $this->sf_requests( 'IUOP_QUERY_TRACK' ) );
	}

	/**
	 * Error：重取後仍 token invalid → AUTH（只重試一次，不無限迴圈）
	 *
	 * @group error
	 */
	public function test_重取後仍失效拋AUTH(): void {
		$this->fake_sf( 'token', 'token_ok' );
		$this->fake_sf( 'IUOP_QUERY_TRACK', [ 'envelope' => 'envelope_token_invalid' ] );

		try {
			( new SfApiClient( SfExpressSettingsDTO::instance() ) )->dispatch( 'IUOP_QUERY_TRACK', [ 'sfWaybillNo' => self::$sf_waybill ] );
			$this->fail( '應拋 AUTH' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::AUTH, $e->error_code );
			$this->assertSame( '500', $e->raw_code );
		}
		$this->assertCount( 2, $this->sf_requests( 'IUOP_QUERY_TRACK' ) );
		$this->assertFalse( \get_transient( SfAccessTokenStore::cache_key( 'test', self::$sf_app_key ) ) );
	}

	/**
	 * Edge：500 但不是 token invalid（系統異常）→ 不重取 token、不重送
	 *
	 * @group edge
	 */
	public function test_系統異常500不重試(): void {
		$this->fake_sf( 'token', 'token_ok' );
		$this->fake_sf( 'IUOP_QUERY_TRACK', [ 'envelope' => 'envelope_system_error' ] );

		try {
			( new SfApiClient( SfExpressSettingsDTO::instance() ) )->dispatch( 'IUOP_QUERY_TRACK', [ 'sfWaybillNo' => self::$sf_waybill ] );
			$this->fail( '應拋例外' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( '500', $e->raw_code );
		}
		$this->assertCount( 1, $this->sf_requests( 'token' ) );
		$this->assertCount( 1, $this->sf_requests( 'IUOP_QUERY_TRACK' ) );
	}

	/**
	 * Error：token 端點回 1005 App is not exist → AUTH，訊息不含 appSecret，不寫快取
	 *
	 * @group error
	 */
	public function test_token端點失敗拋AUTH(): void {
		$this->fake_sf( 'token', 'token_app_not_exist' );

		try {
			$this->store()->get();
			$this->fail( '應拋 AUTH' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::AUTH, $e->error_code );
			$this->assertSame( '1005', $e->raw_code );
			$this->assertStringNotContainsString( self::$sf_app_secret, $e->getMessage() );
		}
		$this->assertFalse( \get_transient( SfAccessTokenStore::cache_key( 'test', self::$sf_app_key ) ) );
	}

	/**
	 * Error：token 端點連線失敗 → NETWORK
	 *
	 * @group error
	 */
	public function test_token端點連線失敗拋NETWORK(): void {
		$this->fake_sf( 'token', new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		try {
			$this->store()->get();
			$this->fail( '應拋 NETWORK' );
		} catch ( SfExpressException $e ) {
			$this->assertSame( ErrorCode::NETWORK, $e->error_code );
		}
	}
}
