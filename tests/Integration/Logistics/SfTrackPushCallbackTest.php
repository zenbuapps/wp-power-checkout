<?php
/**
 * 順豐運單軌跡推送 webhook 測試（P6：sf_express）
 *
 * 路由 `POST power-checkout/sf-express/track-push`（無條件註冊，permission __return_true，驗 header sign）。
 * 回應一律 HTTP 200 + 順豐規定格式（skill `tracking.md` §4）：
 *   - 全部失敗：{"code":"1","msg":"原因","data":null}（未設推送金鑰 / 缺 sign / sign 不符 / body 非 JSON array）
 *   - 部分或全部成功：{"code":"0","msg":"成功","data":[{"code":"0|1","msg":…,"trackUniqueId":…,"sfWaybillNo":…}]}
 * 未驗簽的內容一律不信任、不改單。查無運單 / 認不得的 opCode / 缺運單號 → 該筆回 0（重送改變不了結果，避免無止境重送）。
 * 同批無序 → 依 localTm 排序後套用；已處理的 trackUniqueId 冪等；終態（已簽收）不被較舊事件回退。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter SfTrackPush --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Http\SfTrackPushCallback;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Services\SfExpressProvider;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfExpressMetaKeys;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\Logistics\Doubles\SfExpressTestTrait;
use Tests\Integration\TestCase;

/**
 * 順豐軌跡推送
 *
 * @group integration
 * @group logistics
 * @group sf_express
 */
final class SfTrackPushCallbackTest extends TestCase {
	use SfExpressTestTrait;

	/** @var string 路由 */
	private const ROUTE = '/power-checkout/sf-express/track-push';

	/** 每個測試前：啟用順豐 + 註冊路由 */
	protected function configure_dependencies(): void {
		$this->setup_sf_express();
		SfTrackPushCallback::register_hooks();
		$GLOBALS['wp_rest_server'] = null;
		\rest_get_server();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_sf_express();
		$GLOBALS['wp_rest_server'] = null;
		parent::tear_down();
	}

	/**
	 * 已建單的訂單
	 *
	 * @param string $waybill 運單號
	 * @return \WC_Order
	 */
	private function make_shipped_order( string $waybill = '' ): \WC_Order {
		$order = $this->make_sf_order();
		$order->update_meta_data( SfExpressMetaKeys::PROVIDER_ID_KEY, SfExpressProvider::ID );
		$order->update_meta_data( SfExpressMetaKeys::REF_KEY, '' !== $waybill ? $waybill : self::$sf_waybill );
		$order->update_meta_data( SfExpressMetaKeys::UNIFIED_STATE_KEY, ShippingState::PENDING->value );
		$order->save();
		return $order;
	}

