<?php
/**
 * ReturnSyncManager（缺陷 A：導回時的同步查詢與認列）
 *
 * 客戶於 SLP 付款成功後被導回 order-received 頁，本類別在頁面 render 前同步向 SLP
 * 查詢付款結果並認列，避免「等 webhook」造成的競態（客戶看到「尚未付款」）。
 *
 * 鐵律（前台路徑）：
 *  - sync() 絕不 throw。每個會拋錯的步驟（⑤ 查詢、⑦⑧ 認列）都在方法內各自 catch 並
 *    轉為 ReturnSyncResult；呼叫端的外層 catch 是第二道保險，不是這項保證的依據。
 *    AbstractPaymentGateway::before_page_render() 是先呼叫 before_order_received() 才
 *    empty_cart()，一旦 throw 客戶的購物車不會被清空。
 *  - 同步查詢使用短 timeout（預設 10 秒），不可沿用 Requester::TIMEOUT = 60。
 *
 * 資安（$_GET['tradeOrderId'] 由客戶瀏覽器帶入，不可信）：
 *  - order_key 閘門 → referenceOrderId 必須等於本訂單 ID → StatusManager 的金額 / 幣別守衛，
 *    三道獨立防線。驗證全數通過才寫入 _pc_payment_identity（webhook 的查單主鍵）。
 *  - 三道之中，步驟 ⑥ 的 referenceOrderId 比對是「同金額白嫖攻擊」的唯一防線
 *    （金額相同時另外兩道都會放行），移除前請先補等效防線——詳見該行上方註解。
 *
 * @see specs/open-issue/issue-18-plan.md §流程 1
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers;

use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\ApiClient;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RedirectGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ReturnSyncResult;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\StatusSource;

/** 導回同步查詢與認列 */
final class ReturnSyncManager {

	/** @var int 同步路徑逾時秒數（R2：不可沿用 Requester::TIMEOUT = 60） */
	private const TIMEOUT = 10;

	/** @var int 節流秒數，避免客戶連續重新整理造成 API 風暴 */
	private const THROTTLE_SEC = 30;

	/** @var string 節流 transient 前綴 */
	private const THROTTLE_PREFIX = 'pc_slp_return_sync_';

	/** @var string tradeOrderId 白名單正則（SLP 文件標示可達 64 字元） */
	private const TRADE_ORDER_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

	/**
	 * Constructor
	 *
	 * @param RedirectGateway $gateway 付款閘道（提供 logger 與 API 設定）
	 * @param \WC_Order       $order   訂單
	 */
	public function __construct(
		private readonly RedirectGateway $gateway,
		private readonly \WC_Order $order,
	) {
	}


