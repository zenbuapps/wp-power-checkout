<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Interfaces;

/**
 * 嵌入式（站內付）金流統一 dispatch 介面
 *
 * 由 PaymentDispatchApiService（POST power-checkout/v1/pay/{order_id}/dispatch）呼叫；
 * REST 層已完成 order_key 驗證、gateway 比對與 needs_payment() 檢查。
 *
 * 兩段式慣例：payload 無 pay_session → init（回前端 SDK 初始化資料）；
 * 有 pay_session → confirm（呼叫金流建立交易，回 next_action 等）。
 */
interface IEmbeddedPaymentProvider {

	/**
	 * 執行 dispatch
	 *
	 * 回傳值會原樣放進 REST 回應的 data（建議含 mode / provider 鍵）。
	 * 失敗請 throw；REST 層記 log 並回通用錯誤，不外洩例外訊息。
	 *
	 * @param \WC_Order            $order   訂單
	 * @param array<string, mixed> $payload 前端送來的參數（已移除 order_key / order_id）
	 * @return array<string, mixed>
	 * @throws \Throwable 金流 API 失敗等
	 */
	public function dispatch( \WC_Order $order, array $payload ): array;
}
