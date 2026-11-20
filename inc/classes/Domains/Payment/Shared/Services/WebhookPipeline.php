<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Services;

use J7\PowerCheckout\Domains\Payment\Shared\Enums\WebhookOutcome;
use J7\PowerCheckout\Plugin;

/**
 * 統一 webhook 外框（純流程，無業務邏輯）
 *
 * 驗簽 → 找單 → 冪等鍵 → handler → audit log → ack。
 *
 * 規則：
 *  - 任何分支都回 ack（由 gateway 的 callback_ack_response 決定內容，預設 200 JSON），絕不拋例外。
 *  - 冪等鍵存在訂單 meta `_pc_callback_processed`（陣列，只保留最新 50 筆）；
 *    handler 拋例外時**不寫入**冪等鍵，讓金流重送時可重試。
 *  - audit log 只記 provider / 結果 / 冪等鍵 / 耗時，**不記 body 原文**。
 *
 * 由 AbstractPaymentGateway::handle_webhook() 以 first-class callable 注入各掛鉤建立。
 * 既有 7 個 gateway 的 webhook 不遷移到本外框（計劃「限制條件」）。
 */
final class WebhookPipeline {

	/** @var string 已處理冪等鍵的訂單 meta key */
	public const PROCESSED_META_KEY = '_pc_callback_processed';

	/** @var int 冪等鍵保留筆數上限 */
	public const MAX_PROCESSED_KEYS = 50;

	/**
	 * Constructor
	 *
	 * @param string   $provider_id gateway id（log 用）
	 * @param \Closure $verify      驗簽
	 * @param \Closure $resolve     找單
	 * @param \Closure $key         冪等鍵
	 * @param \Closure $process     業務處理
	 * @param \Closure $ack         ack 回應
	 *
	 * @phpstan-param \Closure(\WP_REST_Request): bool $verify
	 * @phpstan-param \Closure(\WP_REST_Request): (\WC_Order|null) $resolve
	 * @phpstan-param \Closure(\WP_REST_Request, \WC_Order): string $key
	 * @phpstan-param \Closure(\WP_REST_Request, \WC_Order): void $process
	 * @phpstan-param \Closure(\WP_REST_Request, WebhookOutcome): \WP_REST_Response $ack
	 */
	public function __construct(
		private readonly string $provider_id,
		private readonly \Closure $verify,
		private readonly \Closure $resolve,
		private readonly \Closure $key,
		private readonly \Closure $process,
		private readonly \Closure $ack,
	) {
	}

