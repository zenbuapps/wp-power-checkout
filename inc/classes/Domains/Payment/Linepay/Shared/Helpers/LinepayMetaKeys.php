<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers;

/**
 * LINE Pay 直連訂單 meta（HPOS-aware：一律 $order->get_meta / update_meta_data）
 *
 * | Key | 說明 |
 * |---|---|
 * | `_pc_linepay_transaction_id` | 目前付款請求的 transactionId（**19 位字串**，R4） |
 * | `_pc_linepay_order_id` | 目前付款請求送出的 orderId（`PCL{order_id}-{attempt}-{隨機}`） |
 * | `_pc_linepay_attempt` | 付款嘗試次數（每次 request 遞增） |
 * | `_pc_linepay_charge_amount` | 送出的金額快照（整數 TWD）；confirm 金額與守衛依據 |
 * | `_pc_linepay_payment_url` | 付款頁 paymentUrl.web |
 * | `_pc_linepay_payment_detail` | confirm 回應 info 或付款明細（認列時寫入） |
 * | `_pc_linepay_refund_detail` | 退款紀錄（refundTransactionId 字串、金額、時間）陣列 |
 */
final class LinepayMetaKeys {

	public const TRANSACTION_ID_KEY = '_pc_linepay_transaction_id';
	public const ORDER_ID_KEY       = '_pc_linepay_order_id';
	public const ATTEMPT_KEY        = '_pc_linepay_attempt';
	public const CHARGE_AMOUNT_KEY  = '_pc_linepay_charge_amount';
	public const PAYMENT_URL_KEY    = '_pc_linepay_payment_url';
	public const PAYMENT_DETAIL_KEY = '_pc_linepay_payment_detail';
	public const REFUND_DETAIL_KEY  = '_pc_linepay_refund_detail';

	/**
	 * Constructor
	 *
	 * @param \WC_Order $order 訂單
	 */
	public function __construct( private readonly \WC_Order $order ) {
	}

	/**
	 * 下一次付款嘗試序號（遞增並保存）
	 *
	 * @return int
	 */
	public function next_attempt(): int {
		$attempt = \max( 0, (int) self::to_string( $this->order->get_meta( self::ATTEMPT_KEY ) ) ) + 1;
		$this->order->update_meta_data( self::ATTEMPT_KEY, (string) $attempt );
		$this->order->save();
		return $attempt;
	}

	/**
	 * 付款請求成功：寫入本次 orderId / transactionId / 金額快照 / 付款頁
	 *
	 * @param string $linepay_order_id LINE Pay orderId
	 * @param string $transaction_id   transactionId（字串）
	 * @param int    $amount           送出金額
	 * @param string $payment_url      paymentUrl.web
	 * @return void
	 */
	public function update_request( string $linepay_order_id, string $transaction_id, int $amount, string $payment_url ): void {
		$this->order->update_meta_data( self::ORDER_ID_KEY, $linepay_order_id );
		$this->order->update_meta_data( self::TRANSACTION_ID_KEY, $transaction_id );
		$this->order->update_meta_data( self::CHARGE_AMOUNT_KEY, (string) $amount );
		$this->order->update_meta_data( self::PAYMENT_URL_KEY, $payment_url );
		$this->order->save();
	}

	/** @return string 目前 transactionId（未建立為空字串） */
	public function get_transaction_id(): string {
		return self::to_string( $this->order->get_meta( self::TRANSACTION_ID_KEY ) );
	}

	/** @return string 目前 LINE Pay orderId */
	public function get_linepay_order_id(): string {
		return self::to_string( $this->order->get_meta( self::ORDER_ID_KEY ) );
	}

	/** @return int 送出金額快照（未建立為 0） */
	public function get_charge_amount(): int {
		return (int) self::to_string( $this->order->get_meta( self::CHARGE_AMOUNT_KEY ) );
	}

	/**
	 * 寫入付款明細
	 *
	 * @param array<string, mixed> $detail 明細
	 * @return void
	 */
	public function update_payment_detail( array $detail ): void {
		$this->order->update_meta_data( self::PAYMENT_DETAIL_KEY, $detail );
		$this->order->save();
	}

	/** @return array<string, mixed> 付款明細 */
	public function get_payment_detail(): array {
		$detail = $this->order->get_meta( self::PAYMENT_DETAIL_KEY );
		/** @var array<string, mixed> $result */
		$result = \is_array( $detail ) ? $detail : [];
		return $result;
	}

	/**
	 * 追加退款紀錄
	 *
	 * @param array<string, mixed> $record 退款紀錄
	 * @return void
	 */
	public function add_refund_detail( array $record ): void {
		$records   = $this->get_refund_detail();
		$records[] = $record;
		$this->order->update_meta_data( self::REFUND_DETAIL_KEY, $records );
		$this->order->save();
	}

	/** @return array<int, mixed> 退款紀錄 */
	public function get_refund_detail(): array {
		$records = $this->order->get_meta( self::REFUND_DETAIL_KEY );
		return \is_array( $records ) ? \array_values( $records ) : [];
	}

	/**
	 * 純量轉字串（非純量為空字串）
	 *
	 * @param mixed $value 值
	 * @return string
	 */
	private static function to_string( mixed $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}
}
