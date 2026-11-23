<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Enums;

/**
 * 黑貓PAY APN status 字母碼（CVS 與 COCS 共用字母、語意不同）
 *
 * 只有「代表已收到錢」的狀態（is_paid_trigger）會觸發平台查詢；
 * 其餘狀態只留 order note、不改訂單狀態（偽造它們放不出任何貨）。
 *
 * @see .claude/skills/tcat-pay/references/callbacks-apn.md §2.1 §2.2
 */
enum TcatPayApnStatus: string {
	case A = 'A';
	case B = 'B';
	case C = 'C';
	case D = 'D';
	case E = 'E';
	case F = 'F';
	case I = 'I';
	case J = 'J';
	case M = 'M';
	case N = 'N';
	case O = 'O';
	case P = 'P';
	case Q = 'Q';
	case R = 'R';

	/**
	 * 是否為「已收款」觸發（需平台查詢確認）
	 *
	 * CVS：B 已繳納、E 已預約撥款；COCS：B 授權完成、O 請款作業中、E 請款完成。
	 *
	 * @param bool $is_cvs 是否 CVS 單
	 * @return bool
	 */
	public function is_paid_trigger( bool $is_cvs ): bool {
		return $is_cvs
		? \in_array( $this, [ self::B, self::E ], true )
		: \in_array( $this, [ self::B, self::O, self::E ], true );
	}

	/**
	 * 顯示名稱
	 *
	 * @param bool $is_cvs 是否 CVS 單
	 * @return string
	 */
	public function label( bool $is_cvs ): string {
		if ( $is_cvs ) {
			return match ( $this ) {
				self::A => '等待繳款人繳納',
				self::B => '繳款人已繳納',
				self::C => '契客註銷',
				self::D => '繳款單已逾期',
				self::E => '已預約撥款',
				self::I => '開立發票通知',
				self::J => '開立發票折讓通知',
				default => "狀態 {$this->value}",
			};
		}
		return match ( $this ) {
			self::B => '授權完成',
			self::O => '請款作業中',
			self::E => '請款完成',
			self::F => '授權失敗',
			self::D => '訂單逾期',
			self::P => '請款失敗',
			self::M => '取消交易完成',
			self::N => '取消交易失敗',
			self::Q => '取消授權完成',
			self::R => '取消授權失敗',
			self::I => '開立發票通知',
			self::J => '開立發票折讓通知',
			default => "狀態 {$this->value}",
		};
	}
}
