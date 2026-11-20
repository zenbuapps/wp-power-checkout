<?php
/**
 * WebhookPipeline 統一 webhook 外框測試（P0 統一介面）
 *
 * 流程：verify_callback → resolve_callback_order → 冪等鍵（_pc_callback_processed，保留最新 50 筆）
 *       → process_callback → callback_ack_response；任何分支都回 ack（不拋例外、不回 500）。
 *
 * 以測試替身 WebhookPipelineTestGateway（extends AbstractPaymentGateway）驅動
 * AbstractPaymentGateway::handle_webhook()。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Payment/ --filter WebhookPipeline --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Payment;

use J7\PowerCheckout\Domains\Payment\Shared\Enums\WebhookOutcome;
use J7\PowerCheckout\Domains\Payment\Shared\Services\WebhookPipeline;
use Tests\Integration\TestCase;
use Tests\Integration\Payment\Doubles\WebhookPipelineTestGateway;

/**
 * WebhookPipeline 測試
 *
 * @group integration
 * @group payment
 */
final class WebhookPipelineTest extends TestCase {

	/** @var string body 中的唯一標記（用來斷言 log 不含 body 原文） */
	private const BODY_MARKER = 'PC_BODY_MARKER_7f3a9c';

	/** @var array<int, array{message: string, level: string, context: array<mixed>}> 攔截的 log */
	private array $captured_logs = [];

	/** @var WebhookPipelineTestGateway 測試替身 */
	private WebhookPipelineTestGateway $gateway;

	/** 每個測試前重置替身狀態並攔截 log */
	public function set_up(): void {
		parent::set_up();
		WebhookPipelineTestGateway::reset();
		$this->gateway       = new WebhookPipelineTestGateway();
		$this->captured_logs = [];

		\add_filter(
			'woocommerce_logger_log_message',
			function ( $message, $level = '', $context = [] ) {
				$this->captured_logs[] = [
					'message' => (string) $message,
					'level'   => (string) $level,
					'context' => \is_array( $context ) ? $context : [],
				];
				return $message;
			},
			10,
			3
		);
	}

	/**
	 * 建立請求
	 *
	 * @return \WP_REST_Request
	 */
	private function make_request(): \WP_REST_Request {
		$request = new \WP_REST_Request( 'POST', '/power-checkout/test/webhook' );
		$request->set_body( '{"event":"paid","secret":"' . self::BODY_MARKER . '"}' );
		return $request;
	}

	/**
	 * 建立替身會找到的訂單
	 *
	 * @return \WC_Order
	 */
	private function make_order(): \WC_Order {
		$order                                      = $this->create_wc_order(
			[
				'status'         => 'pending',
				'payment_method' => WebhookPipelineTestGateway::ID,
				'total'          => 100,
			]
		);
		WebhookPipelineTestGateway::$resolved_order = $order;
		return $order;
	}

