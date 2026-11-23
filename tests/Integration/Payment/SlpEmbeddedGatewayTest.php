<?php
/**
 * SLP 嵌入式 gateway 測試（P1：shopline_payment_embedded）
 *
 * 涵蓋：
 *  - 註冊於 woocommerce_payment_gateways、provider meta（client-sdk；refund / partial_refund / query）
 *  - process_payment：只寫 pending meta、回 order-received URL、不打 API
 *  - is_available：非 TWD → false
 *  - order-received 回訪：已有 tradeOrderId → 以伺服器端 identity 主動查詢一次並認列（不信任 $_GET）
 *  - order-received 未建立交易 → localize SDK 掛載資料（官方 CDN v1）
 *  - verify_callback：以導轉式 signKey 驗 HMAC-SHA256（ASM-3 共用既有 webhook）
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter SlpEmbeddedGateway --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Enums\PaymentMode;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IEmbeddedPaymentProvider;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\EmbeddedGateway;
use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\PowerCheckout\Plugin;
use Tests\Integration\Payment\Doubles\SlpEmbeddedTestTrait;
use Tests\Integration\TestCase;

/**
 * SLP 嵌入式 gateway 測試
 *
 * @group integration
 * @group payment
 * @group shopline_payment_embedded
 */
final class SlpEmbeddedGatewayTest extends TestCase {
	use SlpEmbeddedTestTrait;

	/** @var string localize 物件名稱 */
	private const LOCALIZE_NAME = 'power_checkout_slp_embedded_data';

	/** @var string|null 原始環境 */
	private ?string $original_env = null;

	/** 每個測試前：TWD、導轉式停用 test 模式、嵌入式啟用 */
	protected function configure_dependencies(): void {
		$this->original_env = Plugin::$env;
		$this->setup_slp_embedded();
	}

	/** 清理 */
	public function tear_down(): void {
		$this->reset_slp_embedded_http();
		unset( $_GET['key'], $_GET['tradeOrderId'] );
		if ( null !== $this->original_env ) {
			Plugin::$env = $this->original_env;
		}
		\wp_deregister_script( SettingTabService::$handle );
		parent::tear_down();
	}

	/**
	 * 以 Reflection 呼叫 protected before_order_received
	 *
	 * @param EmbeddedGateway $gateway gateway
	 * @param \WC_Order       $order   訂單
	 * @return void
	 */
	private function invoke_before_order_received( EmbeddedGateway $gateway, \WC_Order $order ): void {
		$_GET['key'] = $order->get_order_key();
		$method      = new \ReflectionMethod( $gateway, 'before_order_received' );
		$method->setAccessible( true );
		$method->invoke( $gateway, $order );
	}

	/** @return string localize 到 Vue bundle handle 的 inline data（不存在回空字串） */
	private function localized_data(): string {
		$data = \wp_scripts()->get_data( SettingTabService::$handle, 'data' );
		return \is_string( $data ) ? $data : '';
	}

	/**
	 * 冒煙：已註冊於 woocommerce_payment_gateways
	 *
	 * @group smoke
	 */
	public function test_冒煙_已註冊於woocommerce_payment_gateways(): void {
		$methods = \apply_filters( 'woocommerce_payment_gateways', [] );
		$this->assertContains( EmbeddedGateway::class, $methods );

		$gateways = \WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( EmbeddedGateway::ID, $gateways );
	}

	/**
	 * 冒煙：實作 IEmbeddedPaymentProvider；provider meta = client-sdk + refund / partial_refund / query
	 *
	 * @group smoke
	 */
	public function test_冒煙_provider_meta宣告client_sdk與能力(): void {
		$gateway = new EmbeddedGateway();
		$meta    = $gateway->get_provider_meta();

		$this->assertInstanceOf( IEmbeddedPaymentProvider::class, $gateway );
		$this->assertSame( 'shopline_payment_embedded', $meta->id );
		$this->assertSame( PaymentMode::CLIENT_SDK, $meta->payment_mode );
		$this->assertTrue( $meta->capabilities->refund );
		$this->assertTrue( $meta->capabilities->partial_refund );
		$this->assertTrue( $meta->capabilities->query );
		$this->assertFalse( $meta->capabilities->capture );
		$this->assertFalse( $meta->capabilities->void );
		$this->assertFalse( $meta->capabilities->recurring );
	}

	/**
	 * Happy：process_payment 回 order-received URL、寫 pending meta、不打 API
	 *
	 * @group happy
	 */
	public function test_process_payment回order_received且不打API(): void {
		$this->block_slp_http();
		$order   = $this->make_slp_embedded_order();
		$gateway = new EmbeddedGateway();

		$result = $gateway->process_payment( $order->get_id() );

		$this->assertSame( 'success', $result['result'] );
		$this->assertSame( $gateway->get_return_url( $order ), $result['redirect'] ?? '' );
		$this->assertSame( 'yes', \wc_get_order( $order->get_id() )->get_meta( EmbeddedGateway::PENDING_META_KEY ) );
		$this->assertSame( [], $this->slp_api_requests() );
	}

	/**
	 * Edge：非 TWD → is_available()=false
	 *
	 * @group edge
	 */
	public function test_非TWD時is_available為false(): void {
		\update_option( 'woocommerce_currency', 'USD' );

		$this->assertFalse( ( new EmbeddedGateway() )->is_available() );
	}

