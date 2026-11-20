<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\Managers;

use J7\PowerCheckout\Domains\Payment\Linepay\DTOs\LinepaySettingsDTO;
use J7\PowerCheckout\Domains\Payment\Linepay\Http\LinepayApiClient;
use J7\PowerCheckout\Domains\Payment\Linepay\Services\LinepayGateway;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Enums\LinepayConfirmResult;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Enums\LinepayReturnCode;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Exceptions\LinepayApiException;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers\LinepayMetaKeys;
use J7\PowerCheckout\Plugin;

/**
 * LINE Pay 補單（Action Scheduler `pc_linepay_reconcile` + 後台「查詢補單」共用）
 *
 * LINE Pay 沒有 webhook，扣款（confirm）由我方發起：顧客認證後關掉瀏覽器 → confirmUrl 永遠不會被呼叫（R3）。
 * 付款請求成功時排一個單次任務（預設 +300 秒：LINE Pay 付款請求約 20 分鐘失效，補單須趕在失效前；可由 filter `power_checkout_linepay_reconcile_delay` 調整），
 * 執行時**先查付款請求狀態**再決定（未認證就 confirm 會 1169 且整筆作廢，不可拿 confirm 當探針）：
 *  - 0110 已認證未 confirm → confirm（金額取自我方快照）
 *  - 0123 已完成 → 查明細 CAPTURE → 認列（不再 confirm）
 *  - 0000 尚未認證 / 0121 取消逾時 / 0122 失敗 → 只留 note，不改狀態（不自動取消）
 *  - 查詢失敗 → 重排 1 次（+600 秒，attempt=2），仍失敗 → note 放棄
 *  - 訂單已非待付款 / 非 LINE Pay / 無 transactionId → no-op（不打 API）
 *
 * @see zenbu payments/linepay/linepay-reconcile.cron.ts
 */
final class LinepayReconcileJob {

	/** @var string Action Scheduler hook */
	public const HOOK = 'pc_linepay_reconcile';

	/** @var string Action Scheduler group */
	public const GROUP = 'power_checkout';

	/** @var int 預設延遲秒數（付款請求後 5 分鐘；付款請求約 20 分鐘失效） */
	public const DEFAULT_DELAY = 300;

	/** @var int 失敗重排延遲秒數 */
	public const RETRY_DELAY = 600;

	/** @var int 最多執行次數（含首次） */
	private const MAX_ATTEMPTS = 2;

	/** Register hooks @return void */
	public static function register_hooks(): void {
		\add_action( self::HOOK, [ __CLASS__, 'run' ], 10, 2 );
	}

	/**
	 * 付款請求成功後排入補單任務（同一訂單只保留一筆，重試付款會覆蓋舊排程）
	 *
	 * @param int $order_id 訂單 ID
	 * @return void
	 */
	public static function schedule( int $order_id ): void {
		if ( ! \function_exists( 'as_schedule_single_action' ) || ! \function_exists( 'as_unschedule_all_actions' ) ) {
			Plugin::logger( 'LINE Pay：Action Scheduler 不可用，無法排入補單任務', 'warning', [ 'order_id' => $order_id ] );
			return;
		}

		$args = [ 'order_id' => $order_id ];
		\as_unschedule_all_actions( self::HOOK, $args, self::GROUP );
		$delay = (int) \apply_filters( 'power_checkout_linepay_reconcile_delay', self::DEFAULT_DELAY, $order_id );
		\as_schedule_single_action( \time() + \max( 60, $delay ), self::HOOK, $args, self::GROUP );
	}

	/**
	 * Action Scheduler callback（never-throw）
	 *
	 * @param int $order_id 訂單 ID
	 * @param int $attempt  第幾次執行
	 * @return void
	 */
	public static function run( int $order_id, int $attempt = 1 ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! self::should_reconcile( $order ) ) {
			return;
		}

		try {
			self::reconcile( $order, \__( '自動補單', 'power_checkout' ) );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'LINE Pay 自動補單失敗',
				'warning',
				[
					'order_id' => $order_id,
					'attempt'  => $attempt,
					'error'    => $e->getMessage(),
				]
			);

			if ( $attempt < self::MAX_ATTEMPTS && \function_exists( 'as_schedule_single_action' ) ) {
				\as_schedule_single_action(
					\time() + self::RETRY_DELAY,
					self::HOOK,
					[
						'order_id' => $order_id,
						'attempt'  => $attempt + 1,
					],
					self::GROUP
				);
				return;
			}

			$order->add_order_note(
				\sprintf(
					/* translators: %s: 錯誤訊息 */
					\__( '⚠️ LINE Pay 自動補單查詢失敗，已放棄自動重試：%s。請至後台使用「LINE Pay 重新查詢付款狀態（補單）」或 LINE Pay 合作商店中心確認', 'power_checkout' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * 是否需要補單（LINE Pay 訂單、仍待付款、已有 transactionId）
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	public static function should_reconcile( \WC_Order $order ): bool {
		return LinepayGateway::ID === $order->get_payment_method()
		&& ! StatusManager::is_settled( $order )
		&& $order->has_status( [ 'pending', 'failed', 'on-hold' ] )
		&& '' !== ( new LinepayMetaKeys( $order ) )->get_transaction_id();
	}

	/**
	 * 查付款請求狀態後依結果認列
	 *
	 * @param \WC_Order $order  訂單
	 * @param string    $source 來源（order note 用）
	 * @return LinepayConfirmResult|null 未認列動作（尚未認證 / 取消 / 失敗）回 null
	 * @throws LinepayApiException 查詢 / confirm 傳輸失敗或狀態碼無法辨識
	 */
	public static function reconcile( \WC_Order $order, string $source ): ?LinepayConfirmResult {
		$client  = new LinepayApiClient( LinepaySettingsDTO::instance() );
		$manager = new StatusManager( $order, $client );
		$tx      = ( new LinepayMetaKeys( $order ) )->get_transaction_id();
		$check   = $client->check_request_status( $tx );
		$code    = LinepayApiClient::return_code( $check );

		switch ( LinepayReturnCode::tryFrom( $code ) ) {
			case LinepayReturnCode::AUTHENTICATED:
				return $manager->confirm( $source );
			case LinepayReturnCode::COMPLETED:
				return $manager->settle_from_details( $source );
			case LinepayReturnCode::SUCCESS:
			case LinepayReturnCode::CANCELLED:
			case LinepayReturnCode::FAILED:
				$order->add_order_note(
					\sprintf(
						/* translators: 1: 來源 2: returnCode 3: 訊息 4: transactionId */
						\__( 'LINE Pay %1$s：付款請求狀態 %2$s（%3$s），訂單狀態未變更。transactionId %4$s', 'power_checkout' ),
						$source,
						$code,
						self::status_label( $code ),
						$tx
					)
				);
				return null;
			default:
				throw new LinepayApiException(
					"LINE Pay 查詢付款請求狀態失敗：{$code} " . LinepayApiClient::return_message( $check ),
					LinepayReturnCode::error_code_for( $code ),
					$code
				);
		}
	}

	/**
	 * 狀態查詢碼說明
	 *
	 * @param string $code returnCode
	 * @return string
	 */
	private static function status_label( string $code ): string {
		return match ( $code ) {
			LinepayReturnCode::SUCCESS->value => \__( '顧客尚未完成 LINE Pay 認證', 'power_checkout' ),
			LinepayReturnCode::CANCELLED->value => \__( '顧客取消付款或認證逾時', 'power_checkout' ),
			LinepayReturnCode::FAILED->value => \__( '付款失敗', 'power_checkout' ),
			default => $code,
		};
	}
}
