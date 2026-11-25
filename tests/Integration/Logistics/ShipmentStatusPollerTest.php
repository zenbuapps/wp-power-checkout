<?php
/**
 * 物流貨態自動查詢（推播收不到時的保底）
 *
 * 以 PAYUNi 物流（mock 查詢固定回 ShipStatus=31 配送中）驗證共用 ShipmentStatusPoller：
 * 建單排程、退避間隔、到終態停止、收到推播取消、關閉設定不排、查詢失敗重試、上限天數。
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Payuni\DTOs\PayuniLogisticsSettingsDTO;
use J7\PowerCheckout\Domains\Logistics\Payuni\Http\PayuniLogisticsCallback;
use J7\PowerCheckout\Domains\Logistics\Payuni\Services\PayuniLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Helpers\PayuniCrypto;
use J7\PowerCheckout\Domains\Logistics\Shared\Helpers\LogisticsMetaKeys;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\LogisticsApiService;
use J7\PowerCheckout\Domains\Logistics\Shared\Services\ShipmentStatusPoller;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use Tests\Integration\TestCase;

/**
 * @group integration
 * @group logistics
 * @group payuni
 */
final class ShipmentStatusPollerTest extends TestCase {

	private const ID = PayuniLogisticsProvider::ID;

	/** 啟用 PAYUNi 物流（mock） */
	public function set_up(): void {
		parent::set_up();
		\putenv( 'API_MODE=mock' );
		$this->enable_payuni();
		ProviderUtils::$container[ self::ID ] = PayuniLogisticsProvider::instance();
	}

	/** 清理 */
	public function tear_down(): void {
		unset( ProviderUtils::$container[ self::ID ] );
		PayuniLogisticsSettingsDTO::reset();
		\delete_option( ProviderUtils::get_option_name( self::ID ) );
		parent::tear_down();
	}

	/** @param array<string, mixed> $extra 額外設定 */
	private function enable_payuni( array $extra = [] ): void {
		$this->enable_provider(
			self::ID,
			\array_merge(
				[
					'mode'            => 'test',
					'mer_id'          => 'S0000000000',
					'hash_key'        => '12345678901234567890123456789012',
					'hash_iv'         => '1234567890123456',
					'cvs_lgs_type'    => 'B2C',
					'enabled_methods' => [ 'SEVEN', 'HOME' ],
				],
				$extra
			)
		);
		PayuniLogisticsSettingsDTO::reset();
	}

	/** @return \WC_Order 已建物流單（有 ShipTradeNo）的訂單 */
	private function create_shipped_order(): \WC_Order {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$meta  = new LogisticsMetaKeys( $order );
		$meta->update_sub_type( 'SEVEN' );
		$meta->update_ref( 'S138TEST' . \wp_rand( 1000, 9999 ) );
		$meta->update_provider_id( self::ID );
		return \wc_get_order( $order->get_id() );
	}

	/** @return int|false 下一次排程時間 */
	private function next_run( \WC_Order $order ) {
		return \as_next_scheduled_action( ShipmentStatusPoller::HOOK, ShipmentStatusPoller::args( $order->get_id(), self::ID ), ShipmentStatusPoller::GROUP );
	}

	// ========== 排程 ==========

