<?php
/**
 * 物流貨態自動查詢（推播收不到時的保底，共用給所有物流 provider）
 *
 * 為什麼需要：多數物流平台要商家自己到後台填「貨態通知網址」才會推播；沒填，訂單的物流狀態就永遠停在建單當下。
 *
 * 做法（Action Scheduler，WooCommerce 內建，比 WP-Cron 可靠且看得到排程紀錄）：
 *  1. 建單成功 → {@see self::schedule()} 排第一次查詢（預設 5 分鐘後）。
 *  2. {@see self::run()} 呼叫 provider 的 `query_shipment()`（ILogisticsProvider 共用能力；provider 自己把結果寫回訂單），
 *     依回傳的 `unified_state`（ShippingState）判斷：到終態（已取貨 / 已送達 / 已退回 / 失敗 / 逾期未取 / 取消）就停；
 *     否則依間隔排下一次（預設 5 分 → 30 分 → 2 小時 → 之後每 6 小時，最後一個間隔重複）。
 *  3. 超過上限天數（預設 30 天）、連續查詢失敗 5 次、provider 停用或不支援查詢、查詢結果沒有 unified_state → 停止。
 *  4. 收到該單的推播 → {@see self::mark_push_received()} 取消排程；之後也不再排（推播已經在更新貨態）。
 *
 * 設定（各 provider 的 WC option，沒有就用預設）：`status_polling`（yes/no，預設 yes）、
 * `status_polling_intervals`（分鐘，逗號分隔）、`status_polling_max_days`。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Services;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

/** 物流貨態自動查詢 */
final class ShipmentStatusPoller {

	/** @var string Action Scheduler hook */
	public const HOOK = 'pc_logistics_status_poll';

	/** @var string Action Scheduler group */
	public const GROUP = 'power_checkout';

	/** @var array<int, int> 預設查詢間隔（分鐘；最後一個重複使用） */
	public const DEFAULT_INTERVALS = [ 5, 30, 120, 360 ];

	/** @var int 預設最多查幾天 */
	public const DEFAULT_MAX_DAYS = 30;

	/** @var int 連續查詢失敗幾次就放棄 */
	public const MAX_FAILURES = 5;

	/** @var string 已收到推播的時間（有值就不輪詢） */
	public const PUSH_META = '_pc_logistics_status_push_at';

	/** @var string 第一次排程時間（計算上限天數） */
	public const STARTED_META = '_pc_logistics_poll_started_at';

	/** @var string 已完成的查詢次數（決定下一個間隔） */
	public const ROUND_META = '_pc_logistics_poll_round';

	/** @var string 連續失敗次數 */
	public const FAILURES_META = '_pc_logistics_poll_failures';

	/** Register hooks @return void */
	public static function register_hooks(): void {
		\add_action( self::HOOK, [ __CLASS__, 'run' ], 10, 2 );
	}

	/**
	 * 建單成功後排第一次查詢
	 *
	 * @param \WC_Order $order       訂單
	 * @param string    $provider_id 物流 provider id
	 * @return void
	 */
	public static function schedule( \WC_Order $order, string $provider_id ): void {
		if (!self::is_enabled( $provider_id ) || '' !== (string) $order->get_meta( self::PUSH_META )) {
			return;
		}
		$order->update_meta_data( self::STARTED_META, (string) \time() );
		$order->update_meta_data( self::ROUND_META, '0' );
		$order->update_meta_data( self::FAILURES_META, '0' );
		$order->save_meta_data();

		self::enqueue( $order->get_id(), $provider_id, self::interval_seconds( $provider_id, 0 ) );
	}