	/**
	 * Happy：order-received 回訪，已建立交易 → 以伺服器端 tradeOrderId 主動查詢，SUCCEEDED → processing，且不再 localize SDK
	 *
	 * @group happy
	 */
	public function test_order_received已建交易時主動查詢並認列(): void {
		$trade_order_id = 'TRADE_EMB_SYNC_OK';
		$order          = $this->make_slp_embedded_order( 1000.0, 'pending', $trade_order_id );
		$this->fake_slp_http(
			'/trade/payment/get',
			$this->slp_json_response( $this->slp_payment_payload( $trade_order_id, (string) $order->get_id() ) )
		);

		$this->invoke_before_order_received( new EmbeddedGateway(), $order );

		$fresh = \wc_get_order( $order->get_id() );
		$this->assertSame( 'processing', $fresh->get_status() );
		$this->assertSame( $trade_order_id, $fresh->get_transaction_id() );
		$this->assertCount( 1, $this->slp_api_requests() );
		$this->assertStringNotContainsString( self::LOCALIZE_NAME, $this->localized_data() );
	}

	/**
	 * Security：查詢結果 referenceOrderId 指向別張訂單 → 不認列
	 *
	 * @group security
	 */
	public function test_order_received查詢結果不屬於本訂單時不認列(): void {
		$trade_order_id = 'TRADE_EMB_SYNC_MISMATCH';
		$order          = $this->make_slp_embedded_order( 1000.0, 'pending', $trade_order_id );
		$this->fake_slp_http(
			'/trade/payment/get',
			$this->slp_json_response( $this->slp_payment_payload( $trade_order_id, (string) ( $order->get_id() + 999 ) ) )
		);

		$this->invoke_before_order_received( new EmbeddedGateway(), $order );

		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Security：$_GET['tradeOrderId'] 不被信任——無伺服器端 identity 時不打查詢 API
	 *
	 * @group security
	 */
	public function test_order_received不採用網址帶入的tradeOrderId(): void {
		$this->block_slp_http();
		$order                = $this->make_slp_embedded_order();
		$_GET['tradeOrderId'] = 'TRADE_FROM_ATTACKER';

		$this->invoke_before_order_received( new EmbeddedGateway(), $order );

		$this->assertSame( [], $this->slp_api_requests() );
		$this->assertSame( 'pending', \wc_get_order( $order->get_id() )->get_status() );
	}

	/**
	 * Happy：尚未建立交易 → localize SDK 掛載資料（dispatch URL、order_key、官方 CDN v1）
	 *
	 * @group happy
	 */
	public function test_order_received未建交易時localize_SDK資料(): void {
		$this->block_slp_http();
		$order = $this->make_slp_embedded_order();

		$this->invoke_before_order_received( new EmbeddedGateway(), $order );

		$this->assertSame( 1, \preg_match( '/var ' . self::LOCALIZE_NAME . ' = (\{.*?\});/s', $this->localized_data(), $matches ) );
		$data = \json_decode( $matches[1], true );
		$this->assertIsArray( $data );
		$this->assertSame( $order->get_order_key(), $data['order_key'] );
		$this->assertSame( (string) $order->get_id(), $data['order_id'] );
		$this->assertStringContainsString( "pay/{$order->get_id()}/dispatch", $data['dispatch_url'] );
		$this->assertSame( 'https://cdn.shoplinepayments.com/sdk/v1/payment-web.js', $data['sdk_url'] );
		$this->assertSame( 'no', $data['awaiting_confirmation'] );
		$this->assertSame( [], $this->slp_api_requests() );
	}

	/**
	 * Security：verify_callback 以導轉式 signKey 驗 HMAC-SHA256（timestamp.body）
	 *
	 * @group security
	 */
	public function test_verify_callback驗簽(): void {
		Plugin::$env = 'production';
		$body        = '{"type":"trade.succeeded"}';
		$timestamp   = (string) ( \time() * 1000 );

		$valid = new \WP_REST_Request( 'POST', '/power-checkout/slp/webhook' );
		$valid->set_body( $body );
		$valid->set_header( 'timestamp', $timestamp );
		$valid->set_header( 'sign', \hash_hmac( 'sha256', "{$timestamp}.{$body}", self::$slp_sign_key ) );

		$invalid = new \WP_REST_Request( 'POST', '/power-checkout/slp/webhook' );
		$invalid->set_body( $body );
		$invalid->set_header( 'timestamp', $timestamp );
		$invalid->set_header( 'sign', \hash_hmac( 'sha256', "{$timestamp}.{$body}", 'wrong_key' ) );

		$stale = new \WP_REST_Request( 'POST', '/power-checkout/slp/webhook' );
		$stale->set_body( $body );
		$old = (string) ( ( \time() - 3600 ) * 1000 );
		$stale->set_header( 'timestamp', $old );
		$stale->set_header( 'sign', \hash_hmac( 'sha256', "{$old}.{$body}", self::$slp_sign_key ) );

		$gateway = new EmbeddedGateway();
		$this->assertTrue( $gateway->verify_callback( $valid ) );
		$this->assertFalse( $gateway->verify_callback( $invalid ) );
		$this->assertFalse( $gateway->verify_callback( $stale ) );
	}
}
