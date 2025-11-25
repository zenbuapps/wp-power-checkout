<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\Shared\Helpers;

/**
 * 請求、回應參數
 * 每次付款請求，不論是哪種付款方式，都將請求參數、回應參數 raw data 儲存在 order meta 中
 */
class MetaKeys {

	/** @var string 專門儲存第三方金流那邊的識別碼，可以對應訂單 例如：SLP 的 sessionId  */
	private const IDENTITY_KEY = '_pc_identity';

	/** @var string 專門儲存第三方金流那邊的識別碼，可以對應付款(因為一筆訂單可以有多次付款) 例如：SLP 的 tradeOrderId  */
	private const IDENTITY_PAYMENT_KEY = '_pc_payment_identity';

	/** @var string 紀錄付款詳情 */
	private const PAYMENT_DETAIL_KEY = '_pc_payment_detail';

	/** @var string 紀錄退款詳情 */
	private const REFUND_DETAIL_KEY = '_pc_refund_detail';

	/** Construct */
	public function __construct(
		private readonly \WC_Order $_order,
	) {}

	/** @return string 取得訂單識別碼 */
	public function get_identity(): string {
		$payment_detail_array = $this->_order->get_meta( self::IDENTITY_KEY ) ?: '';
		return (string) $payment_detail_array;
	}

	/**
	 * 儲存訂單識別碼
	 *
	 * @param string $value 訂單識別碼
	 * @return void
	 */
	public function update_identity( string $value ): void {
		$this->_order->update_meta_data( self::IDENTITY_KEY, $value );
		$this->_order->save_meta_data();
	}


	/** @return string 取得付款識別碼 */
	public function get_payment_identity(): string {
		$payment_detail_array = $this->_order->get_meta( self::IDENTITY_PAYMENT_KEY ) ?: '';
		return (string) $payment_detail_array;
	}

	/**
	 * 儲存付款識別碼
	 *
	 * @param string $value 付款識別碼
	 * @return void
	 */
	public function update_payment_identity( string $value ): void {
		$this->_order->update_meta_data( self::IDENTITY_PAYMENT_KEY, $value );
		$this->_order->save_meta_data();
	}


	/**
	 * 用 identity_payment_value 取得 Order
	 *
	 * @param string $identity_payment_value identity_payment_key 的值
	 *
	 * @return \WC_Order|null
	 */
	public static function get_order_by_identity_payment_key( string $identity_payment_value ): \WC_Order|null {
		$args = [
			'limit'      => 1,
			'meta_key'   => self::IDENTITY_PAYMENT_KEY, // phpcs:ignore
			'meta_value' => $identity_payment_value,// phpcs:ignore
		];

		$orders = \wc_get_orders($args);
		$order  = \reset($orders);
		return ( $order instanceof \WC_Order ) ? $order : null;
	}


	/** @return array<string, mixed> 取得付款詳情 array */
	public function get_payment_detail(): array {
		$payment_detail_array = $this->_order->get_meta( self::PAYMENT_DETAIL_KEY ) ?: [];
		return is_array($payment_detail_array) ? $payment_detail_array : [];
	}

	/**
	 * 儲存付款詳情 array
	 *
	 * @param array<string, mixed> $value 付款詳情 array
	 * @return void
	 */
	public function update_payment_detail( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_DETAIL_KEY, $value );
		$this->_order->save_meta_data();
	}


	/** @return array<string, mixed> 取得退款詳情 array */
	public function get_refund_detail(): array {
		$refund_detail_array = $this->_order->get_meta( self::REFUND_DETAIL_KEY ) ?: [];
		return \is_array($refund_detail_array) ? $refund_detail_array : [];
	}

	/**
	 * 儲存退款詳情 array
	 *
	 * @param array<string, mixed> $value 付款詳情 array
	 * @return void
	 */
	public function update_refund_detail( array $value ): void {
		$this->_order->update_meta_data( self::REFUND_DETAIL_KEY, $value );
		$this->_order->save_meta_data();
	}
}
