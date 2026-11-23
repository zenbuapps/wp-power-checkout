<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers;

use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;

/**
 * 黑貓PAY 訂單 meta（HPOS-aware：一律 $order->get_meta / update_meta_data）
 *
 * | Key | 說明 |
 * |---|---|
 * | `_pc_tcat_pay_trade_no` | 目前嘗試的 cust_order_no |
 * | `_pc_tcat_pay_trade_no_history` | 每次嘗試一筆 meta row（非唯一 key），APN / 導轉反查用 |
 * | `_pc_tcat_pay_method` | 子方式 credit / cvs_ibon / atm |
 * | `_pc_tcat_pay_charge_amount` | 送平台的金額快照（元，整數）；金額守衛與退款 order_amount 依據 |
 * | `_pc_tcat_pay_payment_url` | COCS 刷卡短網址 |
 * | `_pc_tcat_pay_payment_info` | CVS / ATM 取號資訊（ibon_code / virtual_account / expire_date…） |
 * | `_pc_tcat_pay_payment_detail` | 平台查詢確認已付款時的查詢結果 |
 * | `_pc_tcat_pay_processed_status` | 冪等：已處理的 `"{cust_order_no}:{status}"` |
 * | `_pc_tcat_pay_reconcile_round` | 自動補單已執行的查詢輪次（TcatPayReconcileJob 退避依據） |
 * | `_pc_tcat_pay_reconcile_failures` | 自動補單連續查詢失敗次數 |
 */
final class TcatPayMetaKeys {

	public const TRADE_NO_KEY           = '_pc_tcat_pay_trade_no';
	public const TRADE_NO_HISTORY_KEY   = '_pc_tcat_pay_trade_no_history';
	public const METHOD_KEY             = '_pc_tcat_pay_method';
	public const CHARGE_AMOUNT_KEY      = '_pc_tcat_pay_charge_amount';
	public const PAYMENT_URL_KEY        = '_pc_tcat_pay_payment_url';
	public const PAYMENT_INFO_KEY       = '_pc_tcat_pay_payment_info';
	public const PAYMENT_DETAIL_KEY     = '_pc_tcat_pay_payment_detail';
	public const PROCESSED_STATUS_KEY   = '_pc_tcat_pay_processed_status';
	public const RECONCILE_ROUND_KEY    = '_pc_tcat_pay_reconcile_round';
	public const RECONCILE_FAILURES_KEY = '_pc_tcat_pay_reconcile_failures';

	/** @var int 冪等陣列保留筆數 */
	private const MAX_PROCESSED = 50;

	/**
	 * Constructor
	 *
	 * @param \WC_Order $order 訂單
	 */
	public function __construct( private readonly \WC_Order $order ) {
	}

	/**
	 * 開始新的付款嘗試：寫入目前編號、追加歷史、記錄金額快照
	 *
	 * @param string $trade_no 交易編號
	 * @param int    $amount   送平台金額（元）
	 * @return void
	 */
	public function start_attempt( string $trade_no, int $amount ): void {
		$this->order->update_meta_data( self::TRADE_NO_KEY, $trade_no );
		if ( ! \in_array( $trade_no, $this->get_trade_no_history(), true ) ) {
			$this->order->add_meta_data( self::TRADE_NO_HISTORY_KEY, $trade_no );
		}
		$this->order->update_meta_data( self::CHARGE_AMOUNT_KEY, (string) $amount );
		$this->order->save();
	}

	/** @return string 目前交易編號 */
	public function get_trade_no(): string {
		return self::to_string( $this->order->get_meta( self::TRADE_NO_KEY ) );
	}

	/** @return array<int, string> 所有嘗試過的交易編號 */
	public function get_trade_no_history(): array {
		$values = [];
		$rows   = $this->order->get_meta( self::TRADE_NO_HISTORY_KEY, false );
		foreach ( \is_array( $rows ) ? $rows : [] as $meta ) {
			$value = self::to_string( $meta instanceof \WC_Meta_Data ? ( $meta->get_data()['value'] ?? '' ) : $meta );
			if ( '' !== $value ) {
				$values[] = $value;
			}
		}
		return $values;
	}

	/**
	 * 交易編號是否屬於本訂單（目前或歷史）
	 *
	 * @param string $trade_no 交易編號
	 * @return bool
	 */
	public function owns_trade_no( string $trade_no ): bool {
		return '' !== $trade_no
		&& ( $trade_no === $this->get_trade_no() || \in_array( $trade_no, $this->get_trade_no_history(), true ) );
	}

	/** @return string 子方式 */
	public function get_method(): string {
		return self::to_string( $this->order->get_meta( self::METHOD_KEY ) );
	}

	/**
	 * @param string $method 子方式
	 * @return void
	 */
	public function update_method( string $method ): void {
		$this->order->update_meta_data( self::METHOD_KEY, $method );
		$this->order->save();
	}

