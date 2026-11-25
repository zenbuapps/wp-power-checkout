<?php
/**
 * 物流 provider 選配能力：結帳頁（下單前）選店 + 運送方式子類型
 *
 * 實作此介面的 provider 由共用的 {@see \J7\PowerCheckout\Domains\Logistics\Shared\Services\CheckoutLogisticsIntegration}
 * 一次接上「傳統短碼結帳」與「WooCommerce Blocks 結帳」：
 *  - 運送方式以 rate meta `sub_type` 區分子類型（選哪個 rate 就決定 sub_type）。
 *  - 超商子類型在結帳頁顯示「選擇門市」，門市以 CartLogisticsSession 的 session 權杖綁定（下單前沒有訂單）。
 *  - 下單時把子類型 / 付款情境 / 門市搬進訂單 meta（LogisticsMetaKeys）。
 *
 * 綠界（ecpay_logistics）有自己較早的整合（BlocksLogisticsIntegration / classic-logistics.js），
 * 尚未改走這一層；新的物流廠商請實作本介面。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Interfaces;

/** 結帳頁選店能力 */
interface ISupportsCheckoutStoreSelection {

	/**
	 * 結帳頁對應的 WC 運送方式 id（WC_*Shipping::METHOD_ID）
	 *
	 * @return string
	 */
	public function get_checkout_shipping_method_id(): string;

	/**
	 * 需要選門市的子類型（例：['SEVEN']；宅配不在此列）
	 *
	 * @return array<int, string>
	 */
	public function get_cvs_sub_types(): array;

	/**
	 * 購物車（下單前）選店：回傳選店頁 auto-submit 表單 HTML
	 *
	 * 必須以 CartLogisticsSession::issue_token() 綁定當前 session，並在選店回呼以權杖寫回 session。
	 *
	 * @param array<string, mixed> $ctx 上下文（sub_type / payment_scenario）
	 * @return array<string, mixed> 含 redirect_target
	 * @throws \Exception 未啟用 / 子類型不合法 / 傳輸失敗
	 */
	public function get_cart_store_selection( array $ctx = [] ): array;
}
