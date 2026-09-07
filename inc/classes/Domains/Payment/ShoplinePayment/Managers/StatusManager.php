<?php
/**
 * StatusManager（SLP 付款狀態 → 訂單狀態）
 *
 * 兩條認列路徑共用：
 *  1. 導回同步查詢（ReturnSyncManager，StatusSource::RETURN_SYNC）
 *  2. Webhook 非同步通知（WebHook，StatusSource::WEBHOOK）
 *
 * 因為同一筆付款會有兩條路徑進來，本類別必須具備冪等；
 * 又因導回路徑的 tradeOrderId 來自客戶瀏覽器，SUCCEEDED 前必須有已處理中 / 終態 / 幣別 / 金額四道守衛，
 * 且金額比對的是顧客實付金額（payment->paidAmount），不是我方送出後被原樣回傳的應收回音。
 *
 * @see specs/open-issue/issue-18-plan.md §流程 3
 */

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers;

use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseStatus;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\StatusSource;


/**
 * StatusManager
 * 依照付款回應，改變訂單狀態
 * */
final class StatusManager {

	/**
	 * @var int 金額比對容差（cents）
	 * Components\Amount::create 以 $amount * 100 產生 cents，PHP 浮點運算可能讓
	 * 19.99 * 100 變成 1998.9999... 而截斷為 1998，故容許 ±1 cent，避免誤判合法付款為竄改。
	 */
	private const AMOUNT_TOLERANCE_CENTS = 1;

	/**
	 * Constructor
	 *
	 * @param PaymentDTO   $_response_dto 付款回應 DTO
	 * @param \WC_Order    $order         訂單
	 * @param StatusSource $source        狀態來源，預設 WEBHOOK（既有呼叫端不需改動即維持原行為）
	 */
	public function __construct(
		private readonly PaymentDTO $_response_dto,
		private readonly \WC_Order $order,
		private readonly StatusSource $source = StatusSource::WEBHOOK,
	) {
	}


	/**
	 * 依照 API 回應狀態不同的轉換不同的訂單狀態
	 * 付款成功  => 處理中
	 * 付款失敗  => 等待付款中
	 * 逾時未付  => 取消
	 * 退款成功 => 退款
	 *
	 * @return void
	 */
	public function update_order_status(): void {
		$meta_keys     = new MetaKeys($this->order);
		$processed_key = $this->get_processed_key();

		// ① 冪等守衛：同一筆付款的同一狀態只處理一次（導回同步與 Webhook 共用）
		if ($meta_keys->is_status_processed($processed_key)) {
			return;
		}

		$status_enum = ResponseStatus::tryFrom($this->_response_dto->status);

		// ② 付款成功前的四道守衛：已處理中 / 終態 / 幣別 / 金額（任一不過 → 維持現狀）
		if (ResponseStatus::SUCCEEDED === $status_enum && !$this->can_mark_as_paid()) {
			return;
		}

		// ③ order note + 付款詳情
		$payment_detail_html = $this->get_payment_detail_html();
		$this->order->add_order_note("{$this->source->label()} {$payment_detail_html}");
		$meta_keys->update_payment_detail($this->_response_dto->to_array() );

		// ④ 狀態轉換
		$order_status = match ( $status_enum ) {
			ResponseStatus::SUCCEEDED => OrderStatus::PROCESSING,
			ResponseStatus::EXPIRED => OrderStatus::CANCELLED,
			// EventType::SESSION_PENDING,
			// EventType::SESSION_CREATED,
			default => OrderStatus::PENDING,
		};

		if (ResponseStatus::SUCCEEDED === $status_enum) {
			// 不改用 payment_complete()：對無 line item / 純虛擬商品訂單會先轉 completed 再被降級為
			// processing，多觸發一輪 woocommerce_order_status_*（發票自動開立、通知信）。
			// date_paid 由 WC 的 maybe_set_date_paid() 於 save() 時自動補上。
			$this->order->set_transaction_id($this->_response_dto->tradeOrderId);
		}

		$this->order->update_status($order_status->value);

		// ⑤ 記錄已處理，供下一條路徑跳過
		$meta_keys->push_processed_status($processed_key);
	}


