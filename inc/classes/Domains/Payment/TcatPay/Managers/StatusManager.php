<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Managers;

use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Enums\TcatPayApnStatus;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayMetaKeys;

/**
 * 黑貓PAY 訂單狀態管理（APN / 導轉回報 / 查詢補單共用）
 *
 * 只有「平台查詢確認已收款」才會呼叫 settle_paid()（R1：APN checksum 無金鑰可偽造）。
 *  - 已收款 process_code（明確列舉，不用 >=：CVS 6 逾期、COCS 16/17 失敗 / 取消都落在數值區間內）：
 *    CVS 4 已繳納 / 7 已預約撥款 / 8 已完成撥款；COCS 15 授權完成 / 20 / 21 / 22 請款流程。
 *  - 金額守衛：平台實收（CVS pay_amount、COCS order_amount）與通知金額（若有）都必須等於送單金額快照，
 *    否則不完成、留 note（疑似重複繳款，需人工處理）。
 *  - 非收款狀態（COCS F 授權失敗、CVS D 逾期、M / Q 取消…）只留 note，不改訂單狀態
 *    （CVS 逾期不自動取消，避免誤取消已在途的繳款）。
 *  - 冪等：`_pc_tcat_pay_processed_status` 記 `"{cust_order_no}:{status}"`；只在「確實處理過」時寫入，
 *    平台查詢未確認不寫（偽造 APN 不可預先污染冪等鍵）。
 */
final class StatusManager {

	/** @var array<int, int> CVS 已收款 process_code */
	private const CVS_PAID_CODES = [ 4, 7, 8 ];

	/** @var array<int, int> COCS 已收款 process_code */
	private const COCS_PAID_CODES = [ 15, 20, 21, 22 ];

	/**
	 * Constructor
	 *
	 * @param \WC_Order $order 訂單
	 */
	public function __construct( private readonly \WC_Order $order ) {
	}

	/**
	 * 平台查詢結果是否代表已收款
	 *
	 * @param array<string, mixed> $query  CvsOrderQuery / CocsOrderQuery 回應
	 * @param bool                 $is_cvs 是否 CVS 單
	 * @return bool
	 */
	public static function is_platform_paid( array $query, bool $is_cvs ): bool {
		$code = $query['process_code'] ?? null;
		if ( ! \is_numeric( $code ) ) {
			return false;
		}
		return \in_array( (int) $code, $is_cvs ? self::CVS_PAID_CODES : self::COCS_PAID_CODES, true );
	}

	/**
	 * 平台確認的實收金額（CVS pay_amount、COCS order_amount）
	 *
	 * @param array<string, mixed> $query  查詢回應
	 * @param bool                 $is_cvs 是否 CVS 單
	 * @return int|null
	 */
	public static function platform_amount( array $query, bool $is_cvs ): ?int {
		$value = $query[ $is_cvs ? 'pay_amount' : 'order_amount' ] ?? null;
		return \is_numeric( $value ) ? (int) \round( (float) $value ) : null;
	}

	/**
	 * 以平台查詢結果認列付款
	 *
	 * @param array<string, mixed> $query           平台查詢回應（呼叫端已確認 is_platform_paid）
	 * @param string               $trade_no        交易編號
	 * @param string               $processed_key   冪等鍵 "{trade_no}:{status}"
	 * @param mixed                $notified_amount 通知帶來的金額（無則 null）
	 * @param string               $source          來源（order note 用）
	 * @return bool 是否完成認列
	 */
	public function settle_paid( array $query, string $trade_no, string $processed_key, mixed $notified_amount, string $source ): bool {
		$meta = new TcatPayMetaKeys( $this->order );

		if ( $this->order->is_paid() || $this->order->has_status( [ 'refunded', 'cancelled' ] ) ) {
			$meta->add_processed_status( $processed_key );
			return false;
		}

		$expected = $meta->get_charge_amount();
		$platform = self::platform_amount( $query, \in_array( $meta->get_method(), [ 'cvs_ibon', 'atm' ], true ) );
		$notified = \is_numeric( $notified_amount ) ? (int) \round( (float) $notified_amount ) : null;

		if ( $platform !== $expected || ( null !== $notified && $notified !== $expected ) ) {
			$this->order->add_order_note(
				\sprintf(
					/* translators: 1: 來源 2: 交易編號 3: 應收 4: 平台實收 */
					\__( '⚠️ 黑貓PAY %1$s：金額不符（疑似重複繳款），未自動完成訂單，請人工確認。交易編號 %2$s，應收 %3$d 元，平台實收 %4$s 元', 'power_checkout' ),
					$source,
					$trade_no,
					$expected,
					null === $platform ? '-' : (string) $platform
				)
			);
			$meta->add_processed_status( $processed_key );
			return false;
		}

		$meta->update_payment_detail( $query );
		$this->order->payment_complete( $trade_no );
		$this->order->add_order_note(
			\sprintf(
				/* translators: 1: 來源 2: 交易編號 3: 金額 */
				\__( '✅ 黑貓PAY 付款成功（%1$s，已向平台查詢確認）。交易編號 %2$s，金額 %3$d 元', 'power_checkout' ),
				$source,
				$trade_no,
				$expected
			)
		);
		$meta->add_processed_status( $processed_key );
		return true;
	}

	/**
	 * 非收款狀態：只留 note（冪等），不改訂單狀態
	 *
	 * @param string $trade_no 交易編號
	 * @param string $status   APN status 原始值
	 * @param bool   $is_cvs   是否 CVS 單
	 * @return void
	 */
	public function record_status_note( string $trade_no, string $status, bool $is_cvs ): void {
		$meta = new TcatPayMetaKeys( $this->order );
		$key  = "{$trade_no}:{$status}";
		if ( $meta->is_processed( $key ) ) {
			return;
		}

		$enum  = TcatPayApnStatus::tryFrom( $status );
		$label = null === $enum ? "狀態 {$status}" : $enum->label( $is_cvs );
		$this->order->add_order_note(
			\sprintf(
				/* translators: 1: 狀態 2: 交易編號 */
				\__( '黑貓PAY 通知：%1$s（交易編號 %2$s），訂單狀態未變更', 'power_checkout' ),
				$label,
				$trade_no
			)
		);
		$meta->add_processed_status( $key );
	}
}
