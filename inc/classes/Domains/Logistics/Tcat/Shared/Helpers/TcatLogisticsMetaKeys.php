<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers;

/**
 * 黑貓宅急便直串訂單 meta（HPOS-aware：一律 $order->get_meta / update_meta_data）
 *
 * | Key | 說明 |
 * |---|---|
 * | `_pc_tcat_logistics_provider_id` | `tcat_logistics`（結帳選用或建單時寫入） |
 * | `_pc_tcat_logistics_thermosphere` | 溫層 0001/0002/0003（結帳 rate meta 寫入；建單沿用） |
 * | `_pc_tcat_logistics_ref` | 託運單號 OBTNumber（12 碼，系統配號） |
 * | `_pc_tcat_logistics_file_no` | PrintOBT 回傳 FileNo（DownloadOBT 用；**24 小時失效**） |
 * | `_pc_tcat_logistics_file_no_created_at` | FileNo 取得時間（unix timestamp） |
 * | `_pc_tcat_logistics_status` | 最新貨態 StatusId（二代 3 碼） |
 * | `_pc_tcat_logistics_status_name` | 最新貨態說明 |
 * | `_pc_tcat_logistics_unified_state` | 統一狀態 ShippingState value（未知碼為空字串） |
 * | `_pc_tcat_logistics_return_ref` | 逆物流（ReserveAdd）託運單號 |
 */
final class TcatLogisticsMetaKeys {

	public const PROVIDER_ID_KEY        = '_pc_tcat_logistics_provider_id';
	public const THERMOSPHERE_KEY       = '_pc_tcat_logistics_thermosphere';
	public const REF_KEY                = '_pc_tcat_logistics_ref';
	public const FILE_NO_KEY            = '_pc_tcat_logistics_file_no';
	public const FILE_NO_CREATED_AT_KEY = '_pc_tcat_logistics_file_no_created_at';
	public const STATUS_KEY             = '_pc_tcat_logistics_status';
	public const STATUS_NAME_KEY        = '_pc_tcat_logistics_status_name';
	public const UNIFIED_STATE_KEY      = '_pc_tcat_logistics_unified_state';
	public const RETURN_REF_KEY         = '_pc_tcat_logistics_return_ref';

	/** @var string provider id 值 */
	private const PROVIDER_ID = 'tcat_logistics';

	/**
	 * Constructor
	 *
	 * @param \WC_Order $order 訂單
	 */
	public function __construct( private readonly \WC_Order $order ) {
	}

	/** @return string 託運單號 */
	public function get_ref(): string {
		return $this->get( self::REF_KEY );
	}

	/** @return string FileNo */
	public function get_file_no(): string {
		return $this->get( self::FILE_NO_KEY );
	}

	/** @return int FileNo 取得時間 */
	public function get_file_no_created_at(): int {
		return (int) $this->get( self::FILE_NO_CREATED_AT_KEY );
	}

	/** @return string 溫層 */
	public function get_thermosphere(): string {
		return $this->get( self::THERMOSPHERE_KEY );
	}

	/** @return string 逆物流單號 */
	public function get_return_ref(): string {
		return $this->get( self::RETURN_REF_KEY );
	}

	/**
	 * 最近一次貨態
	 *
	 * @return array{status: string, status_name: string, unified_state: string}
	 */
	public function get_status(): array {
		return [
			'status'        => $this->get( self::STATUS_KEY ),
			'status_name'   => $this->get( self::STATUS_NAME_KEY ),
			'unified_state' => $this->get( self::UNIFIED_STATE_KEY ),
		];
	}

	/**
	 * 建單成功：寫入託運單號 / FileNo / 取得時間
	 *
	 * @param string $obt_number 託運單號
	 * @param string $file_no    FileNo
	 * @param int    $created_at 取得時間（unix timestamp）
	 * @return void
	 */
	public function save_shipment( string $obt_number, string $file_no, int $created_at ): void {
		$this->order->update_meta_data( self::PROVIDER_ID_KEY, self::PROVIDER_ID );
		$this->order->update_meta_data( self::REF_KEY, $obt_number );
		$this->order->update_meta_data( self::FILE_NO_KEY, $file_no );
		$this->order->update_meta_data( self::FILE_NO_CREATED_AT_KEY, (string) $created_at );
		$this->order->save();
	}

	/**
	 * 結帳選用：寫入溫層與 provider id
	 *
	 * @param string $thermosphere 溫層代碼
	 * @return void
	 */
	public function save_checkout_choice( string $thermosphere ): void {
		$this->order->update_meta_data( self::PROVIDER_ID_KEY, self::PROVIDER_ID );
		$this->order->update_meta_data( self::THERMOSPHERE_KEY, $thermosphere );
		$this->order->save();
	}

	/**
	 * 寫入最新貨態
	 *
	 * @param string $status        StatusId
	 * @param string $status_name   StatusName
	 * @param string $unified_state ShippingState value（未知為空字串）
	 * @return void
	 */
	public function save_status( string $status, string $status_name, string $unified_state ): void {
		$this->order->update_meta_data( self::STATUS_KEY, $status );
		$this->order->update_meta_data( self::STATUS_NAME_KEY, $status_name );
		$this->order->update_meta_data( self::UNIFIED_STATE_KEY, $unified_state );
		$this->order->save();
	}

	/**
	 * 寫入逆物流單號
	 *
	 * @param string $obt_number 逆物流託運單號
	 * @return void
	 */
	public function save_return_ref( string $obt_number ): void {
		$this->order->update_meta_data( self::RETURN_REF_KEY, $obt_number );
		$this->order->save();
	}

	/**
	 * 讀字串 meta
	 *
	 * @param string $key meta key
	 * @return string
	 */
	private function get( string $key ): string {
		$value = $this->order->get_meta( $key );
		return \is_scalar( $value ) ? (string) $value : '';
	}
}
