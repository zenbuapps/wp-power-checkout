<?php
/**
 * EmbeddedSyncManager（SLP 嵌入式：主動查詢付款結果並認列）
 *
 * 嵌入式沒有 session，付款結果原則上來自 trade.* webhook；本類別在兩個時機主動以
 * POST /trade/payment/get 查詢一次補單：
 *  1. 顧客 3DS / pay() 完成後回到 order-received（EmbeddedGateway::before_order_received，節流 30 秒）
 *  2. 後台 query_trade()（不節流）
 *
 * 與導轉式 ReturnSyncManager 的差異（資安）：
 *  - tradeOrderId 只取伺服器端 confirm 階段寫入的 _pc_payment_identity，**不讀 $_GET**——
 *    嵌入式的 returnUrl 是我方自訂的 order-received，網址上的參數一律不可信。
 *  - 查詢結果 referenceOrderId 必須屬於本訂單：新格式（每次嘗試唯一，EmbeddedReference）須存在於本訂單
 *    reference 歷史 meta；升級前建立的交易沿用舊規則（數字型 = 本訂單 ID）。
 *  - 認列交給既有 StatusManager（冪等 / 終態 / 幣別 / 金額四道守衛）。
 *
 * never-throw：所有失敗以 ReturnSyncResult 表達，thankyou 頁絕不可 500。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers;

use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\ApiClient;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ReturnSyncResult;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\StatusSource;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Helpers\EmbeddedReference;

/** SLP 嵌入式主動查詢與認列 */
final class EmbeddedSyncManager {

	/** @var int 前台同步查詢逾時秒數 */
	private const TIMEOUT = 10;

	/** @var int 節流秒數 */
	private const THROTTLE_SEC = 30;

	/** @var string 節流 transient 前綴 */
	private const THROTTLE_PREFIX = 'pc_slp_emb_sync_';

	/** @var PaymentDTO|null 最近一次查詢結果（供呼叫端判斷交易狀態） */
	public ?PaymentDTO $last_payment = null;

	/**
	 * Constructor
	 *
	 * @param AbstractPaymentGateway $gateway 付款閘道（logger 與 API 用）
	 * @param \WC_Order              $order   訂單
	 */
	public function __construct(
		private readonly AbstractPaymentGateway $gateway,
		private readonly \WC_Order $order,
	) {
	}

	/**
	 * 查詢並認列（never-throw）
	 *
	 * @param bool $throttle 是否套用 30 秒節流（前台回訪 true；後台查詢 false）
	 * @return ReturnSyncResult
	 */
	public function sync( bool $throttle = true ): ReturnSyncResult {
		$trade_order_id = ( new MetaKeys( $this->order ) )->get_payment_identity();
		if ( '' === $trade_order_id ) {
			return ReturnSyncResult::SKIPPED_NO_TRADE_ID;
		}

		if ( ! $this->order->has_status( [ OrderStatus::PENDING->value, OrderStatus::FAILED->value ] ) ) {
			return ReturnSyncResult::SKIPPED_NOT_PENDING;
		}

		if ( $throttle ) {
			$throttle_key = self::THROTTLE_PREFIX . $this->order->get_id();
			if ( \get_transient( $throttle_key ) ) {
				return ReturnSyncResult::SKIPPED_THROTTLED;
			}
			\set_transient( $throttle_key, 1, self::THROTTLE_SEC );
		}

		try {
			$payment_dto = ( new ApiClient( $this->gateway, $this->order ) )->get_payment( $trade_order_id, $this->get_timeout() );
		} catch ( \Throwable $e ) {
			$this->gateway->logger(
				"❌ {$this->gateway->title} 主動查詢付款結果失敗 #{$this->order->get_id()}<br>{$e->getMessage()}",
				'error',
				[ 'tradeOrderId' => $trade_order_id ],
				5
			);
			return ReturnSyncResult::API_FAILED;
		}

		// 查詢結果必須屬於本訂單：新格式須在本訂單 reference 歷史內；舊版交易（升級前）為數字型 = 訂單 ID
		$reference_order_id = isset( $payment_dto->referenceOrderId ) ? $payment_dto->referenceOrderId : '';
		$belongs            = EmbeddedReference::is_attempt_reference( $reference_order_id )
		? EmbeddedReference::belongs_to( $this->order, $reference_order_id )
		: $reference_order_id === (string) $this->order->get_id();
		if ( ! $belongs ) {
			$this->gateway->logger(
				"❌ {$this->gateway->title} 主動查詢結果不屬於本訂單 #{$this->order->get_id()}",
				'error',
				[
					'tradeOrderId'     => $trade_order_id,
					'referenceOrderId' => $reference_order_id,
				],
				5
			);
			return ReturnSyncResult::MISMATCHED_ORDER;
		}

		$this->last_payment = $payment_dto;

		try {
			( new StatusManager( $payment_dto, $this->order, StatusSource::RETURN_SYNC ) )->update_order_status();
		} catch ( \Throwable $e ) {
			$this->gateway->logger(
				"❌ {$this->gateway->title} 主動查詢成功但認列失敗 #{$this->order->get_id()}<br>{$e->getMessage()}",
				'error',
				[ 'tradeOrderId' => $trade_order_id ],
				5
			);
			return ReturnSyncResult::SETTLE_FAILED;
		}

		return ReturnSyncResult::UPDATED;
	}

	/** @return int 同步查詢逾時秒數（與導回同步共用 filter） */
	private function get_timeout(): int {
		return (int) \apply_filters( 'power_checkout_slp_return_query_timeout', self::TIMEOUT );
	}
}
