<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers;

/**
 * 順豐訂單 meta（HPOS-aware：一律 $order->get_meta / update_meta_data）
 *
 * | Key | 說明 |
 * |---|---|
 * | `_pc_sf_express_provider_id` | `sf_express`（結帳選用或建單時寫入） |
 * | `_pc_sf_express_ref` | 順豐運單號 sfWaybillNo（軌跡推送以此反查訂單；取消成功後清空以便重建） |
 * | `_pc_sf_express_customer_order_no` | 送出的客戶訂單號（= 訂單編號） |
 * | `_pc_sf_express_cancelled_ref` | 最近一次已取消的運單號（對帳用） |
 * | `_pc_sf_express_status` | 最新 opCode |
 * | `_pc_sf_express_status_name` | 最新 opCode 描述（opCodeTranslation） |
 * | `_pc_sf_express_unified_state` | 統一狀態 ShippingState value |
 * | `_pc_sf_express_status_time` | 最新軌跡當地時間（localTm + gmt） |
 * | `_pc_sf_express_status_ts` | 最新軌跡 unix 秒（只准前進的比較基準） |
 * | `_pc_sf_express_processed_tracks` | 已處理的推送 trackUniqueId（冪等；保留最近 50 筆） |
 *
 * 面單網址（labelUrl / 列印 url）免認證即可下載且含收件人個資 → **不落庫**。
 */
final class SfExpressMetaKeys {

	public const PROVIDER_ID_KEY       = '_pc_sf_express_provider_id';
	public const REF_KEY               = '_pc_sf_express_ref';
	public const CUSTOMER_ORDER_NO_KEY = '_pc_sf_express_customer_order_no';
	public const CANCELLED_REF_KEY     = '_pc_sf_express_cancelled_ref';
	public const STATUS_KEY            = '_pc_sf_express_status';
	public const STATUS_NAME_KEY       = '_pc_sf_express_status_name';
	public const UNIFIED_STATE_KEY     = '_pc_sf_express_unified_state';
	public const STATUS_TIME_KEY       = '_pc_sf_express_status_time';
	public const STATUS_TS_KEY         = '_pc_sf_express_status_ts';
	public const PROCESSED_TRACKS_KEY  = '_pc_sf_express_processed_tracks';

	/** @var int 冪等紀錄保留筆數 */
	public const PROCESSED_TRACKS_LIMIT = 50;

	/** @var string provider id 值 */
	private const PROVIDER_ID = 'sf_express';

	/**
	 * Constructor
	 *
	 * @param \WC_Order $order 訂單
	 */
	public function __construct( private readonly \WC_Order $order ) {
	}

	/**
	 * 以順豐運單號反查訂單（空字串不查）
	 *
	 * @param string $waybill 運單號
	 * @return \WC_Order|null
	 */
	public static function find_order_by_ref( string $waybill ): ?\WC_Order {
		if ('' === $waybill) {
			return null;
		}
		$orders = \wc_get_orders(
			[
				'limit'      => 1,
				'meta_key'   => self::REF_KEY, // phpcs:ignore
				'meta_value' => $waybill, // phpcs:ignore
			]
		);
		$order  = \is_array( $orders ) ? \reset( $orders ) : false;
		return $order instanceof \WC_Order ? $order : null;
	}

	/** @return string 順豐運單號 */
	public function get_ref(): string {
		return $this->get( self::REF_KEY );
	}

	/** @return string 統一狀態 */
	public function get_unified_state(): string {
		return $this->get( self::UNIFIED_STATE_KEY );
	}

	/** @return int 最新軌跡 unix 秒（0 = 無） */
	public function get_status_ts(): int {
		return (int) $this->get( self::STATUS_TS_KEY );
	}

