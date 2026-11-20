<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\Shared\Helpers;

/**
 * 新竹物流訂單 meta（HPOS-aware：一律 $order->get_meta / update_meta_data）
 *
 * | Key | 說明 |
 * |---|---|
 * | `_pc_hct_logistics_provider_id` | `hct_logistics`（結帳選用或建單時寫入） |
 * | `_pc_hct_logistics_product_type` | 商品種類 001 / 003 / 008（結帳 rate meta 寫入；建單沿用） |
 * | `_pc_hct_logistics_ref` | 新竹貨號 edelno（10 碼含檢查碼） |
 * | `_pc_hct_logistics_epino` | 送出的訂單編號 epino（取消 / 退貨 / 對帳用） |
 * | `_pc_hct_logistics_esdate` | 出貨日期 yyyyMMdd |
 * | `_pc_hct_logistics_arrival_station` | 到著站 erstno |
 * | `_pc_hct_logistics_label` | 標籤圖（base64；建單回傳 image，列印時直接輸出不再打 API） |
 * | `_pc_hct_logistics_status` | 最新貨況代碼 status_code |
 * | `_pc_hct_logistics_status_name` | 最新貨況說明 |
 * | `_pc_hct_logistics_unified_state` | 統一狀態 ShippingState value |
 * | `_pc_hct_logistics_status_time` | 最新貨況時間 |
 * | `_pc_hct_logistics_return_ref` | 逆物流（退貨）貨號 |
 */
final class HctLogisticsMetaKeys {

	public const PROVIDER_ID_KEY     = '_pc_hct_logistics_provider_id';
	public const PRODUCT_TYPE_KEY    = '_pc_hct_logistics_product_type';
	public const REF_KEY             = '_pc_hct_logistics_ref';
	public const EPINO_KEY           = '_pc_hct_logistics_epino';
	public const ESDATE_KEY          = '_pc_hct_logistics_esdate';
	public const ARRIVAL_STATION_KEY = '_pc_hct_logistics_arrival_station';
	public const LABEL_KEY           = '_pc_hct_logistics_label';
	public const STATUS_KEY          = '_pc_hct_logistics_status';
	public const STATUS_NAME_KEY     = '_pc_hct_logistics_status_name';
	public const UNIFIED_STATE_KEY   = '_pc_hct_logistics_unified_state';
	public const STATUS_TIME_KEY     = '_pc_hct_logistics_status_time';
	public const RETURN_REF_KEY      = '_pc_hct_logistics_return_ref';

	/** @var string provider id 值 */
	private const PROVIDER_ID = 'hct_logistics';

	/**
	 * Constructor
	 *
	 * @param \WC_Order $order 訂單
	 */
	public function __construct( private readonly \WC_Order $order ) {
	}

	/** @return string 新竹貨號 */
	public function get_ref(): string {
		return $this->get( self::REF_KEY );
	}

	/** @return string 送出的訂單編號 */
	public function get_epino(): string {
		return $this->get( self::EPINO_KEY );
	}

	/** @return string 標籤圖 base64 */
	public function get_label(): string {
		return $this->get( self::LABEL_KEY );
	}

	/** @return string 商品種類 */
	public function get_product_type(): string {
		return $this->get( self::PRODUCT_TYPE_KEY );
	}

	/** @return string 逆物流貨號 */
	public function get_return_ref(): string {
		return $this->get( self::RETURN_REF_KEY );
	}

	/**
	 * 最近一次貨況
	 *
	 * @return array{status: string, status_name: string, unified_state: string, status_time: string}
	 */
	public function get_status(): array {
		return [
			'status'        => $this->get( self::STATUS_KEY ),
			'status_name'   => $this->get( self::STATUS_NAME_KEY ),
			'unified_state' => $this->get( self::UNIFIED_STATE_KEY ),
			'status_time'   => $this->get( self::STATUS_TIME_KEY ),
		];
	}

	/**
	 * 建單成功：寫入貨號 / 訂單編號 / 出貨日 / 到著站 / 標籤
	 *
	 * @param string $edelno          新竹貨號
	 * @param string $epino           訂單編號
	 * @param string $esdate          出貨日期 yyyyMMdd
	 * @param string $arrival_station 到著站
	 * @param string $label_base64    標籤圖 base64（無則空字串）
	 * @return void
	 */
	public function save_shipment( string $edelno, string $epino, string $esdate, string $arrival_station, string $label_base64 ): void {
		$this->order->update_meta_data( self::PROVIDER_ID_KEY, self::PROVIDER_ID );
		$this->order->update_meta_data( self::REF_KEY, $edelno );
		$this->order->update_meta_data( self::EPINO_KEY, $epino );
		$this->order->update_meta_data( self::ESDATE_KEY, $esdate );
		$this->order->update_meta_data( self::ARRIVAL_STATION_KEY, $arrival_station );
		$this->order->update_meta_data( self::LABEL_KEY, $label_base64 );
		$this->order->save();
	}

	/**
	 * 結帳選用：寫入商品種類與 provider id
	 *
	 * @param string $product_type 商品種類
	 * @return void
	 */
	public function save_checkout_choice( string $product_type ): void {
		$this->order->update_meta_data( self::PROVIDER_ID_KEY, self::PROVIDER_ID );
		$this->order->update_meta_data( self::PRODUCT_TYPE_KEY, $product_type );
		$this->order->save();
	}

	/**
	 * 寫入最新貨況
	 *
	 * @param string $status        status_code
	 * @param string $status_name   貨況說明
	 * @param string $unified_state ShippingState value
	 * @param string $status_time   貨況時間
	 * @return void
	 */
	public function save_status( string $status, string $status_name, string $unified_state, string $status_time ): void {
		$this->order->update_meta_data( self::STATUS_KEY, $status );
		$this->order->update_meta_data( self::STATUS_NAME_KEY, $status_name );
		$this->order->update_meta_data( self::UNIFIED_STATE_KEY, $unified_state );
		$this->order->update_meta_data( self::STATUS_TIME_KEY, $status_time );
		$this->order->save();
	}

	/**
	 * 寫入逆物流貨號
	 *
	 * @param string $edelno 退貨貨號
	 * @return void
	 */
	public function save_return_ref( string $edelno ): void {
		$this->order->update_meta_data( self::RETURN_REF_KEY, $edelno );
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