	/**
	 * 收到推播：取消該單輪詢，之後也不再排
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function mark_push_received( \WC_Order $order ): void {
		if ('' === (string) $order->get_meta( self::PUSH_META )) {
			$order->update_meta_data( self::PUSH_META, (string) \time() );
			$order->save_meta_data();
		}
		if (\function_exists( 'as_unschedule_all_actions' )) {
			foreach ( self::provider_ids_of( $order ) as $provider_id ) {
				\as_unschedule_all_actions( self::HOOK, self::args( $order->get_id(), $provider_id ), self::GROUP );
			}
		}
	}

	/**
	 * Action Scheduler 執行：查一次貨態，決定停止或排下一次
	 *
	 * 永不拋例外（Action Scheduler 會把例外記成失敗，但我們自己處理重試）。
	 *
	 * @param int    $order_id    訂單 ID
	 * @param string $provider_id 物流 provider id
	 * @return void
	 */
	public static function run( $order_id, $provider_id = '' ): void {
		$order_id    = (int) $order_id;
		$provider_id = (string) $provider_id;
		$order       = \wc_get_order( $order_id );
		if (!$order instanceof \WC_Order) {
			return;
		}

		// 推播已在更新貨態 / 關閉自動查詢 → 停
		if ('' !== (string) $order->get_meta( self::PUSH_META ) || !self::is_enabled( $provider_id )) {
			return;
		}

		$provider = \J7\PowerCheckout\Domains\Logistics\ProviderRegister::resolve_enabled_provider( $provider_id );
		if (!$provider instanceof ILogisticsProvider || !$provider->get_provider_meta()->capabilities->can_query_shipment) {
			return;
		}

		// 超過上限天數 → 停
		$started  = (int) $order->get_meta( self::STARTED_META );
		$max_days = self::max_days( $provider_id );
		if ($started > 0 && \time() - $started > $max_days * 86400) {
			$order->add_order_note( \sprintf( '已自動查詢物流狀態 %d 天仍未送達或退回，停止自動查詢；需要時請手動查詢。', $max_days ) );
			return;
		}

		$round = (int) $order->get_meta( self::ROUND_META );

		try {
			$result = $provider->query_shipment( $order );
		} catch ( \Throwable $e ) {
			$failures = (int) $order->get_meta( self::FAILURES_META ) + 1;
			$order->update_meta_data( self::FAILURES_META, (string) $failures );
			$order->save_meta_data();
			if ($failures >= self::MAX_FAILURES) {
				$order->add_order_note( \sprintf( '自動查詢物流狀態連續失敗 %1$d 次，已停止：%2$s', $failures, $e->getMessage() ) );
				return;
			}
			self::enqueue( $order_id, $provider_id, self::interval_seconds( $provider_id, $round ) );
			return;
		}

		$state = ShippingState::tryFrom( (string) ( $result['unified_state'] ?? '' ) );
		if (null === $state) {
			// 這家 provider 的查詢沒有統一貨態，無法判斷何時該停 → 不再輪詢
			Plugin::logger( "物流自動查詢：{$provider_id} 未回傳 unified_state，停止輪詢", 'info', [ 'order_id' => $order_id ] );
			return;
		}

		$order = \wc_get_order( $order_id ); // provider 可能已更新訂單
		if (!$order instanceof \WC_Order) {
			return;
		}
		$order->update_meta_data( self::FAILURES_META, '0' );
		$order->update_meta_data( self::ROUND_META, (string) ( $round + 1 ) );
		$order->save_meta_data();

		if ($state->is_terminal()) {
			return;
		}

		self::enqueue( $order_id, $provider_id, self::interval_seconds( $provider_id, $round + 1 ) );
	}

	// region 設定

	/**
	 * 是否開啟自動查詢（預設開啟）
	 *
	 * @param string $provider_id provider id
	 * @return bool
	 */
	public static function is_enabled( string $provider_id ): bool {
		if ('' === $provider_id || !ProviderUtils::is_enabled( $provider_id )) {
			return false;
		}
		return 'no' !== (string) ProviderUtils::get_option( $provider_id, 'status_polling' );
	}

	/**
	 * 查詢間隔（分鐘），設定格式「5,30,120,360」；不合法用預設
	 *
	 * @param string $provider_id provider id
	 * @return array<int, int>
	 */
	public static function intervals( string $provider_id ): array {
		$raw  = (string) ProviderUtils::get_option( $provider_id, 'status_polling_intervals' );
		$list = [];
		foreach ( \explode( ',', $raw ) as $part ) {
			$minutes = (int) \trim( $part );
			if ($minutes >= 1) {
				$list[] = $minutes;
			}
		}
		return $list ?: self::DEFAULT_INTERVALS;
	}

	/**
	 * 第 $round 次查詢後要等多久（秒；超出清單就用最後一個）
	 *
	 * @param string $provider_id provider id
	 * @param int    $round       已完成的查詢次數
	 * @return int
	 */
	public static function interval_seconds( string $provider_id, int $round ): int {
		$intervals = self::intervals( $provider_id );
		$minutes   = $intervals[ \min( $round, \count( $intervals ) - 1 ) ];
		return \max( 60, $minutes * 60 );
	}

	/**
	 * 最多自動查詢幾天
	 *
	 * @param string $provider_id provider id
	 * @return int
	 */
	public static function max_days( string $provider_id ): int {
		$days = (int) ProviderUtils::get_option( $provider_id, 'status_polling_max_days' );
		return $days >= 1 ? $days : self::DEFAULT_MAX_DAYS;
	}

	// endregion

	// region helpers

	/**
	 * Action Scheduler args
	 *
	 * @param int    $order_id    訂單 ID
	 * @param string $provider_id provider id
	 * @return array{order_id: int, provider_id: string}
	 */
	public static function args( int $order_id, string $provider_id ): array {
		return [
			'order_id'    => $order_id,
			'provider_id' => $provider_id,
		];
	}

	/**
	 * 排一次查詢（同一張單只保留一個待執行排程）
	 *
	 * @param int    $order_id    訂單 ID
	 * @param string $provider_id provider id
	 * @param int    $delay       秒
	 * @return void
	 */
	private static function enqueue( int $order_id, string $provider_id, int $delay ): void {
		if (!\function_exists( 'as_schedule_single_action' ) || !\function_exists( 'as_unschedule_all_actions' )) {
			return;
		}
		$args = self::args( $order_id, $provider_id );
		\as_unschedule_all_actions( self::HOOK, $args, self::GROUP );
		\as_schedule_single_action( \time() + $delay, self::HOOK, $args, self::GROUP );
	}

	/**
	 * 訂單可能用到的 provider id（統一 meta + 已註冊清單；取消排程用）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<int, string>
	 */
	private static function provider_ids_of( \WC_Order $order ): array {
		$ids = \J7\PowerCheckout\Domains\Logistics\ProviderRegister::get_provider_ids();
		unset( $order );
		return $ids;
	}

	// endregion
}