	/**
	 * 最近一次貨態
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
	 * 建單成功：寫入運單號 / 客戶訂單號，狀態歸 pending（清掉前一張已取消運單的貨態）
	 *
	 * @param string $waybill           運單號
	 * @param string $customer_order_no 客戶訂單號
	 * @param string $pending_state     ShippingState::PENDING value
	 * @return void
	 */
	public function save_shipment( string $waybill, string $customer_order_no, string $pending_state ): void {
		$this->order->update_meta_data( self::PROVIDER_ID_KEY, self::PROVIDER_ID );
		$this->order->update_meta_data( self::REF_KEY, $waybill );
		$this->order->update_meta_data( self::CUSTOMER_ORDER_NO_KEY, $customer_order_no );
		$this->order->update_meta_data( self::STATUS_KEY, '' );
		$this->order->update_meta_data( self::STATUS_NAME_KEY, '' );
		$this->order->update_meta_data( self::UNIFIED_STATE_KEY, $pending_state );
		$this->order->update_meta_data( self::STATUS_TIME_KEY, '' );
		$this->order->update_meta_data( self::STATUS_TS_KEY, '0' );
		$this->order->save();
	}

	/** 結帳選用：寫入 provider id @return void */
	public function save_checkout_choice(): void {
		$this->order->update_meta_data( self::PROVIDER_ID_KEY, self::PROVIDER_ID );
		$this->order->save();
	}

	/**
	 * 寫入最新貨態
	 *
	 * @param string $status        opCode
	 * @param string $status_name   opCode 描述
	 * @param string $unified_state ShippingState value
	 * @param string $status_time   當地時間（含 gmt）
	 * @param int    $status_ts     unix 秒
	 * @return void
	 */
	public function save_status( string $status, string $status_name, string $unified_state, string $status_time, int $status_ts ): void {
		$this->order->update_meta_data( self::STATUS_KEY, $status );
		$this->order->update_meta_data( self::STATUS_NAME_KEY, $status_name );
		$this->order->update_meta_data( self::UNIFIED_STATE_KEY, $unified_state );
		$this->order->update_meta_data( self::STATUS_TIME_KEY, $status_time );
		$this->order->update_meta_data( self::STATUS_TS_KEY, (string) $status_ts );
		$this->order->save();
	}

	/**
	 * 取消成功：清運單號（可重建）、保留已取消運單號、狀態 cancelled
	 *
	 * @param string $cancelled_state ShippingState::CANCELLED value
	 * @param string $status_time     取消時間
	 * @return void
	 */
	public function save_cancelled( string $cancelled_state, string $status_time ): void {
		$this->order->update_meta_data( self::CANCELLED_REF_KEY, $this->get_ref() );
		$this->order->update_meta_data( self::REF_KEY, '' );
		$this->order->update_meta_data( self::STATUS_KEY, 'CANCELLED' );
		$this->order->update_meta_data( self::STATUS_NAME_KEY, '已取消託運單' );
		$this->order->update_meta_data( self::UNIFIED_STATE_KEY, $cancelled_state );
		$this->order->update_meta_data( self::STATUS_TIME_KEY, $status_time );
		$this->order->save();
	}

	/**
	 * 推送冪等：是否已處理過
	 *
	 * @param string $key 冪等鍵
	 * @return bool
	 */
	public function is_track_processed( string $key ): bool {
		return \in_array( $key, $this->processed_tracks(), true );
	}

	/**
	 * 推送冪等：標記已處理（保留最近 50 筆）
	 *
	 * @param string $key 冪等鍵
	 * @return void
	 */
	public function mark_track_processed( string $key ): void {
		$keys   = $this->processed_tracks();
		$keys[] = $key;
		$this->order->update_meta_data( self::PROCESSED_TRACKS_KEY, \array_slice( \array_values( \array_unique( $keys ) ), -self::PROCESSED_TRACKS_LIMIT ) );
		$this->order->save();
	}

	/** @return array<int, string> 已處理的冪等鍵 */
	private function processed_tracks(): array {
		$value = $this->order->get_meta( self::PROCESSED_TRACKS_KEY );
		return \is_array( $value ) ? \array_values( \array_filter( $value, 'is_string' ) ) : [];
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
