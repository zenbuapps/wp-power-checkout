<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Enums;

/**
 * 黑貓PAY 子付款方式（結帳欄位 pc_tcat_pay_method 的值）
 *
 * CVS 的「繳款方式 × 收單行」只有兩種合法組合（zenbu kolet.types.ts KOLET_CVS_CHANNELS）：
 *  - ibon 超商代碼 → payment_type=0 + payment_acquirerType=2（安源），預設上限 20,000
 *  - ATM 虛擬帳號 → payment_type=1 + payment_acquirerType=3（統一金流），預設上限 49,999
 * 由子方式一次決定，結構上排除必被平台退件的組合。COCS 刷卡預設上限 100,000（依合約）。
 *
 * @see .claude/skills/tcat-pay/references/codes-reference.md §4 §5
 */
enum TcatPayMethod: string {

	/** COCS 線上刷卡（刷卡短網址） */
	case CREDIT = 'credit';

	/** CVS ibon 超商代碼（安源） */
	case CVS_IBON = 'cvs_ibon';

	/** ATM 虛擬帳號（統一金流） */
	case ATM = 'atm';

	/** @return string 顯示名稱 */
	public function label(): string {
		return match ( $this ) {
			self::CREDIT   => \__( '信用卡', 'power_checkout' ),
			self::CVS_IBON => \__( 'ibon 超商代碼繳費', 'power_checkout' ),
			self::ATM      => \__( 'ATM 虛擬帳號轉帳', 'power_checkout' ),
		};
	}

	/** @return bool 是否為 CVS 取號類（無線上退款 API） */
	public function is_cvs(): bool {
		return self::CREDIT !== $this;
	}

	/** @return int 平台預設單筆上限（元） */
	public function default_max_amount(): int {
		return match ( $this ) {
			self::CREDIT   => 100000,
			self::CVS_IBON => 20000,
			self::ATM      => 49999,
		};
	}

	/** @return string CvsOrderAppend payment_type（credit 不適用回空字串） */
	public function payment_type(): string {
		return match ( $this ) {
			self::CVS_IBON => '0',
			self::ATM      => '1',
			self::CREDIT   => '',
		};
	}

	/** @return string CvsOrderAppend payment_acquirerType（credit 不適用回空字串） */
	public function payment_acquirer_type(): string {
		return match ( $this ) {
			self::CVS_IBON => '2',
			self::ATM      => '3',
			self::CREDIT   => '',
		};
	}

	/** @return string 設定欄位名（金額上限） */
	public function max_amount_setting_key(): string {
		return match ( $this ) {
			self::CREDIT   => 'max_amount_credit',
			self::CVS_IBON => 'max_amount_cvs',
			self::ATM      => 'max_amount_atm',
		};
	}
}