	/**
	 * @test
	 * @group smoke
	 */
	public function test_hook已註冊(): void {
		$this->assertNotFalse( \has_action( ShipmentStatusPoller::HOOK, [ ShipmentStatusPoller::class, 'run' ] ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_透過建單REST成功後排第一次查詢_預設5分鐘(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] );
		$order->set_billing_first_name( '小明' );
		$order->set_billing_last_name( '王' );
		$order->set_billing_phone( '0912345678' );
		$order->save();
		$meta = new LogisticsMetaKeys( $order );
		$meta->update_sub_type( 'SEVEN' );
		$meta->update_store_id( '273927' );

		$request = new \WP_REST_Request( 'POST', '/power-checkout/v1/logistics/' . $order->get_id() . '/create-shipment' );
		$request->set_url_params( [ 'id' => $order->get_id() ] );
		$request->set_param( 'id', $order->get_id() );
		$request->set_param( 'provider', self::ID );
		$before   = \time();
		$response = LogisticsApiService::instance()->post_logistics_with_id_create_shipment_callback( $request );

		$this->assertSame( 200, $response->get_status() );
		$next = $this->next_run( $order );
		$this->assertIsInt( $next );
		$this->assertEqualsWithDelta( $before + 300, $next, 60 );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_關閉自動查詢就不排(): void {
		$this->enable_payuni( [ 'status_polling' => 'no' ] );
		$order = $this->create_shipped_order();

		ShipmentStatusPoller::schedule( $order, self::ID );

		$this->assertFalse( $this->next_run( $order ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_查到未終態_寫回訂單並依間隔排下一次(): void {
		$order = $this->create_shipped_order();
		ShipmentStatusPoller::schedule( $order, self::ID );

		$before = \time();
		\do_action( ShipmentStatusPoller::HOOK, $order->get_id(), self::ID );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( '31', ( new LogisticsMetaKeys( $fresh ) )->get_status(), 'mock 查詢 31（配送中）應寫回訂單' );
		$this->assertSame( '1', (string) $fresh->get_meta( ShipmentStatusPoller::ROUND_META ) );
		$this->assertEqualsWithDelta( $before + 30 * 60, $this->next_run( $order ), 60, '第二次間隔 30 分鐘' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_間隔設定_最後一個重複_不合法用預設(): void {
		$this->assertSame( 5 * 60, ShipmentStatusPoller::interval_seconds( self::ID, 0 ) );
		$this->assertSame( 360 * 60, ShipmentStatusPoller::interval_seconds( self::ID, 99 ) );

		$this->enable_payuni( [ 'status_polling_intervals' => '10, 60' ] );
		$this->assertSame( 10 * 60, ShipmentStatusPoller::interval_seconds( self::ID, 0 ) );
		$this->assertSame( 60 * 60, ShipmentStatusPoller::interval_seconds( self::ID, 5 ) );

		$this->enable_payuni( [ 'status_polling_intervals' => 'abc' ] );
		$this->assertSame( ShipmentStatusPoller::DEFAULT_INTERVALS, ShipmentStatusPoller::intervals( self::ID ) );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_到終態就停止(): void {
		$order = $this->create_shipped_order();
		ShipmentStatusPoller::schedule( $order, self::ID );
		\as_unschedule_all_actions( ShipmentStatusPoller::HOOK, ShipmentStatusPoller::args( $order->get_id(), self::ID ), ShipmentStatusPoller::GROUP );

		// mock 查詢回「11 已取貨」
		$filter = static fn(): string => '11';
		\add_filter( 'power_checkout_payuni_logistics_mock_ship_status', $filter );
		\do_action( ShipmentStatusPoller::HOOK, $order->get_id(), self::ID );
		\remove_filter( 'power_checkout_payuni_logistics_mock_ship_status', $filter );

		$this->assertSame( '11', ( new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_status() );
		$this->assertFalse( $this->next_run( $order ), '已取貨是終點，不再排下一次' );

		$this->assertTrue( \J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums\PayuniShipStatus::to_unified_state( '11' )->is_terminal() );
		$this->assertTrue( \J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums\PayuniShipStatus::to_unified_state( '52' )->is_terminal() );
		$this->assertFalse( \J7\PowerCheckout\Domains\Logistics\Payuni\Shared\Enums\PayuniShipStatus::to_unified_state( '81' )->is_terminal(), '門市關轉不是終點' );
	}

	/**
	 * @test
	 * @group happy
	 */
	public function test_收到推播_取消排程且之後不再排(): void {
		$order = $this->create_shipped_order();
		ShipmentStatusPoller::schedule( $order, self::ID );
		$this->assertIsInt( $this->next_run( $order ) );

		// PAYUNi 已驗章的貨態推播
		$crypto  = new PayuniCrypto( '12345678901234567890123456789012', '1234567890123456' );
		$enc     = $crypto->encrypt(
			[
				'Status'      => 'SUCCESS',
				'MerID'       => 'S0000000000',
				'ShipTradeNo' => ( new LogisticsMetaKeys( $order ) )->get_ref(),
				'ShipStatus'  => '32',
				'ApiType'     => 'ShipStatus',
			]
		);
		$request = new \WP_REST_Request( 'POST', '/power-checkout/payuni/logistics/status-notify' );
		$request->set_body_params(
			[
				'MerID'       => 'S0000000000',
				'EncryptInfo' => $enc,
				'HashInfo'    => $crypto->hash_info( $enc ),
			]
		);
		PayuniLogisticsCallback::instance()->post_logistics_status_notify_callback( $request );

		$this->assertFalse( $this->next_run( $order ), '收到推播後應取消輪詢' );

		ShipmentStatusPoller::schedule( \wc_get_order( $order->get_id() ), self::ID );
		$this->assertFalse( $this->next_run( $order ), '收過推播的單不再排' );

		\do_action( ShipmentStatusPoller::HOOK, $order->get_id(), self::ID );
		$this->assertSame( '32', ( new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_status(), '執行到也直接略過，不覆蓋推播的貨態' );
	}

	/**
	 * @test
	 * @group error
	 */
	public function test_查詢失敗會重試_連續5次放棄(): void {
		$order = $this->create_wc_order( [ 'status' => 'processing' ] ); // 沒有 ShipTradeNo → query 拋例外
		( new LogisticsMetaKeys( $order ) )->update_sub_type( 'SEVEN' );
		ShipmentStatusPoller::schedule( \wc_get_order( $order->get_id() ), self::ID );

		for ( $i = 1; $i < ShipmentStatusPoller::MAX_FAILURES; $i++ ) {
			\do_action( ShipmentStatusPoller::HOOK, $order->get_id(), self::ID );
			$this->assertIsInt( $this->next_run( $order ), "第 {$i} 次失敗仍會重試" );
		}
		\as_unschedule_all_actions( ShipmentStatusPoller::HOOK, ShipmentStatusPoller::args( $order->get_id(), self::ID ), ShipmentStatusPoller::GROUP );
		\do_action( ShipmentStatusPoller::HOOK, $order->get_id(), self::ID );

		$this->assertFalse( $this->next_run( $order ), '連續失敗達上限就停' );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_超過上限天數就停(): void {
		$order = $this->create_shipped_order();
		ShipmentStatusPoller::schedule( $order, self::ID );
		\as_unschedule_all_actions( ShipmentStatusPoller::HOOK, ShipmentStatusPoller::args( $order->get_id(), self::ID ), ShipmentStatusPoller::GROUP );

		$fresh = \wc_get_order( $order->get_id() );
		$fresh->update_meta_data( ShipmentStatusPoller::STARTED_META, (string) ( \time() - 31 * 86400 ) );
		$fresh->save_meta_data();

		\do_action( ShipmentStatusPoller::HOOK, $order->get_id(), self::ID );

		$this->assertFalse( $this->next_run( $order ) );
	}

	/**
	 * @test
	 * @group edge
	 */
	public function test_provider停用就不查(): void {
		$order = $this->create_shipped_order();
		ShipmentStatusPoller::schedule( $order, self::ID );
		\as_unschedule_all_actions( ShipmentStatusPoller::HOOK, ShipmentStatusPoller::args( $order->get_id(), self::ID ), ShipmentStatusPoller::GROUP );
		$this->disable_provider( self::ID );

		\do_action( ShipmentStatusPoller::HOOK, $order->get_id(), self::ID );

		$this->assertFalse( $this->next_run( $order ) );
		$this->assertSame( '', ( new LogisticsMetaKeys( \wc_get_order( $order->get_id() ) ) )->get_status() );
	}
}
