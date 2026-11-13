<?php
/**
 * F5：全虛擬商品訂單轉處理中後，於請求結束時自動完成（DF4）
 *
 * - 觸發：woocommerce_order_status_changed 且 to=processing（金流回呼、管理員手動皆適用）
 * - R1：轉 processing 時只把訂單 ID 放進佇列（key 去重），shutdown 才由 flush_queue() 統一處理，
 *   重讀訂單確認「最終狀態仍是 processing」才完成 → 避免金流 payment_complete() 後再 update_status('processing')
 *   造成狀態來回、完成信寄兩次
 * - R2：發票 provider 已冪等，completed 再觸發自動開立時會跳過，本類別不需處理
 * - never-throw：任何 \Throwable 皆記 error log（含 #訂單 ID），不外拋、不改狀態
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Checkout\Services;

use J7\PowerCheckout\Domains\Checkout\DTOs\CheckoutEnhancementSettingsDTO;
use J7\PowerCheckout\Domains\Checkout\Shared\Helpers\CartInspector;
use J7\PowerCheckout\Plugin;

/** 全虛擬商品訂單自動完成 */
final class VirtualOrderAutoCompleteService {

	/** @var string 設定開關鍵 */
	private const SETTING_KEY = 'virtual_auto_complete';

	/** @var string 是否自動完成的 filter（傳入 bool 與 WC_Order） */
	public const SHOULD_COMPLETE_FILTER = 'power_checkout_virtual_auto_complete_should_complete';

	/** @var string 觸發狀態 */
	private const TRIGGER_STATUS = 'processing';

	/** @var string 目標狀態 */
	private const TARGET_STATUS = 'completed';

	/**
	 * 待自動完成的訂單佇列（key = 訂單 ID，用 key 去重）
	 *
	 * @var array<int, true>
	 */
	private static array $queue = [];

	/**
	 * 註冊 hooks（無條件掛上，開關於 callback 內判斷）
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		\add_action( 'woocommerce_order_status_changed', [ __CLASS__, 'on_status_changed' ], 20, 4 );
	}

	/**
	 * 訂單狀態變更：轉為處理中且開關開啟時，把訂單 ID 放進佇列，並確保 shutdown 時會 flush
	 *
	 * @param mixed $order_id 訂單 ID
	 * @param mixed $from     原狀態
	 * @param mixed $to       新狀態
	 * @param mixed $order    訂單物件（未使用；flush 時會重讀）
	 * @return void
	 */
	public static function on_status_changed( mixed $order_id, mixed $from, mixed $to, mixed $order = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$id = \is_numeric( $order_id ) ? (int) $order_id : 0;

		try {
			if ( self::TRIGGER_STATUS !== $to || $id <= 0 || !CheckoutEnhancementSettingsDTO::is_on( self::SETTING_KEY ) ) {
				return;
			}

			self::$queue[ $id ] = true;

			// 以 has_action 判斷而非 static 旗標：hook 可能被移除（例如測試還原 $wp_filter）
			if ( false === \has_action( 'shutdown', [ __CLASS__, 'flush_queue' ] ) ) {
				\add_action( 'shutdown', [ __CLASS__, 'flush_queue' ] );
			}
		} catch ( \Throwable $e ) {
			Plugin::logger( "VirtualOrderAutoCompleteService::on_status_changed 訂單 #{$id} 失敗：{$e->getMessage()}", 'error' );
		}
	}

	/**
	 * 請求結束時處理佇列：最終狀態仍為處理中、全虛擬、filter 允許，才轉為已完成
	 *
	 * 先取出並清空佇列，避免 flush 期間重入時重跑。
	 *
	 * @return void
	 */
	public static function flush_queue(): void {
		$order_ids = \array_keys( self::$queue );
		self::reset_queue();

		foreach ( $order_ids as $order_id ) {
			self::maybe_complete( $order_id );
		}
	}

	/**
	 * 清空佇列（測試 tear_down 用）
	 *
	 * @return void
	 */
	public static function reset_queue(): void {
		self::$queue = [];
	}

	/**
	 * 判斷並自動完成單一訂單（never-throw）
	 *
	 * @param int $order_id 訂單 ID
	 * @return void
	 */
	private static function maybe_complete( int $order_id ): void {
		try {
			$order = \wc_get_order( $order_id );
			if ( !$order instanceof \WC_Order ) {
				return;
			}

			// 以最終狀態為準：金流後續改狀態 / 已被別人完成 → 不處理
			if ( self::TRIGGER_STATUS !== $order->get_status() ) {
				return;
			}

			if ( !CartInspector::is_all_virtual_order( $order ) ) {
				return;
			}

			$should_complete = (bool) \apply_filters( self::SHOULD_COMPLETE_FILTER, true, $order );
			if ( !$should_complete ) {
				return;
			}

			$updated = $order->update_status( self::TARGET_STATUS, \__( 'Power Checkout：全虛擬商品訂單，自動完成', 'power_checkout' ) );
			if ( false === $updated ) {
				Plugin::logger( "VirtualOrderAutoCompleteService 訂單 #{$order_id} 自動完成失敗（update_status 回傳 false）", 'error' );
			}
		} catch ( \Throwable $e ) {
			Plugin::logger( "VirtualOrderAutoCompleteService 訂單 #{$order_id} 自動完成失敗：{$e->getMessage()}", 'error' );
		}
	}
}
