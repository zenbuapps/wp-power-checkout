<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Managers;

use J7\PowerCheckout\Domains\Payment\TcatPay\DTOs\TcatPaySettingsDTO;
use J7\PowerCheckout\Domains\Payment\TcatPay\Http\TcatPayApiClient;
use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Enums\TcatPayMethod;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayMetaKeys;
use J7\PowerCheckout\Plugin;

/**
 * 黑貓PAY 自動補單（Action Scheduler `pc_tcat_pay_reconcile`）
 *
 * APN 同一狀態只重送約 3 次（每 15 分鐘）：我方若在那約 45 分鐘窗口內無法回 OK，通知就永久遺失；
 * 而超商代碼 / ATM 顧客可能數天後才繳費。本任務主動查平台（CocsOrderQuery / CvsOrderQuery），
 * 把「已收款仍 pending」的訂單補回。
 *
 *  - 建單成功（CocsOrderAppend / CvsOrderAppend）即 schedule()；同一訂單只保留一筆排程（AS args 固定 `order_id`，
 *    輪次 / 失敗次數記在訂單 meta），重新付款會重置
 *  - 認列只走 StatusManager::settle_paid()（明確已收款 process_code + 金額快照守衛）；金額不符只留 note 並停止
 *  - 退避：credit 依 CREDIT_DELAYS（約 5 分鐘起、70 分鐘內查完）；CVS / ATM 每 CVS_INTERVAL 查一次，
 *    直到繳費期限（`_pc_tcat_pay_payment_info.expire_date` 當日結束）+ CVS_GRACE，或達 CVS_MAX_ROUNDS
 *  - 平台未收款且期滿 → 只留 note，**永不自動取消**（「還沒繳」與「繳了沒通知」在查詢上都是安靜的）
 *  - 查詢失敗 → +RETRY_DELAY 重試（不消耗輪次），連續失敗達 MAX_FAILURES → note 放棄
 *  - 訂單已付款 / 已取消 / 已退款 / 非黑貓PAY / 無交易編號 → no-op（不打 API）
 *  - 延遲可由 filter `power_checkout_tcat_pay_reconcile_delay`（int $default, int $order_id, string $method, int $round）調整，下限 60 秒
 *
 * 只查「目前嘗試」的交易編號；舊嘗試的付款仍由 APN（含歷史編號）或後台「查詢補單」處理。
 *
 * @see zenbu payments/kolet/kolet-reconcile.cron.ts
 */
final class TcatPayReconcileJob {

	/** @var string Action Scheduler hook */
	public const HOOK = 'pc_tcat_pay_reconcile';

	/** @var string Action Scheduler group */
	public const GROUP = 'power_checkout';

	/** @var array<int, int> credit 每輪延遲秒數（建單後 5 / 10 / 20 / 40 / 70 分鐘各查一次） */
	public const CREDIT_DELAYS = [ 300, 300, 600, 1200, 1800 ];

	/** @var int CVS / ATM 查詢間隔秒數 */
	public const CVS_INTERVAL = 14400;

	/** @var int CVS / ATM 繳費期限後的寬限秒數（超商入帳回報延遲） */
	public const CVS_GRACE = 86400;

	/** @var int CVS / ATM 最多查詢輪次（無期限資訊時的上限，約 10 天） */
	public const CVS_MAX_ROUNDS = 60;

	/** @var int 查詢失敗重試延遲秒數 */
	public const RETRY_DELAY = 900;

	/** @var int 連續查詢失敗上限 */
	public const MAX_FAILURES = 5;

	/** @var int 延遲下限秒數 */
	private const MIN_DELAY = 60;

	/** Register hooks（無條件註冊，gateway 停用後在途訂單仍可補單） @return void */
	public static function register_hooks(): void {
		\add_action( self::HOOK, [ __CLASS__, 'run' ], 10, 1 );
	}