	/**
	 * 送推送
	 *
	 * @param string      $body 原始 body
	 * @param string|null $sign sign header（null = 不帶；省略 = 以測試金鑰計算）
	 * @return \WP_REST_Response
	 */
	private function push( string $body, ?string $sign = '__AUTO__' ): \WP_REST_Response {
		$request = new \WP_REST_Request( 'POST', self::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( null !== $sign ) {
			$request->set_header( 'sign', '__AUTO__' === $sign ? $this->sf_push_sign( $body ) : $sign );
		}
		$request->set_body( $body );
		return \rest_do_request( $request );
	}

	/**
	 * 單筆事件 body
	 *
	 * @param string               $op_code opCode
	 * @param string               $time    localTm
	 * @param string               $uid     trackUniqueId
	 * @param array<string, mixed> $extra   額外欄位
	 * @return string
	 */
	private function event_body( string $op_code, string $time, string $uid, array $extra = [] ): string {
		return (string) \wp_json_encode(
			[
				\array_merge(
					[
						'gmt'               => 'GMT+08:00',
						'localTm'           => $time,
						'opCode'            => $op_code,
						'opCodeTranslation' => "op{$op_code}",
						'sfWaybillNo'       => self::$sf_waybill,
						'trackUniqueId'     => $uid,
						'trackRemark'       => 'remark',
					],
					$extra
				),
			],
			JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * @param \WC_Order $order 訂單
	 * @return \WC_Order 重新讀取
	 */
	private function reload( \WC_Order $order ): \WC_Order {
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		return $fresh;
	}

	/**
	 * @param \WC_Order $order 訂單
	 * @return int order note 數
	 */
	private function note_count( \WC_Order $order ): int {
		return \count( \wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
	}

	/**
	 * Smoke：路由無條件註冊（provider 未啟用也存在）
	 *
	 * @group smoke
	 */
	public function test_冒煙_路由無條件註冊(): void {
		ProviderUtils::update_option( SfExpressProvider::ID, [ 'enabled' => 'no' ] );
		$GLOBALS['wp_rest_server'] = null;
		SfTrackPushCallback::register_hooks();

		$routes = \rest_get_server()->get_routes();

		$this->assertArrayHasKey( self::ROUTE, $routes );
	}

	/**
	 * Happy：合法推送（無序兩筆）→ 依時間套用，最終 delivered；回 code 0 + 逐筆 code 0
	 *
	 * @group happy
	 */
	public function test_合法推送更新貨態(): void {
		$order = $this->make_shipped_order();
		$body  = $this->sf_fixture( 'push_batch' );

		$response = $this->push( $body );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( '0', $data['code'] );
		$this->assertCount( 2, $data['data'] );
		$this->assertSame( '0', $data['data'][0]['code'] );
		$this->assertSame( 'uid-80', $data['data'][0]['trackUniqueId'], 'ack 順序對應推送順序' );
		$this->assertSame( self::$sf_waybill, $data['data'][0]['sfWaybillNo'] );
		$this->assertSame( 'uid-30', $data['data'][1]['trackUniqueId'] );

		$order = $this->reload( $order );
		$this->assertSame( ShippingState::DELIVERED->value, $order->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
		$this->assertSame( '80', $order->get_meta( SfExpressMetaKeys::STATUS_KEY ) );
		$this->assertSame( '已簽收', $order->get_meta( SfExpressMetaKeys::STATUS_NAME_KEY ) );
	}

	/**
	 * Security：sign 不符 → 200 + code 1，不改單
	 *
	 * @group security
	 */
	public function test_簽章錯不改單(): void {
		$order = $this->make_shipped_order();
		$body  = $this->event_body( '80', '2026-11-21 09:30:00', 'uid-x' );

		$response = $this->push( $body, $this->sf_push_sign( $body, 'attacker-key' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_data()['code'] );
		$this->assertNull( $response->get_data()['data'] );
		$this->assertSame( ShippingState::PENDING->value, $this->reload( $order )->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
	}

	/**
	 * Security：缺 sign header → 200 + code 1，不改單
	 *
	 * @group security
	 */
	public function test_缺簽章不改單(): void {
		$order = $this->make_shipped_order();

		$response = $this->push( $this->event_body( '80', '2026-11-21 09:30:00', 'uid-x' ), null );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_data()['code'] );
		$this->assertSame( ShippingState::PENDING->value, $this->reload( $order )->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
	}

	/**
	 * Security：未設定推送金鑰 → 整批拒收（不因金鑰空而放行），不改單
	 *
	 * @group security
	 */
	public function test_未設金鑰整批拒收(): void {
		$this->setup_sf_express( [ 'push_sign_key' => '' ] );
		$order = $this->make_shipped_order();
		$body  = $this->event_body( '80', '2026-11-21 09:30:00', 'uid-x' );

		$response = $this->push( $body, $this->sf_push_sign( $body, '' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_data()['code'] );
		$this->assertSame( ShippingState::PENDING->value, $this->reload( $order )->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
	}

	/**
	 * Edge：body 不是 JSON array → 200 + code 1
	 *
	 * @group edge
	 */
	public function test_body非陣列(): void {
		$response = $this->push( '{"opCode":"80"}' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_data()['code'] );
	}

	/**
	 * Edge：重複推送同一 trackUniqueId → 冪等（不重複寫 note），仍回 code 0
	 *
	 * @group edge
	 */
	public function test_重複推送冪等(): void {
		$order = $this->make_shipped_order();
		$body  = $this->event_body( '50', '2026-11-20 10:00:00', 'uid-50' );

		$this->push( $body );
		$notes_after_first = $this->note_count( $order );
		$response          = $this->push( $body );

		$this->assertSame( '0', $response->get_data()['code'] );
		$this->assertSame( '0', $response->get_data()['data'][0]['code'] );
		$this->assertSame( $notes_after_first, $this->note_count( $order ) );
		$this->assertSame( ShippingState::ACCEPTED->value, $this->reload( $order )->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
	}

	/**
	 * Edge：未知運單號 → 200 no-op（該筆回 0，避免無止境重送）
	 *
	 * @group edge
	 */
	public function test_未知運單號no_op(): void {
		$order = $this->make_shipped_order();
		$body  = $this->event_body( '80', '2026-11-21 09:30:00', 'uid-unknown', [ 'sfWaybillNo' => 'SF0000000000000' ] );

		$response = $this->push( $body );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '0', $response->get_data()['code'] );
		$this->assertSame( '0', $response->get_data()['data'][0]['code'] );
		$this->assertSame( ShippingState::PENDING->value, $this->reload( $order )->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
	}

	/**
	 * Edge：認不得的 opCode → 不更新貨態，該筆回 0
	 *
	 * @group edge
	 */
	public function test_未知opCode不更新(): void {
		$order = $this->make_shipped_order();

		$response = $this->push( $this->event_body( '12345', '2026-11-21 09:30:00', 'uid-unknown-op' ) );

		$this->assertSame( '0', $response->get_data()['data'][0]['code'] );
		$this->assertSame( ShippingState::PENDING->value, $this->reload( $order )->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
	}

	/**
	 * Edge：已簽收（終態）後收到較舊的「裝車」→ 不回退
	 *
	 * @group edge
	 */
	public function test_終態不被較舊事件回退(): void {
		$order = $this->make_shipped_order();
		$this->push( $this->event_body( '80', '2026-11-21 09:30:00', 'uid-80' ) );

		$this->push( $this->event_body( '30', '2026-11-20 18:00:00', 'uid-30' ) );

		$this->assertSame( ShippingState::DELIVERED->value, $this->reload( $order )->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
	}

	/**
	 * Happy：opCode 99 + otherInfo 46（簽收後退回）→ returned（終態後仍允許退回）
	 *
	 * @group happy
	 */
	public function test_簽收後退回(): void {
		$order = $this->make_shipped_order();
		$this->push( $this->event_body( '80', '2026-11-21 09:30:00', 'uid-80' ) );

		$this->push( $this->event_body( '99', '2026-11-22 09:30:00', 'uid-99', [ 'otherInfo' => '46' ] ) );

		$this->assertSame( ShippingState::RETURNED->value, $this->reload( $order )->get_meta( SfExpressMetaKeys::UNIFIED_STATE_KEY ) );
	}
}
