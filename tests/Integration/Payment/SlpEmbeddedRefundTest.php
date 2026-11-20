<?php
/**
 * SLP 嵌入式退款測試（P1）
 *
 * 走真實退款鏈：wc_create_refund(refund_payment=true) → EmbeddedGateway::process_refund（能力檢查）
 * → woocommerce_order_refunded → process_gateway_refund → POST /trade/refund/create（重用既有 ApiClient）。
 * API 失敗 → 共用 fail_gateway_refund()（刪 refund、全額回滾狀態、記錄 _pc_refund_error）。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter SlpEmbeddedRefund --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use Tests\Integration\Payment\Doubles\SlpEmbeddedTestTrait;
use Tests\Integration\TestCase;

/**
 * SLP 嵌入式退款測試
 *
 * @group integration
 * @group payment
 * @group shopline_payment_embedded
 */
final class SlpEmbeddedRefundTest extends TestCase {
	use SlpEmbeddedTestTrait;

	/** @var string|false 原 API_MODE */
	private string|false $original_api_mode = false;

	/** 每個測試前：TWD、導轉式 test 模式、嵌入式啟用 */
	protected function configure_dependencies(): void {
		$this->original_api_mode = \getenv( 'API_MODE' );
		\putenv( 'API_MODE=mock' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		$this->setup_slp_embedded();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_slp_embedded_http();
		if ( false === $this->original_api_mode ) {
			\putenv( 'API_MODE' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		} else {
			\putenv( "API_MODE={$this->original_api_mode}" ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_putenv
		}
		parent::tear_down();
	}

	/**
	 * 已付款嵌入式信用卡訂單（付款詳情 = webhook 認列時寫入的交易物件）
	 *
	 * @param float  $total          金額
	 * @param string $payment_method gateway id
	 * @return \WC_Order
	 */
	private function make_paid_order( float $total = 1000.0, string $payment_method = EmbeddedGateway::ID ): \WC_Order {
		$trade_order_id = 'TRADE_EMB_REFUND_' . \wp_rand( 1000, 9999 );
		$order          = $this->make_slp_embedded_order( $total, 'processing', $trade_order_id, $payment_method );
		( new MetaKeys( $order ) )->update_payment_detail(
			$this->slp_payment_payload( $trade_order_id, (string) $order->get_id(), 'SUCCEEDED', (int) \round( $total * 100 ) )
		);
		return $order;
	}

	/**
	 * 退款成功假回應
	 *
	 * @param \WC_Order $order  訂單
	 * @param float     $amount 金額
	 * @return void
	 */
	private function fake_refund_ok( \WC_Order $order, float $amount ): void {
		$this->fake_slp_http(
			'/trade/refund/create',
			$this->slp_json_response(
				[
					'refundOrderId'    => 'RF_EMB_' . $order->get_id(),
					'referenceOrderId' => "RF_{$order->get_id()}_1",
					'tradeOrderId'     => ( new MetaKeys( $order ) )->get_payment_identity(),
					'status'           => 'SUCCEEDED',
					'amount'           => [
						'value'    => (int) \round( $amount * 100 ),
						'currency' => 'TWD',
					],
				]
			)
		);
	}

	/**
	 * 走真實退款鏈
	 *
	 * @param \WC_Order $order  訂單
	 * @param float     $amount 金額
	 * @return \WC_Order_Refund|\WP_Error
	 */
	private function refund( \WC_Order $order, float $amount ) {
		\WC()->payment_gateways()->init();
		return \wc_create_refund(
			[
				'order_id'       => $order->get_id(),
				'amount'         => $amount,
				'reason'         => '嵌入式退款測試',
				'refund_payment' => true,
			]
		);
	}

	/** @return array<int, string> 打到退款端點的 request body tradeOrderId */
	private function refund_trade_order_ids(): array {
		$ids = [];
		foreach ( $this->slp_api_requests() as $req ) {
			if ( \str_contains( $req['url'], '/trade/refund/create' ) ) {
				$body  = \json_decode( $req['body'], true );
				$ids[] = \is_array( $body ) ? (string) ( $body['tradeOrderId'] ?? '' ) : '';
			}
		}
		return $ids;
	}

	/**
	 * Happy：全額退款成功 → refund 存在、送出正確 tradeOrderId
	 *
	 * @group happy
	 */
	public function test_全額退款成功(): void {
		$order = $this->make_paid_order();
		$this->fake_refund_ok( $order, 1000.0 );

		$refund = $this->refund( $order, 1000.0 );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertNotFalse( \wc_get_order( $refund->get_id() ), '成功時 refund 應保留' );
		$this->assertSame( [ ( new MetaKeys( $order ) )->get_payment_identity() ], $this->refund_trade_order_ids() );
	}

	/**
	 * Happy：部分退款成功 → 訂單維持 processing
	 *
	 * @group happy
	 */
	public function test_部分退款成功(): void {
		$order = $this->make_paid_order();
		$this->fake_refund_ok( $order, 300.0 );

		$refund = $this->refund( $order, 300.0 );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( 1, $this->refund_trade_order_ids() );
	}

	/**
	 * Error：退款 API 失敗 → fail_gateway_refund（refund 刪除、全額回滾 processing、記錄失敗原因）
	 *
	 * @group error
	 */
	public function test_退款API失敗走fail_gateway_refund(): void {
		$order = $this->make_paid_order();
		$this->fake_slp_http(
			'/trade/refund/create',
			$this->slp_json_response(
				[
					'code' => '9999',
					'msg'  => '可退款餘額不足（嵌入式測試）',
				]
			)
		);

		$refund = $this->refund( $order, 1000.0 );

		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );
		$this->assertFalse( \wc_get_order( $refund->get_id() ), 'API 失敗時 refund 應被刪除' );
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$error = $fresh->get_meta( '_pc_refund_error' );
		$this->assertIsArray( $error );
		$this->assertSame( ErrorCode::PROVIDER->value, $error['error_code'] );
		$this->assertStringContainsString( '可退款餘額不足（嵌入式測試）', (string) $error['message'] );
	}

	/**
	 * Edge：嵌入式 handler 不處理其他 gateway 的訂單（is_this_gateway 守衛）
	 *
	 * @group edge
	 */
	public function test_非嵌入式訂單handler不處理(): void {
		$this->block_slp_http();
		$order  = $this->make_paid_order( 1000.0, 'bacs' );
		$refund = \wc_create_refund(
			[
				'order_id' => $order->get_id(),
				'amount'   => 100.0,
				'reason'   => '手動',
			]
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );

		( new EmbeddedGateway() )->process_gateway_refund( $order->get_id(), $refund->get_id() );

		$this->assertSame( [], $this->slp_api_requests() );
	}
}