	/**
	 * 建單成功後排入補單任務（重置輪次；同一訂單只保留一筆排程）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function schedule( \WC_Order $order ): void {
		if ( ! \function_exists( 'as_schedule_single_action' ) || ! \function_exists( 'as_unschedule_all_actions' ) ) {
			Plugin::logger( '黑貓PAY：Action Scheduler 不可用，無法排入補單任務', 'warning', [ 'order_id' => $order->get_id() ] );
			return;
		}

		$meta = new TcatPayMetaKeys( $order );
		$meta->update_reconcile_state( 0, 0 );
		$method = TcatPayMethod::tryFrom( $meta->get_method() );
		$base   = null !== $method && $method->is_cvs() ? self::CVS_INTERVAL : self::CREDIT_DELAYS[0];

		self::enqueue( $order->get_id(), self::delay( $base, $order->get_id(), $meta->get_method(), 0 ) );
	}

	/**
	 * Action Scheduler callback（never-throw）
	 *
	 * @param int $order_id 訂單 ID
	 * @return void
	 */
	public static function run( int $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! self::should_reconcile( $order ) ) {
			return;
		}

		$meta     = new TcatPayMetaKeys( $order );
		$trade_no = $meta->get_trade_no();
		$method   = TcatPayMethod::tryFrom( $meta->get_method() );
		$is_cvs   = null !== $method && $method->is_cvs();
		$round    = $meta->get_reconcile_round();

		try {
			$query = ( new TcatPayApiClient( TcatPaySettingsDTO::instance() ) )->collect(
				$is_cvs ? TcatPayApiClient::CMD_CVS_ORDER_QUERY : TcatPayApiClient::CMD_COCS_ORDER_QUERY,
				[ 'cust_order_no' => $trade_no ]
			);
		} catch ( \Throwable $e ) {
			self::handle_failure( $order, $meta, $round, $e );
			return;
		}

		++$round;
		$meta->update_reconcile_state( $round, 0 );

		if ( StatusManager::is_platform_paid( $query, $is_cvs ) ) {
			// 已收款：認列或金額不符（只留 note），兩者都不再排程
			( new StatusManager( $order ) )->settle_paid( $query, $trade_no, "{$trade_no}:RECONCILE", null, \__( '自動補單', 'power_checkout' ) );
			return;
		}

		$stop_reason = $is_cvs ? self::cvs_stop_reason( $meta, $round ) : self::credit_stop_reason( $round );
		if ( null !== $stop_reason ) {
			$order->add_order_note(
				\sprintf(
					/* translators: 1: 原因 2: 交易編號 */
					\__( '黑貓PAY 自動補單：平台仍未確認收款（%1$s），停止自動補單，訂單狀態未變更（不會自動取消）。交易編號 %2$s', 'power_checkout' ),
					$stop_reason,
					$trade_no
				)
			);
			return;
		}

