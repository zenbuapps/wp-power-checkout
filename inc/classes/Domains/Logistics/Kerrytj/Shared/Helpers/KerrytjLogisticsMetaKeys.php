<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers;

/**
 * 嘉里大榮訂單 meta（HPOS-aware：一律 $order->get_meta / update_meta_data）
 *
 * | Key | 說明 |
 * |---|---|
 * | `_pc_kerrytj_logistics_provider_id` | `kerrytj_logistics` |
 * | `_pc_kerrytj_logistics_temperature` | 溫層 1 / 2 / 3（結帳 rate meta 寫入；建單沿用） |
 * | `_pc_kerrytj_logistics_pending_bln` | 已配出、尚未確認建單成功的託運單號（先記再打 API；失敗重試沿用，號碼不回收） |
 * | `_pc_kerrytj_logistics_ref` | 託運單號 BLN（建單成功） |
 * | `_pc_kerrytj_logistics_mode` | 建單環境 test / prod（列印 / 修改須同環境帳密） |
 * | `_pc_kerrytj_logistics_shipment` | 建單送出的 34 欄快照 JSON（標籤 / 明細 / 修改的唯一來源） |
 * | `_pc_kerrytj_logistics_station` | 到著站名稱（AreaData.StationName） |
 * | `_pc_kerrytj_logistics_status` / `_status_name` / `_unified_state` / `_status_time` | 最新貨況 |
 * | `_pc_kerrytj_logistics_return_pending_bln` | 已配出、尚未確認的取件單號 |
 * | `_pc_kerrytj_logistics_return_ref` | 取件（退貨）單號 |
 */
final class KerrytjLogisticsMetaKeys {

	public const PROVIDER_ID_KEY        = '_pc_kerrytj_logistics_provider_id';
	public const TEMPERATURE_KEY        = '_pc_kerrytj_logistics_temperature';
	public const PENDING_BLN_KEY        = '_pc_kerrytj_logistics_pending_bln';
	public const REF_KEY                = '_pc_kerrytj_logistics_ref';
	public const MODE_KEY               = '_pc_kerrytj_logistics_mode';
	public const SHIPMENT_KEY           = '_pc_kerrytj_logistics_shipment';
	public const STATION_KEY            = '_pc_kerrytj_logistics_station';
	public const STATUS_KEY             = '_pc_kerrytj_logistics_status';
	public const STATUS_NAME_KEY        = '_pc_kerrytj_logistics_status_name';
	public const UNIFIED_STATE_KEY      = '_pc_kerrytj_logistics_unified_state';
	public const STATUS_TIME_KEY        = '_pc_kerrytj_logistics_status_time';
	public const RETURN_PENDING_BLN_KEY = '_pc_kerrytj_logistics_return_pending_bln';
	public const RETURN_REF_KEY         = '_pc_kerrytj_logistics_return_ref';

	/** @var string provider id 值 */
	private const PROVIDER_ID = 'kerrytj_logistics';

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

	/** @return string 尚未確認的託運單號 */
	public function get_pending_bln(): string {
		return $this->get( self::PENDING_BLN_KEY );
	}

	/** @return string 建單環境 */
	public function get_mode(): string {
		return $this->get( self::MODE_KEY );
	}

	/** @return string 結帳溫層 */
	public function get_temperature(): string {
		return $this->get( self::TEMPERATURE_KEY );
	}

	/** @return string 到著站 */
	public function get_station(): string {
		return $this->get( self::STATION_KEY );
	}

	/** @return string 取件單號 */
	public function get_return_ref(): string {
		return $this->get( self::RETURN_REF_KEY );
	}

	/** @return string 尚未確認的取件單號 */
	public function get_return_pending_bln(): string {
		return $this->get( self::RETURN_PENDING_BLN_KEY );
	}

	/** @return array<string, string> 建單 34 欄快照（無則空陣列） */
	public function get_shipment(): array {
		$decoded = \json_decode( $this->get( self::SHIPMENT_KEY ), true );
		if (!\is_array( $decoded )) {
			return [];
		}
		$row = [];
		foreach ( $decoded as $key => $value ) {
			if (\is_string( $key ) && \is_scalar( $value )) {
				$row[ $key ] = (string) $value;
			}
		}
		return $row;
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
	 * 結帳選用：寫入溫層與 provider id
	 *
	 * @param string $temperature 溫層
	 * @return void
	 */
	public function save_checkout_choice( string $temperature ): void {
		$this->order->update_meta_data( self::PROVIDER_ID_KEY, self::PROVIDER_ID );
		$this->order->update_meta_data( self::TEMPERATURE_KEY, $temperature );
		$this->order->save();
	}

	/**
	 * 配號後、呼叫 API 前先記下單號（失敗重試沿用）
	 *
	 * @param string $bln 託運單號
	 * @return void
	 */
	public function save_pending_bln( string $bln ): void {
		$this->order->update_meta_data( self::PENDING_BLN_KEY, $bln );
		$this->order->save();
	}

	/**
	 * 建單成功：寫入單號 / 環境 / 快照 / 到著站，清除 pending
	 *
	 * @param string                $bln     託運單號
	 * @param string                $mode    環境
	 * @param array<string, string> $row     34 欄
	 * @param string                $station 到著站
	 * @return void
	 */
	public function save_shipment( string $bln, string $mode, array $row, string $station ): void {
		$this->order->update_meta_data( self::PROVIDER_ID_KEY, self::PROVIDER_ID );
		$this->order->update_meta_data( self::REF_KEY, $bln );
		$this->order->update_meta_data( self::MODE_KEY, $mode );
		$this->order->update_meta_data( self::SHIPMENT_KEY, (string) \wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) );
		$this->order->update_meta_data( self::STATION_KEY, $station );
		$this->order->update_meta_data( self::TEMPERATURE_KEY, (string) ( $row['Temperature'] ?? '' ) );
		$this->order->delete_meta_data( self::PENDING_BLN_KEY );
		$this->order->save();
	}

	/**
	 * 修改成功：更新快照
	 *
	 * @param array<string, string> $row 34 欄
	 * @return void
	 */
	public function save_shipment_row( array $row ): void {
		$this->order->update_meta_data( self::SHIPMENT_KEY, (string) \wp_json_encode( $row, JSON_UNESCAPED_UNICODE ) );
		$this->order->save();
	}

	/**
	 * 寫入最新貨況
	 *
	 * @param string $status        StatusCode
	 * @param string $status_name   說明
	 * @param string $unified_state ShippingState value
	 * @param string $status_time   時間 yyyyMMdd HHmmss
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
	 * 記下尚未確認的取件單號
	 *
	 * @param string $bln 取件單號
	 * @return void
	 */
	public function save_return_pending_bln( string $bln ): void {
		$this->order->update_meta_data( self::RETURN_PENDING_BLN_KEY, $bln );
		$this->order->save();
	}

	/**
	 * 取件成功：寫入取件單號、清除 pending
	 *
	 * @param string $bln 取件單號
	 * @return void
	 */
	public function save_return_ref( string $bln ): void {
		$this->order->update_meta_data( self::RETURN_REF_KEY, $bln );
		$this->order->delete_meta_data( self::RETURN_PENDING_BLN_KEY );
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