	/** @return string 冪等鍵，格式 "{tradeOrderId}:{status}" */
	private function get_processed_key(): string {
		return "{$this->_response_dto->tradeOrderId}:{$this->_response_dto->status}";
	}


	/**
	 * 產生付款詳情 order note 內容（殘缺 payload 時降級為純文字）
	 *
	 * `PaymentDTO::to_human_html()` 會讀取 `payment->paidAmount` 等欄位，殘缺 payload
	 * （DTO 基底在非 local 環境會吞掉建構錯誤）可能留下未初始化屬性而拋 `Error`。
	 * 一筆已驗簽、已通過四道守衛的合法通知，不該只因為「畫不出漂亮的備註」就無法認列，
	 * 故此處降級為最小可用的純文字備註，讓狀態轉換照常完成。
	 *
	 * @return string order note 內容
	 */
	private function get_payment_detail_html(): string {
		try {
			return $this->_response_dto->to_human_html();
		} catch (\Throwable $e) {
			return \sprintf(
				'付款狀態：%s（tradeOrderId: %s）<br>⚠️ 通知內容不完整，無法產生完整付款詳情：%s',
				$this->_response_dto->status,
				$this->_response_dto->tradeOrderId,
				$e->getMessage()
			);
		}
	}


	/**
	 * 付款成功認列前的守衛
	 *
	 * 依序為：
	 * a. 已處理中守衛：訂單已 processing 代表先前某筆通知已認列，重複通知直接 skip
	 *    （對齊 Paynow\Managers\StatusManager::handle_success()；避免不同 tradeOrderId
	 *      的後續通知覆寫 _pc_payment_detail / transaction_id 並多寫一則 order note）
	 * b. 終態守衛：已 refunded / cancelled / completed 的訂單不得被「復活」為 processing
	 * c. 幣別守衛：store 預設幣別可能是 USD，不比對幣別則 USD 訂單金額有機會與 TWD 通知偶然相符
	 * d. 金額守衛：防竄改，容許 ±1 cent 浮點誤差
	 *
	 * @return bool 是否可以認列為已付款
	 */
	private function can_mark_as_paid(): bool {
		// a. 已處理中守衛：已 processing 則 skip（重複通知不重複處理，不寫 note）
		if ($this->order->has_status( OrderStatus::PROCESSING->value )) {
			return false;
		}

		// b. 終態守衛
		$final_statuses = [
			OrderStatus::REFUNDED->value,
			OrderStatus::CANCELLED->value,
			OrderStatus::COMPLETED->value,
		];
		if ($this->order->has_status($final_statuses)) {
			// 不可靜默跳過：「客戶付了錢但商家已手動取消 / 已退款」這種情況必須讓客服看得到，
			// 否則遲到的付款通知會無人察覺。
			$this->order->add_order_note(
				\sprintf(
					'%s ⚠️ 收到遲到的付款通知，訂單已為終態「%s」未自動變更，請人工確認（tradeOrderId: %s，通知狀態: %s）',
					$this->source->label(),
					$this->order->get_status(),
					$this->_response_dto->tradeOrderId,
					$this->_response_dto->status
				)
			);
			return false;
		}

		$notified_amount = $this->get_notified_amount();
		if (null === $notified_amount) {
			$this->order->add_order_note(
				\sprintf(
					'%s ⚠️ 收到付款成功通知，但通知內缺少金額資訊，拒絕變更狀態（tradeOrderId: %s）',
					$this->source->label(),
					$this->_response_dto->tradeOrderId
				)
			);
			return false;
		}

		// c. 幣別守衛
		$order_currency = $this->order->get_currency();
		if ($notified_amount['currency'] !== $order_currency) {
			$this->order->add_order_note(
				\sprintf(
					'%s ⚠️ 疑似竄改：付款通知幣別為 %s（來源：%s），訂單幣別為 %s，拒絕變更狀態（tradeOrderId: %s）',
					$this->source->label(),
					$notified_amount['currency'],
					$notified_amount['source'],
					$order_currency,
					$this->_response_dto->tradeOrderId
				)
			);
			return false;
		}

		// d. 金額守衛
		$expected_cents = (int) \round( (float) $this->order->get_total() * 100 );
		$diff           = \abs( $notified_amount['value'] - $expected_cents );
		if ($diff > self::AMOUNT_TOLERANCE_CENTS) {
			$this->order->add_order_note(
				\sprintf(
					'%s ⚠️ 疑似竄改：付款通知金額為 %s（來源：%s），訂單應收 %s（cents），拒絕變更狀態（tradeOrderId: %s）',
					$this->source->label(),
					$notified_amount['value'],
					$notified_amount['source'],
					$expected_cents,
					$this->_response_dto->tradeOrderId
				)
			);
			return false;
		}

		return true;
	}


