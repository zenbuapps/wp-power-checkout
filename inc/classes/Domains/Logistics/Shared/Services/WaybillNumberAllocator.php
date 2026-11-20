<?php
/**
 * 通用託運單號段原子配號器（P5 新竹物流建立，P7 嘉里大榮共用）
 *
 * 物流商核發號段、由我方配號（新竹「貨號區間」、嘉里大榮 BLN），號碼重複 / 用罄 → 建單失敗。
 *
 * 儲存（皆 autoload = no）：
 *  - `pc_waybill_range_{key}`   = array{start: int, end: int}（流水號本體，不含檢查碼）
 *  - `pc_waybill_current_{key}` = 最後一個已配出的流水號（初始 start - 1）
 *
 * 原子性：配號是**單一 SQL**
 *   `UPDATE wp_options SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)
 *    WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < {end}`
 * 再以同一連線 `SELECT LAST_INSERT_ID()` 取回新值。資料列鎖下的遞增 + 上限判斷由 MySQL 完成，
 * 不經 object cache / transient 的「讀後寫」，兩請求同時配號也不會拿到同號；affected rows = 0 即用罄。
 * 號碼一經配出不回收（API 逾時也不回收，避免同號重送造成重複託運單）。
 *
 * 號碼格式（補零 / 檢查碼）由注入的 IWaybillNumberFormat 決定；配號器不依賴任何 provider。
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Services;

use J7\PowerCheckout\Domains\Logistics\Shared\Exceptions\WaybillNumberException;
use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\IWaybillNumberFormat;
use J7\PowerCheckout\Shared\Errors\ErrorCode;

/** 號段配號器 */
final class WaybillNumberAllocator {

	/** @var string 號段 option 前綴 */
	public const RANGE_OPTION_PREFIX = 'pc_waybill_range_';

	/** @var string 目前值 option 前綴 */
	public const CURRENT_OPTION_PREFIX = 'pc_waybill_current_';

	/**
	 * Constructor
	 *
	 * @param string               $key    號段識別（如 hct_logistics；嘉里大榮可分 kerrytj_logistics_cold）
	 * @param IWaybillNumberFormat $format 號碼格式策略
	 * @throws \InvalidArgumentException Key 不合法
	 */
	public function __construct(
		private readonly string $key,
		private readonly IWaybillNumberFormat $format
	) {
		if (1 !== \preg_match( '/^[a-z0-9_]{1,60}$/', $key )) {
			throw new \InvalidArgumentException( 'WaybillNumberAllocator：key 只允許小寫英數與底線' );
		}
	}

	/**
	 * 設定 / 調整號段
	 *
	 * 目前值仍落在新號段內（含「尚未配號」的 start - 1）則保留（例如只延長迄號），否則從新起號重新開始。
	 *
	 * @param int $start 起號（流水號本體，≥ 1）
	 * @param int $end   迄號（≤ 格式上限）
	 * @return void
	 * @throws WaybillNumberException VALIDATION 號段不合法
	 */
	public function sync_range( int $start, int $end ): void {
		if ($start < 1 || $start > $end || $end > $this->format->max_serial()) {
			throw new WaybillNumberException(
				\sprintf( '號段不合法：起號須 ≥ 1、起號 ≤ 迄號、迄號 ≤ %d', $this->format->max_serial() ),
				ErrorCode::VALIDATION
			);
		}

		$range = $this->get_range();
		if (null === $range || $range['start'] !== $start || $range['end'] !== $end) {
			\update_option(
				$this->range_option(),
				[
					'start' => $start,
					'end'   => $end,
				],
				false
			);
		}

		$current = $this->read_current();
		if (null === $current || $current < $start - 1 || $current > $end) {
			$this->write_current( $start - 1 );
		}
	}

	/**
	 * 清除號段設定（保留目前值，避免重新設定同號段時從頭配號造成重號）
	 *
	 * @return void
	 */
	public function clear_range(): void {
		\delete_option( $this->range_option() );
	}

	/** @return bool 是否已設定號段 */
	public function is_configured(): bool {
		return null !== $this->get_range();
	}

