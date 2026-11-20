<?php
/**
 * 物流 provider 選配能力：後台「測試連線」
 *
 * 以已儲存的設定呼叫一支唯讀 API 驗證憑證與環境（P4–P7 各 provider 自行實作；未實作者 ping 回 501）。
 * 不需 provider 已啟用（設定頁儲存後、啟用前即可測試）。失敗一律 throw，由 LogisticsPingApiService 轉 HTTP。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Interfaces;

interface ISupportsConnectionTest {

	/**
	 * 測試連線（唯讀 API）
	 *
	 * @return array<string, mixed> 給後台顯示的結果摘要（不得含憑證）
	 * @throws \Throwable 憑證缺漏 / API 失敗
	 */
	public function test_connection(): array;
}