	/**
	 * 同步查詢付款結果並認列
	 *
	 * 本方法不 throw：每個會拋錯的步驟（⑤ 查詢、⑦⑧ 認列）都各自 catch 並轉為
	 * ReturnSyncResult，所有失敗都以回傳值表達。呼叫端的外層 catch 是第二道保險，
	 * 不是本方法 never-throw 的依據。
	 *
	 * @return ReturnSyncResult 同步結果
	 */
	public function sync(): ReturnSyncResult {
		// ① tradeOrderId：缺席或格式不合為正常情境（例如客戶自行重訪），不記 log
		$trade_order_id = $this->get_trade_order_id();
		if ('' === $trade_order_id) {
			return ReturnSyncResult::SKIPPED_NO_TRADE_ID;
		}

		// ② order_key 閘門：不符就不打 API（第一道資安防線）
		if (!$this->is_order_key_valid()) {
			$this->gateway->logger(
				"⚠️ {$this->gateway->title} 導回同步查詢跳過：order_key 驗證未通過 #{$this->order->get_id()}",
				'warning',
				[ 'tradeOrderId' => $trade_order_id ],
				0,
				false
			);
			return ReturnSyncResult::SKIPPED_INVALID_KEY;
		}

		// ③ 狀態閘門：已認列過的訂單不重複查詢（冪等 + 省 API）
		if (!$this->order->has_status( [ OrderStatus::PENDING->value, OrderStatus::FAILED->value ] )) {
			return ReturnSyncResult::SKIPPED_NOT_PENDING;
		}

		// ④ 節流：transient 設在 API 呼叫之前，API 逾時也不會被連打
		$throttle_key = self::THROTTLE_PREFIX . $this->order->get_id();
		if (\get_transient($throttle_key)) {
			return ReturnSyncResult::SKIPPED_THROTTLED;
		}
		\set_transient($throttle_key, 1, self::THROTTLE_SEC);

		// ⑤ 同步查詢（短 timeout）
		try {
			$payment_dto = ( new ApiClient( $this->gateway, $this->order ) )->get_payment( $trade_order_id, $this->get_timeout() );
		} catch (\Throwable $e) {
			$this->gateway->logger(
				"❌ {$this->gateway->title} 導回同步查詢失敗 #{$this->order->get_id()}<br>{$e->getMessage()}",
				'error',
				[ 'tradeOrderId' => $trade_order_id ],
				5
			);
			return ReturnSyncResult::API_FAILED;
		}

		// ⑥ referenceOrderId 比對（第二道資安防線）：查詢結果必須屬於本訂單
		//
		// ⚠️ 安全關鍵：此行是「同金額白嫖攻擊」的唯一防線。
		// 攻擊者持自己訂單的合法 order_key + 受害者的 tradeOrderId，
		// 前面的 order_key 閘門會過（key 是他自己的），StatusManager 的金額 / 幣別守衛也會過
		// （兩筆訂單金額相同時），只有這裡擋得住。
		// 移除前請先補等效防線——突變測試已證實：拆掉此行，
		// test_referenceOrderId指向別的訂單時拒絕認列 立刻轉紅。
		$reference_order_id = $payment_dto->referenceOrderId;
		if ($reference_order_id !== (string) $this->order->get_id()) {
			$this->gateway->logger(
				"❌ {$this->gateway->title} 導回同步查詢結果不屬於本訂單 #{$this->order->get_id()}",
				'error',
				[
					'tradeOrderId'     => $trade_order_id,
					'referenceOrderId' => $reference_order_id,
				],
				5
			);
			return ReturnSyncResult::MISMATCHED_ORDER;
		}

		// ⑦⑧ 認列。整段包 try/catch，讓「本方法不 throw」這個承諾由自己保證，
		// 而不是依賴呼叫端的外層 catch——never-throw 是這條路徑的核心安全屬性
		// （thankyou 頁絕不可 500），也讓 sync() 能被 gateway 以外的呼叫端安全複用。
		try {
			// ⑦ 驗證通過才寫入付款識別碼（webhook 查單主鍵，未驗證即寫是 meta 汙染的攻擊入口）
			( new MetaKeys( $this->order ) )->update_payment_identity( $trade_order_id );

			// ⑧ 交由 StatusManager（第三道防線：金額 / 幣別 / 終態 / 冪等守衛都在裡面）
			( new StatusManager( $payment_dto, $this->order, StatusSource::RETURN_SYNC ) )->update_order_status();
		} catch (\Throwable $e) {
			$this->gateway->logger(
				"❌ {$this->gateway->title} 導回同步查詢成功但認列失敗 #{$this->order->get_id()}<br>{$e->getMessage()}",
				'error',
				[ 'tradeOrderId' => $trade_order_id ],
				5
			);
			return ReturnSyncResult::SETTLE_FAILED;
		}

		return ReturnSyncResult::UPDATED;
	}


	/**
	 * 從 query string 取得並清理 tradeOrderId
	 *
	 * @return string 通過白名單的 tradeOrderId，不合則回傳空字串
	 */
	private function get_trade_order_id(): string {
		// 導回頁由 SLP 帶參數（無 nonce），改以 order_key + referenceOrderId 雙重驗證；
		// unslash / sanitize 於下方緊接著執行，故此處忽略 sniff。
		$raw = $_GET['tradeOrderId'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if (!\is_string($raw)) {
			return '';
		}

		$trade_order_id = \sanitize_text_field( \wp_unslash( $raw ) );
		if (!\preg_match( self::TRADE_ORDER_ID_PATTERN, $trade_order_id )) {
			return '';
		}

		return $trade_order_id;
	}


	/**
	 * 驗證 query string 的 order_key 是否屬於本訂單
	 *
	 * @return bool 是否通過
	 */
	private function is_order_key_valid(): bool {
		// order_key 本身即為 WC 的驗證憑證（無 nonce）；unslash / sanitize 於下方緊接著執行。
		$raw = $_GET['key'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if (!\is_string($raw) || '' === $raw) {
			return false;
		}

		$key = \sanitize_text_field( \wp_unslash( $raw ) );
		return \hash_equals( $this->order->get_order_key(), $key );
	}


	/** @return int 同步查詢逾時秒數（可由 filter 覆寫） */
	private function get_timeout(): int {
		return (int) \apply_filters( 'power_checkout_slp_return_query_timeout', self::TIMEOUT );
	}
}
