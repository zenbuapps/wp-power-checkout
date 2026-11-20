<?php
/**
 * SLP 嵌入式 webhook 測試（P1；ASM-3 共用既有 /power-checkout/slp/webhook）
 *
 * 藍圖 §9 修正 2：只開嵌入式（導轉式停用）時，EmbeddedGateway::init() 也必須註冊 SLP webhook 路由。
 * 驗簽 signKey 讀導轉式設定（RedirectSettingsDTO），嵌入式共用。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter SlpEmbeddedWebhook --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\ProviderRegister;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\WebHook;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Plugin;
use Tests\Integration\Payment\Doubles\SlpEmbeddedTestTrait;
use Tests\Integration\TestCase;

/**
 * SLP 嵌入式 webhook 測試
 *
 * @group integration
 * @group payment
 * @group shopline_payment_embedded
 */
final class SlpEmbeddedWebhookTest extends TestCase {
	use SlpEmbeddedTestTrait;

	/** @var string SLP webhook 路由 */
	private const WEBHOOK_ROUTE = '/power-checkout/slp/webhook';

	/** @var string|null 原始環境 */
	private ?string $original_env = null;

	/** 每個測試前：導轉式停用（但存 signKey）、嵌入式啟用；env=production 以實際驗簽 */
	protected function configure_dependencies(): void {
		$this->original_env = Plugin::$env;
		Plugin::$env        = 'production';
		$this->setup_slp_embedded();
		$this->block_slp_http();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_slp_embedded_http();
		if ( null !== $this->original_env ) {
			Plugin::$env = $this->original_env;
		}
		parent::tear_down();
	}

	/**
	 * 組出已簽章的 webhook request（以導轉式 signKey 簽）
	 *
	 * @param array<string, mixed> $data 事件 data
	 * @param string               $type 事件類型
	 * @return \WP_REST_Request<array<string, mixed>>
	 */
	private function signed_request( array $data, string $type = 'trade.succeeded' ): \WP_REST_Request {
		$json      = (string) \wp_json_encode(
			[
				'id'      => 'EVT_EMB_' . $data['tradeOrderId'],
				'type'    => $type,
				'created' => \time(),
				'data'    => $data,
			]
		);
		$timestamp = (string) ( \time() * 1000 );

		$request = new \WP_REST_Request( 'POST', self::WEBHOOK_ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_header( 'timestamp', $timestamp );
		$request->set_header( 'sign', \hash_hmac( 'sha256', "{$timestamp}.{$json}", self::$slp_sign_key ) );
		$request->set_body( $json );
		return $request;
	}

	/**
	 * 冒煙：導轉式停用、嵌入式啟用，ProviderRegister 初始化後 SLP webhook 路由存在
	 *
	 * @group smoke
	 */
	public function test_冒煙_只開嵌入式時webhook路由存在(): void {
		global $wp_rest_server;

		// 重置 WebHook singleton 與 REST server，排除其他測試殘留的路由
		$property = new \ReflectionProperty( WebHook::class, 'instance' );
		$property->setAccessible( true );
		$property->setValue( null, null );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		ProviderRegister::register_hooks();

		$this->assertArrayHasKey( self::WEBHOOK_ROUTE, \rest_get_server()->get_routes() );
		$wp_rest_server = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	/**
	 * Happy：導轉式停用時，嵌入式訂單 trade.succeeded → processing + transaction id
	 *
	 * @group happy
	 */
	public function test_導轉式停用時嵌入式訂單trade_succeeded轉processing(): void {
		$trade_order_id = 'TRADE_EMB_WH_OK';
		$order          = $this->make_slp_embedded_order( 1000.0, 'pending', $trade_order_id );

		$response = WebHook::instance()->post_webhook_callback(
			$this->signed_request( $this->slp_payment_payload( $trade_order_id, (string) $order->get_id() ) )
		);

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( $trade_order_id, $fresh->get_transaction_id() );
	}

	/**
	 * Security：trade.succeeded 指向 bacs 訂單 → 不處理
	 *
	 * @group security
	 */
	public function test_trade_succeeded指向bacs訂單時不處理(): void {
		$trade_order_id = 'TRADE_EMB_WH_BACS';
		$order          = $this->make_slp_embedded_order( 1000.0, 'pending', $trade_order_id, 'bacs' );

		$response = WebHook::instance()->post_webhook_callback(
			$this->signed_request( $this->slp_payment_payload( $trade_order_id, (string) $order->get_id() ) )
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Edge：同一事件重送 → 冪等（狀態不變、付款詳情 note 只寫一次）
	 *
	 * @group edge
	 */
	public function test_重複事件冪等(): void {
		$trade_order_id = 'TRADE_EMB_WH_DUP';
		$order          = $this->make_slp_embedded_order( 1000.0, 'pending', $trade_order_id );
		$payload        = $this->slp_payment_payload( $trade_order_id, (string) $order->get_id() );

		WebHook::instance()->post_webhook_callback( $this->signed_request( $payload ) );
		$notes_after_first = \count( \wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
		$second            = WebHook::instance()->post_webhook_callback( $this->signed_request( $payload ) );

		$this->assertSame( 200, $second->get_status() );
		$this->assertSame( 'processing', \wc_get_order( $order->get_id() )->get_status() );
		$this->assertCount( $notes_after_first, \wc_get_order_notes( [ 'order_id' => $order->get_id() ] ) );
	}

	/**
	 * Error：通知金額與訂單不符 → 不轉 processing（既有金額守衛）
	 *
	 * @group error
	 */
	public function test_金額不符不轉processing(): void {
		$trade_order_id = 'TRADE_EMB_WH_AMOUNT';
		$order          = $this->make_slp_embedded_order( 1000.0, 'pending', $trade_order_id );

		WebHook::instance()->post_webhook_callback(
			$this->signed_request( $this->slp_payment_payload( $trade_order_id, (string) $order->get_id(), 'SUCCEEDED', 100 ) )
		);

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Security：簽章不符 → 401 且不處理
	 *
	 * @group security
	 */
	public function test_簽章不符回401(): void {
		$trade_order_id = 'TRADE_EMB_WH_BADSIGN';
		$order          = $this->make_slp_embedded_order( 1000.0, 'pending', $trade_order_id );
		$request        = $this->signed_request( $this->slp_payment_payload( $trade_order_id, (string) $order->get_id() ) );
		$request->set_header( 'sign', \str_repeat( '0', 64 ) );

		$response = WebHook::instance()->post_webhook_callback( $request );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}
}