	/**
	 * 取得用於守衛比對的金額（cents + 幣別 + 來源）
	 *
	 * **優先取 `payment->paidAmount`（顧客實付金額）**，對齊 PAYUNi（比 `TradeAmt`）與
	 * PayNow（比 `Amount`）的實付比對做法。
	 *
	 * `order->amount` 是我方建立 session 時送出、SLP 原樣回傳的「回音」，拿它做防竄改
	 * 等於拿自己送出去的值驗自己，守衛效力打折，因此**僅在 paidAmount 取不到時降級使用**——
	 * 降級後的保護強度與本次修復前相同（不會更弱），且不致誤擋合法通知。
	 *
	 * 何時會降級：`Webhook\Payment::$require_properties` 已含 `paidAmount`，正常的 SUCCEEDED
	 * 通知一定有值；但 DTO 基底在非 local 環境會吞掉建構錯誤，殘缺 payload 可能留下
	 * 未初始化的 `Amount::$value`。此時 `to_array()` 會略過該屬性，走降級路徑。
	 *
	 * 註：本方法只在 SUCCEEDED 分支被呼叫，ATM 取號階段與 FAILED 通知都不會進到這裡。
	 *
	 * @return array{value: int, currency: string, source: string}|null 取不到任何金額時回傳 null（never-throw）
	 */
	private function get_notified_amount(): ?array {
		// 優先：顧客實付金額
		$paid_amount = $this->read_amount( true );
		if (null !== $paid_amount) {
			return $paid_amount;
		}

		// 降級：訂單應收金額（我方送出的回音）
		return $this->read_amount( false );
	}


	/**
	 * 從 DTO 讀出金額陣列
	 *
	 * 以 `to_array()` 取值而非直接存取屬性：`Components\Amount` 與 `Webhook\Order` 使用的是
	 * 拼錯的 `$required_properties`（DTO 基底只認 `require_properties`），欄位缺席時屬性會是
	 * 未初始化狀態，直接存取會拋 `Error`；`to_array()` 會略過未初始化屬性，配合 try/catch
	 * 即可維持 never-throw。
	 *
	 * @param bool $use_paid_amount true 取 payment->paidAmount（實付）；false 取 order->amount（回音）
	 *
	 * @return array{value: int, currency: string, source: string}|null
	 */
	private function read_amount( bool $use_paid_amount ): ?array {
		try {
			$amount_array = $use_paid_amount
			? $this->_response_dto->payment->paidAmount->to_array()
			: $this->_response_dto->order->amount->to_array();
		} catch (\Throwable) {
			return null;
		}

		if (!isset($amount_array['value'], $amount_array['currency'])) {
			return null;
		}

		return [
			'value'    => (int) $amount_array['value'],
			'currency' => (string) $amount_array['currency'],
			'source'   => $use_paid_amount ? '實付金額' : '訂單應收金額（回音、降級）',
		];
	}
}