	/**
	 * 取得訂單已處理的冪等鍵
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<int, string>
	 */
	private function processed_keys( \WC_Order $order ): array {
		$fresh = \wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $fresh );
		$keys = $fresh->get_meta( WebhookPipeline::PROCESSED_META_KEY );
		return \is_array( $keys ) ? \array_values( $keys ) : [];
	}

	/**
	 * 斷言 log 含指定文字
	 *
	 * @param string $needle 文字
	 */
	private function assert_log_contains( string $needle ): void {
		foreach ( $this->captured_logs as $log ) {
			if ( \str_contains( $log['message'], $needle ) ) {
				$this->assertTrue( true );
				return;
			}
		}
		$this->fail( "找不到含 {$needle} 的 log：" . \wp_json_encode( \array_column( $this->captured_logs, 'message' ), JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Happy：verify=true + 找到單 + 新 key → handler 呼叫 1 次、key 寫入 meta、回 200
	 *
	 * @group happy
	 */
	public function test_驗簽通過找到單新key_handler呼叫一次並寫入key(): void {
		$order = $this->make_order();

		$response = $this->gateway->handle_webhook( $this->make_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, WebhookPipelineTestGateway::$process_calls );
		$this->assertSame( [ 'evt-1' ], $this->processed_keys( $order ) );
		$this->assertSame( WebhookOutcome::PROCESSED, WebhookPipelineTestGateway::$last_outcome );
	}

	/**
	 * Edge：同 key 重送 → handler 不再呼叫、仍回 200
	 *
	 * @group edge
	 */
	public function test_同key重送不重複處理仍回200(): void {
		$order = $this->make_order();

		$this->gateway->handle_webhook( $this->make_request() );
		$response = $this->gateway->handle_webhook( $this->make_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, WebhookPipelineTestGateway::$process_calls );
		$this->assertSame( [ 'evt-1' ], $this->processed_keys( $order ) );
		$this->assertSame( WebhookOutcome::DUPLICATE, WebhookPipelineTestGateway::$last_outcome );
	}

	/**
	 * Edge：已處理 key 超過 50 筆時只保留最新 50 筆
	 *
	 * @group edge
	 */
	public function test_已處理key超過50筆只保留最新50(): void {
		$order    = $this->make_order();
		$existing = [];
		for ( $i = 1; $i <= 50; $i++ ) {
			$existing[] = "old-{$i}";
		}
		$order->update_meta_data( WebhookPipeline::PROCESSED_META_KEY, $existing );
		$order->save();

		WebhookPipelineTestGateway::$key = 'new-51';
		$this->gateway->handle_webhook( $this->make_request() );

		$keys = $this->processed_keys( $order );
		$this->assertCount( 50, $keys );
		$this->assertSame( 'old-2', $keys[0] );
		$this->assertSame( 'new-51', $keys[49] );
		$this->assertNotContains( 'old-1', $keys );
	}

	/**
	 * Security：verify=false → handler 不呼叫、回 200、log 含 SIGNATURE
	 *
	 * @group security
	 */
	public function test_驗簽失敗不處理回200且log含SIGNATURE(): void {
		$order                                = $this->make_order();
		WebhookPipelineTestGateway::$verified = false;

		$response = $this->gateway->handle_webhook( $this->make_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, WebhookPipelineTestGateway::$process_calls );
		$this->assertSame( [], $this->processed_keys( $order ) );
		$this->assertSame( WebhookOutcome::SIGNATURE_INVALID, WebhookPipelineTestGateway::$last_outcome );
		$this->assert_log_contains( 'SIGNATURE' );
	}

	/**
	 * Error：handler 拋例外 → 回 200、log error、key 不寫入（允許重送重試）
	 *
	 * @group error
	 */
	public function test_handler拋例外回200且key不寫入(): void {
		$order                             = $this->make_order();
		WebhookPipelineTestGateway::$throw = new \RuntimeException( 'handler boom' );

		$response = $this->gateway->handle_webhook( $this->make_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, WebhookPipelineTestGateway::$process_calls );
		$this->assertSame( [], $this->processed_keys( $order ) );
		$this->assertSame( WebhookOutcome::HANDLER_ERROR, WebhookPipelineTestGateway::$last_outcome );

		$has_error = false;
		foreach ( $this->captured_logs as $log ) {
			if ( 'error' === $log['level'] ) {
				$has_error = true;
			}
		}
		$this->assertTrue( $has_error, 'handler 例外須記 error 級 log' );
	}

	/**
	 * Error：找不到單 → 回 200、log NOT_FOUND
	 *
	 * @group error
	 */
	public function test_找不到訂單回200且log含NOT_FOUND(): void {
		WebhookPipelineTestGateway::$resolved_order = null;

		$response = $this->gateway->handle_webhook( $this->make_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, WebhookPipelineTestGateway::$process_calls );
		$this->assertSame( WebhookOutcome::NOT_FOUND, WebhookPipelineTestGateway::$last_outcome );
		$this->assert_log_contains( 'NOT_FOUND' );
	}

	/**
	 * Happy：provider 覆寫 callback_ack_response() 回 text/plain OK 時外框原樣回傳
	 *
	 * @group happy
	 */
	public function test_覆寫ack回應時外框原樣回傳(): void {
		$this->make_order();
		$custom = new \WP_REST_Response( 'OK', 200 );
		$custom->header( 'Content-Type', 'text/plain; charset=utf-8' );
		WebhookPipelineTestGateway::$ack = $custom;

		$response = $this->gateway->handle_webhook( $this->make_request() );

		$this->assertSame( $custom, $response );
		$this->assertSame( 'OK', $response->get_data() );
		$this->assertSame( 'text/plain; charset=utf-8', $response->get_headers()['Content-Type'] );
	}

	/**
	 * Security：log 不含 body 原文（只記 provider / key / 結果 / 耗時）
	 *
	 * @group security
	 */
	public function test_log不含body原文(): void {
		$this->make_order();
		WebhookPipelineTestGateway::$throw = new \RuntimeException( 'handler boom' );

		$this->gateway->handle_webhook( $this->make_request() );
		WebhookPipelineTestGateway::$verified = false;
		$this->gateway->handle_webhook( $this->make_request() );

		$this->assertNotEmpty( $this->captured_logs );
		foreach ( $this->captured_logs as $log ) {
			$this->assertStringNotContainsString( self::BODY_MARKER, $log['message'] );
			$this->assertStringNotContainsString( self::BODY_MARKER, (string) \wp_json_encode( $log['context'] ) );
		}
	}

	/**
	 * Error：verify_callback 本身拋例外 → 仍回 200、不處理
	 *
	 * @group error
	 */
	public function test_驗簽拋例外仍回200不處理(): void {
		$this->make_order();
		WebhookPipelineTestGateway::$verify_throw = new \RuntimeException( 'verify boom' );

		$response = $this->gateway->handle_webhook( $this->make_request() );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 0, WebhookPipelineTestGateway::$process_calls );
		$this->assertSame( WebhookOutcome::SIGNATURE_INVALID, WebhookPipelineTestGateway::$last_outcome );
	}
}