	/** @return int 送平台金額快照（無快照時退回訂單總額四捨五入） */
	public function get_charge_amount(): int {
		$value = $this->order->get_meta( self::CHARGE_AMOUNT_KEY );
		return \is_numeric( $value ) ? (int) $value : (int) \round( (float) $this->order->get_total() );
	}

	/** @return string COCS 刷卡短網址 */
	public function get_payment_url(): string {
		return self::to_string( $this->order->get_meta( self::PAYMENT_URL_KEY ) );
	}

	/**
	 * @param string $url 刷卡短網址
	 * @return void
	 */
	public function update_payment_url( string $url ): void {
		$this->order->update_meta_data( self::PAYMENT_URL_KEY, $url );
		$this->order->save();
	}

	/** @return array<string, mixed> 取號資訊 */
	public function get_payment_info(): array {
		return self::to_array( $this->order->get_meta( self::PAYMENT_INFO_KEY ) );
	}

	/**
	 * @param array<string, mixed> $info 取號資訊
	 * @return void
	 */
	public function update_payment_info( array $info ): void {
		$this->order->update_meta_data( self::PAYMENT_INFO_KEY, $info );
		$this->order->save();
	}

	/** @return array<string, mixed> 付款明細 */
	public function get_payment_detail(): array {
		return self::to_array( $this->order->get_meta( self::PAYMENT_DETAIL_KEY ) );
	}

	/**
	 * @param array<string, mixed> $detail 付款明細
	 * @return void
	 */
	public function update_payment_detail( array $detail ): void {
		$this->order->update_meta_data( self::PAYMENT_DETAIL_KEY, $detail );
		$this->order->save();
	}

	/** @return array<int, string> 已處理的 "{cust_order_no}:{status}" */
	public function get_processed_status(): array {
		$values = $this->order->get_meta( self::PROCESSED_STATUS_KEY );
		if ( ! \is_array( $values ) ) {
			return [];
		}
		return \array_values( \array_map( [ self::class, 'to_string' ], $values ) );
	}

	/**
	 * @param string $key "{cust_order_no}:{status}"
	 * @return bool
	 */
	public function is_processed( string $key ): bool {
		return \in_array( $key, $this->get_processed_status(), true );
	}

	/**
	 * 記錄已處理（只保留最新 MAX_PROCESSED 筆）
	 *
	 * @param string $key "{cust_order_no}:{status}"
	 * @return void
	 */
	public function add_processed_status( string $key ): void {
		$values = $this->get_processed_status();
		if ( \in_array( $key, $values, true ) ) {
			return;
		}
		$values[] = $key;
		$this->order->update_meta_data( self::PROCESSED_STATUS_KEY, \array_slice( $values, -self::MAX_PROCESSED ) );
		$this->order->save();
	}

	/** @return int 自動補單已執行的查詢輪次 */
	public function get_reconcile_round(): int {
		$value = $this->order->get_meta( self::RECONCILE_ROUND_KEY );
		return \is_numeric( $value ) ? (int) $value : 0;
	}

	/** @return int 自動補單連續查詢失敗次數 */
	public function get_reconcile_failures(): int {
		$value = $this->order->get_meta( self::RECONCILE_FAILURES_KEY );
		return \is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * 寫入自動補單狀態
	 *
	 * @param int $round    已執行的查詢輪次
	 * @param int $failures 連續查詢失敗次數
	 * @return void
	 */
	public function update_reconcile_state( int $round, int $failures ): void {
		$this->order->update_meta_data( self::RECONCILE_ROUND_KEY, (string) $round );
		$this->order->update_meta_data( self::RECONCILE_FAILURES_KEY, (string) $failures );
		$this->order->save();
	}

	/**
	 * 以交易編號（歷史任一筆）反查黑貓PAY訂單
	 *
	 * 不從編號 parse 訂單 ID（不信任外部字串）；只認付款方式仍是 tcat_pay 的訂單。
	 *
	 * @param string $trade_no 交易編號
	 * @return \WC_Order|null
	 */
	public static function get_order_by_trade_no( string $trade_no ): ?\WC_Order {
		if ( '' === $trade_no ) {
			return null;
		}

		$orders = \wc_get_orders(
			[
				'limit'      => 2,
				'meta_key'   => self::TRADE_NO_HISTORY_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $trade_no, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			]
		);
		if ( ! \is_array( $orders ) || 1 !== \count( $orders ) ) {
			return null;
		}

		$order = \reset( $orders );
		if ( TcatPayGateway::ID !== $order->get_payment_method() ) {
			return null;
		}
		return $order;
	}

	/**
	 * @param mixed $value 值
	 * @return string
	 */
	private static function to_string( mixed $value ): string {
		return \is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * @param mixed $value 值
	 * @return array<string, mixed>
	 */
	private static function to_array( mixed $value ): array {
		if ( ! \is_array( $value ) ) {
			return [];
		}
		/** @var array<string, mixed> $value */
		return $value;
	}
}