	/**
	 * 執行完整流程，永遠回 ack
	 *
	 * @param \WP_REST_Request $request 回呼請求
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$started = \microtime( true );
		$key     = '';
		$outcome = $this->run( $request, $key );

		$this->audit( $outcome, $key, $started );

		return $this->acknowledge( $request, $outcome );
	}

	/**
	 * 預設 ack：HTTP 200 JSON
	 *
	 * @return \WP_REST_Response
	 */
	public static function default_ack(): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => 'OK',
				'data'    => null,
			],
			200
		);
	}

	/**
	 * 驗簽 → 找單 → 冪等 → handler
	 *
	 * @param \WP_REST_Request $request 回呼請求
	 * @param string           $key     冪等鍵（傳址回填，供 audit log）
	 * @return WebhookOutcome
	 */
	private function run( \WP_REST_Request $request, string &$key ): WebhookOutcome {
		if ( ! $this->is_verified( $request ) ) {
			return WebhookOutcome::SIGNATURE_INVALID;
		}

		try {
			$order = ( $this->resolve )( $request );
			if ( ! $order instanceof \WC_Order ) {
				return WebhookOutcome::NOT_FOUND;
			}

			$key = ( $this->key )( $request, $order );
			if ( $this->is_processed( $order, $key ) ) {
				return WebhookOutcome::DUPLICATE;
			}

			( $this->process )( $request, $order );
			$this->mark_processed( $order, $key );

			return WebhookOutcome::PROCESSED;
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"[webhook] {$this->provider_id} " . WebhookOutcome::HANDLER_ERROR->value . ' handler 例外：' . $e::class,
				'error',
				[
					'provider' => $this->provider_id,
					'key'      => $key,
					'error'    => $e->getMessage(),
				]
			);
			return WebhookOutcome::HANDLER_ERROR;
		}
	}

	/**
	 * 驗簽（驗簽本身拋例外視同失敗，fail-closed）
	 *
	 * @param \WP_REST_Request $request 回呼請求
	 * @return bool
	 */
	private function is_verified( \WP_REST_Request $request ): bool {
		try {
			return true === ( $this->verify )( $request );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * 冪等鍵是否已處理（空鍵視為未處理且不記錄）
	 *
	 * @param \WC_Order $order 訂單
	 * @param string    $key   冪等鍵
	 * @return bool
	 */
	private function is_processed( \WC_Order $order, string $key ): bool {
		return '' !== $key && \in_array( $key, self::get_processed_keys( self::reload( $order ) ), true );
	}

	/**
	 * 寫入冪等鍵（只保留最新 MAX_PROCESSED_KEYS 筆）
	 *
	 * 重新讀取訂單再寫，避免以過期實例覆蓋 handler 以其他實例寫入的資料。
	 *
	 * @param \WC_Order $order 訂單
	 * @param string    $key   冪等鍵
	 * @return void
	 */
	private function mark_processed( \WC_Order $order, string $key ): void {
		if ( '' === $key ) {
			return;
		}

		$fresh  = self::reload( $order );
		$keys   = self::get_processed_keys( $fresh );
		$keys[] = $key;
		$fresh->update_meta_data( self::PROCESSED_META_KEY, \array_slice( $keys, -self::MAX_PROCESSED_KEYS ) );
		$fresh->save();
	}

	/**
	 * 重新讀取訂單（找單掛鉤回傳的實例可能已過期）
	 *
	 * @param \WC_Order $order 訂單
	 * @return \WC_Order
	 */
	private static function reload( \WC_Order $order ): \WC_Order {
		$fresh = \wc_get_order( $order->get_id() );
		return $fresh instanceof \WC_Order ? $fresh : $order;
	}

	/**
	 * 取得已處理冪等鍵
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<int, string>
	 */
	private static function get_processed_keys( \WC_Order $order ): array {
		$keys = $order->get_meta( self::PROCESSED_META_KEY );
		if ( ! \is_array( $keys ) ) {
			return [];
		}
		return \array_values( \array_map( static fn( mixed $value ): string => \is_scalar( $value ) ? (string) $value : '', $keys ) );
	}

	/**
	 * Audit log（不記 body 原文）
	 *
	 * @param WebhookOutcome $outcome 結果
	 * @param string         $key     冪等鍵
	 * @param float          $started 開始時間（microtime）
	 * @return void
	 */
	private function audit( WebhookOutcome $outcome, string $key, float $started ): void {
		$level = match ( $outcome ) {
			WebhookOutcome::PROCESSED, WebhookOutcome::DUPLICATE => 'info',
			default => 'warning',
		};

		Plugin::logger(
			"[webhook] {$this->provider_id} {$outcome->value}",
			$level,
			[
				'provider'    => $this->provider_id,
				'outcome'     => $outcome->value,
				'key'         => $key,
				'duration_ms' => (int) \round( ( \microtime( true ) - $started ) * 1000 ),
			]
		);
	}

	/**
	 * 取得 ack（gateway 覆寫的 ack 拋例外時退回預設 200）
	 *
	 * @param \WP_REST_Request $request 回呼請求
	 * @param WebhookOutcome   $outcome 結果
	 * @return \WP_REST_Response
	 */
	private function acknowledge( \WP_REST_Request $request, WebhookOutcome $outcome ): \WP_REST_Response {
		try {
			return ( $this->ack )( $request, $outcome );
		} catch ( \Throwable $e ) {
			Plugin::logger( "[webhook] {$this->provider_id} ack 例外，改回預設 200：" . $e::class, 'error' );
			return self::default_ack();
		}
	}
}
