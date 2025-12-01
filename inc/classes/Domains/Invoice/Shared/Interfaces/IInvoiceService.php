<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Interfaces;

interface IInvoiceService {

	/**
	 * @param bool $with_default 是否有預設值，還是只拿 DB 值
	 * false = 只拿 db, true = 會給預設值
	 *
	 * @return array 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array;

	/**
	 * 開立發票
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array{code:int, message:string, data:array} API 資料
	 */
	public function issue( \WC_Order|int $order_or_id ): array;

	/**
	 * 做廢發票
	 *
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array{code:int, message:string, data:array} API 資料
	 */
	public function cancel( \WC_Order|int $order_or_id ): array;

	/**
	 * 取得發票號碼
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 */
	public function get_invoice_number( \WC_Order $order ): string;
}
