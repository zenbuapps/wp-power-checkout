<?php

declare( strict_types=1 );

namespace Tests\Integration\Payment\Doubles;

use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\WebhookOutcome;

/**
 * 測試替身：可控的 webhook gateway
 */
final class WebhookPipelineTestGateway extends AbstractPaymentGateway {

	/** @var string gateway ID */
	const ID = 'pc_test_webhook_pipeline_gateway';

	/** @var bool verify 結果 */
	public static bool $verified = true;

	/** @var \Throwable|null verify 時拋出的例外 */
	public static ?\Throwable $verify_throw = null;

	/** @var \WC_Order|null resolve 回傳的訂單 */
	public static ?\WC_Order $resolved_order = null;

	/** @var string 冪等鍵 */
	public static string $key = 'evt-1';

	/** @var \Throwable|null process 時拋出的例外 */
	public static ?\Throwable $throw = null;

	/** @var int process 呼叫次數 */
	public static int $process_calls = 0;

	/** @var \WP_REST_Response|null 覆寫的 ack 回應 */
	public static ?\WP_REST_Response $ack = null;

	/** @var WebhookOutcome|null 最後一次 ack 收到的結果 */
	public static ?WebhookOutcome $last_outcome = null;

	/** @var string gateway ID（WC_Payment_Gateway 屬性） */
	public $id = self::ID;

	/** 重置所有靜態狀態 */
	public static function reset(): void {
		self::$verified       = true;
		self::$verify_throw   = null;
		self::$resolved_order = null;
		self::$key            = 'evt-1';
		self::$throw          = null;
		self::$process_calls  = 0;
		self::$ack            = null;
		self::$last_outcome   = null;
	}

	/**
	 * 最小設定
	 *
	 * @param bool $with_default 是否帶預設值
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		return [
			'enabled' => 'yes',
			'title'   => '測試 webhook 替身',
		];
	}

	/**
	 * 驗簽
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return bool
	 * @throws \Throwable 測試注入的例外
	 */
	public function verify_callback( \WP_REST_Request $request ): bool {
		if ( null !== self::$verify_throw ) {
			throw self::$verify_throw;
		}
		return self::$verified;
	}

	/**
	 * 找單
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WC_Order|null
	 */
	protected function resolve_callback_order( \WP_REST_Request $request ): ?\WC_Order {
		return self::$resolved_order;
	}

	/**
	 * 冪等鍵
	 *
	 * @param \WP_REST_Request $request 請求
	 * @param \WC_Order        $order   訂單
	 * @return string
	 */
	protected function get_callback_idempotency_key( \WP_REST_Request $request, \WC_Order $order ): string {
		return self::$key;
	}

	/**
	 * 業務處理
	 *
	 * @param \WP_REST_Request $request 請求
	 * @param \WC_Order        $order   訂單
	 * @return void
	 * @throws \Throwable 測試注入的例外
	 */
	protected function process_callback( \WP_REST_Request $request, \WC_Order $order ): void {
		++self::$process_calls;
		if ( null !== self::$throw ) {
			throw self::$throw;
		}
	}

	/**
	 * ack 回應
	 *
	 * @param \WP_REST_Request $request 請求
	 * @param WebhookOutcome   $outcome 處理結果
	 * @return \WP_REST_Response
	 */
	protected function callback_ack_response( \WP_REST_Request $request, WebhookOutcome $outcome ): \WP_REST_Response {
		self::$last_outcome = $outcome;
		return self::$ack ?? parent::callback_ack_response( $request, $outcome );
	}
}
