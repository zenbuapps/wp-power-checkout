<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Helpers;

/** 每次發票請求，不論是哪種發票，都將資料儲存在 order meta 中 */
class MetaKeys {

	/** @var string 紀錄開立發票的參數  */
	private const ISSUE_INVOICE_PARAMS_KEY = '_pc_issue_invoice_params';

	/** @var string 紀錄開立發票後的資料  */
	private const ISSUED_INVOICE_DATA_KEY = '_pc_issued_invoice_data';

	/** @var string 紀錄取消發票詳情 */
	private const CANCELLED_INVOICE_DATA_KEY = '_pc_cancelled_invoice_data';


	/** @var string 紀錄此訂單是用哪個發票服務開出的 */
	private const SERVICE_ID_KEY = '_pc_invoice_service_id';

	/** Construct */
	public function __construct(
		private readonly \WC_Order $order,
	) {}

	/** @return array 開立發票的參數 */
	public function get_issue_params( string $key = '', mixed $default = null ): mixed {
		$issue_params_array = (array) ( $this->order->get_meta( self::ISSUE_INVOICE_PARAMS_KEY ) ?: [] );
		if (!$key) {
			return $issue_params_array;
		}
		return $issue_params_array[ $key ] ?? $default;
	}

	/**
	 * 更新開立發票的參數
	 *
	 * @param array $value 開立發票的參數
	 * @return void
	 */
	public function update_issue_params( array $value ): void {
		$this->order->update_meta_data( self::ISSUE_INVOICE_PARAMS_KEY, $value );
		$this->order->save_meta_data();
	}


	/**
	 * @param string $key KEY
	 * @param mixed  $default 預設值
	 * @return string 取得開立發票的資料
	 */
	public function get_issued_data( string $key = '', mixed $default = null ): mixed {
		$issue_data_array = (array) ( $this->order->get_meta( self::ISSUED_INVOICE_DATA_KEY ) ?: [] );
		if (!$key) {
			return $issue_data_array;
		}
		return $issue_data_array[ $key ] ?? $default;
	}

	/**
	 * 儲存開立發票的資料
	 *
	 * @param array $value 開立發票的資料
	 * @return void
	 */
	public function update_issued_data( array $value ): void {
		$this->order->update_meta_data( self::ISSUED_INVOICE_DATA_KEY, $value );
		$this->order->save_meta_data();
	}


	/**
	 * 取得取消發票資料 array
	 *
	 * @return array<string, mixed>
	 */
	public function get_cancelled_data(): array {
		$cancel_data_array = $this->order->get_meta( self::CANCELLED_INVOICE_DATA_KEY ) ?: [];
		return \is_array($cancel_data_array) ? $cancel_data_array : [];
	}

	/**
	 * 儲存取消發票資料 array
	 *
	 * @param array<string, mixed> $value 取消發票資料 array
	 * @return void
	 */
	public function update_cancelled_data( array $value ): void {
		$this->order->update_meta_data( self::CANCELLED_INVOICE_DATA_KEY, $value );
		$this->order->save_meta_data();
	}


	/**
	 * 取得電子發票服務 id
	 *
	 * @return string
	 */
	public function get_service_id(): string {
		return $this->order->get_meta( self::SERVICE_ID_KEY ) ?: '';
	}

	/**
	 * 儲存電子發票服務 ID
	 *
	 * @param string $value 電子發票服務 ID
	 * @return void
	 */
	public function update_service_id( string $value ): void {
		$this->order->update_meta_data( self::SERVICE_ID_KEY, $value );
		$this->order->save_meta_data();
	}
}