	/**
	 * 原子配出下一個號碼
	 *
	 * @return string 完整託運單號（含檢查碼）
	 * @throws WaybillNumberException VALIDATION 未設定號段 / NUMBER_EXHAUSTED 號段用罄 / UNKNOWN DB 失敗
	 */
	public function allocate(): string {
		global $wpdb;

		$range = $this->get_range();
		if (null === $range) {
			throw new WaybillNumberException( '尚未設定託運單號段', ErrorCode::VALIDATION );
		}
		if (null === $this->read_current()) {
			$this->write_current( $range['start'] - 1 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 原子遞增必須直接下 SQL
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1) WHERE option_name = %s AND CAST(option_value AS UNSIGNED) < %d",
				$this->current_option(),
				$range['end']
			)
		);
		\wp_cache_delete( $this->current_option(), 'options' );

		if (false === $affected) {
			throw new WaybillNumberException( '託運單號配號失敗（資料庫錯誤）', ErrorCode::UNKNOWN );
		}
		if (0 === (int) $affected) {
			throw new WaybillNumberException(
				\sprintf( '託運單號段已用罄（%1$d ~ %2$d），請至設定頁補新號段', $range['start'], $range['end'] ),
				ErrorCode::NUMBER_EXHAUSTED
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 同連線取回 LAST_INSERT_ID
		$serial = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		if ($serial < 1 || $serial > $range['end']) {
			throw new WaybillNumberException( '託運單號配號失敗（無法取回配出值）', ErrorCode::UNKNOWN );
		}

		return $this->format->format( $serial );
	}

	/**
	 * 號段狀態（設定頁顯示用）
	 *
	 * @return array{configured: bool, start: int, end: int, current: int, remaining: int, next: string}
	 */
	public function status(): array {
		$range = $this->get_range();
		if (null === $range) {
			return [
				'configured' => false,
				'start'      => 0,
				'end'        => 0,
				'current'    => 0,
				'remaining'  => 0,
				'next'       => '',
			];
		}

		$current   = $this->read_current() ?? $range['start'] - 1;
		$current   = \max( $range['start'] - 1, \min( $current, $range['end'] ) );
		$remaining = $range['end'] - $current;

		return [
			'configured' => true,
			'start'      => $range['start'],
			'end'        => $range['end'],
			'current'    => $current,
			'remaining'  => $remaining,
			'next'       => $remaining > 0 ? $this->format->format( $current + 1 ) : '',
		];
	}

	/**
	 * 讀號段設定
	 *
	 * @return array{start: int, end: int}|null
	 */
	private function get_range(): ?array {
		$range = \get_option( $this->range_option(), null );
		if (!\is_array( $range ) || !\is_numeric( $range['start'] ?? null ) || !\is_numeric( $range['end'] ?? null )) {
			return null;
		}
		return [
			'start' => (int) $range['start'],
			'end'   => (int) $range['end'],
		];
	}

	/**
	 * 直接讀 DB 目前值（不經 object cache）
	 *
	 * @return int|null 不存在回 null
	 */
	private function read_current(): ?int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 配號狀態必須讀 DB 真值
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $this->current_option() ) );
		return \is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * 寫入目前值（直接寫 DB 並清快取，避免 update_option 比對到過期快取而略過寫入）
	 *
	 * @param int $value 目前值
	 * @return void
	 */
	private function write_current( int $value ): void {
		global $wpdb;
		if (null === $this->read_current()) {
			\add_option( $this->current_option(), (string) $value, '', false );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- 繞過過期快取
			$wpdb->update( $wpdb->options, [ 'option_value' => (string) $value ], [ 'option_name' => $this->current_option() ] );
		}
		\wp_cache_delete( $this->current_option(), 'options' );
	}

	/** @return string 號段 option 名稱 */
	private function range_option(): string {
		return self::RANGE_OPTION_PREFIX . $this->key;
	}

	/** @return string 目前值 option 名稱 */
	private function current_option(): string {
		return self::CURRENT_OPTION_PREFIX . $this->key;
	}
}