		$base = $is_cvs ? self::CVS_INTERVAL : self::CREDIT_DELAYS[ $round ];
		self::enqueue( $order_id, self::delay( $base, $order_id, $meta->get_method(), $round ) );
	}

	/**
	 * 是否需要補單（黑貓PAY 訂單、仍待付款、已有交易編號）
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	public static function should_reconcile( \WC_Order $order ): bool {
		return TcatPayGateway::ID === $order->get_payment_method()
		&& ! $order->is_paid()
		&& $order->has_status( [ 'pending', 'failed', 'on-hold' ] )
		&& '' !== ( new TcatPayMetaKeys( $order ) )->get_trade_no();
	}

	/**
	 * 查詢失敗：未達上限 → +RETRY_DELAY 重試（不消耗輪次）；達上限 → note 放棄
	 *
	 * @param \WC_Order       $order 訂單
	 * @param TcatPayMetaKeys $meta  meta
	 * @param int             $round 目前輪次
	 * @param \Throwable      $error 錯誤
	 * @return void
	 */
	private static function handle_failure( \WC_Order $order, TcatPayMetaKeys $meta, int $round, \Throwable $error ): void {
		$failures = $meta->get_reconcile_failures() + 1;
		$meta->update_reconcile_state( $round, $failures );

		Plugin::logger(
			'黑貓PAY 自動補單查詢失敗',
			'warning',
			[
				'order_id' => $order->get_id(),
				'failures' => $failures,
				'error'    => $error->getMessage(),
			]
		);

		if ( $failures < self::MAX_FAILURES ) {
			self::enqueue( $order->get_id(), self::RETRY_DELAY );
			return;
		}

		$order->add_order_note(
			\sprintf(
				/* translators: 1: 次數 2: 錯誤訊息 */
				\__( '⚠️ 黑貓PAY 自動補單連續查詢失敗 %1$d 次，已放棄自動重試：%2$s。請至後台使用「黑貓PAY 重新查詢付款狀態（補單）」或黑貓PAY 後台確認', 'power_checkout' ),
				$failures,
				$error->getMessage()
			)
		);
	}

	/**
	 * Credit 是否該停止（退避表用完）
	 *
	 * @param int $round 已執行輪次
	 * @return string|null 停止原因；null = 繼續
	 */
	private static function credit_stop_reason( int $round ): ?string {
		return $round >= \count( self::CREDIT_DELAYS ) ? \__( '已達查詢次數上限', 'power_checkout' ) : null;
	}

	/**
	 * CVS / ATM 是否該停止（已過繳費期限 + 寬限，或達最大輪次）
	 *
	 * @param TcatPayMetaKeys $meta  meta
	 * @param int             $round 已執行輪次
	 * @return string|null 停止原因；null = 繼續
	 */
	private static function cvs_stop_reason( TcatPayMetaKeys $meta, int $round ): ?string {
		$expire = self::expire_timestamp( $meta->get_payment_info()['expire_date'] ?? null );
		if ( null !== $expire && \time() > $expire + self::CVS_GRACE ) {
			return \__( '已超過繳費期限', 'power_checkout' );
		}
		return $round >= self::CVS_MAX_ROUNDS ? \__( '已達查詢次數上限', 'power_checkout' ) : null;
	}

	/**
	 * 繳費期限 → timestamp（`YYYY-MM-DD` 視為站台時區當日 23:59:59；也接受 `YYYY-MM-DD HH:mm:ss`）
	 *
	 * @param mixed $value expire_date
	 * @return int|null 無法解析回 null
	 */
	private static function expire_timestamp( mixed $value ): ?int {
		if ( ! \is_string( $value ) ) {
			return null;
		}
		$value = \trim( $value );
		if ( 1 === \preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$value .= ' 23:59:59';
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, \wp_timezone() );
		return false === $date ? null : $date->getTimestamp();
	}

	/**
	 * 排入單次任務（先清掉同訂單既有排程）
	 *
	 * @param int $order_id 訂單 ID
	 * @param int $delay    延遲秒數
	 * @return void
	 */
	private static function enqueue( int $order_id, int $delay ): void {
		if ( ! \function_exists( 'as_schedule_single_action' ) || ! \function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}
		$args = [ 'order_id' => $order_id ];
		\as_unschedule_all_actions( self::HOOK, $args, self::GROUP );
		\as_schedule_single_action( \time() + $delay, self::HOOK, $args, self::GROUP );
	}

	/**
	 * 補單延遲秒數（filter `power_checkout_tcat_pay_reconcile_delay`，下限 60 秒）
	 *
	 * @param int    $base     預設延遲
	 * @param int    $order_id 訂單 ID
	 * @param string $method   子方式
	 * @param int    $round    已執行輪次
	 * @return int
	 */
	private static function delay( int $base, int $order_id, string $method, int $round ): int {
		$filtered = \apply_filters( 'power_checkout_tcat_pay_reconcile_delay', $base, $order_id, $method, $round );
		return \max( self::MIN_DELAY, \is_numeric( $filtered ) ? (int) $filtered : $base );
	}
}
