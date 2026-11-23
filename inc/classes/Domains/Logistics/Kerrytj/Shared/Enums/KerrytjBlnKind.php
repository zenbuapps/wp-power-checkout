<?php
/**
 * 嘉里大榮託運單號種類（決定號段、單號格式與貨況端點）
 *
 * - AMBIENT 常溫：11 碼流水號（無檢查碼）→ BLNListTracing
 * - COLD    低溫：9 碼流水號 + MOD 7 檢查碼共 10 碼 → ColdBLNListTracing
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums;

/** 單號種類 */
enum KerrytjBlnKind: string {

	case AMBIENT = 'ambient';
	case COLD    = 'cold';

	/** @return string 中文名稱 */
	public function label(): string {
		return self::AMBIENT === $this ? '常溫' : '低溫';
	}

	/** @return string 貨況查詢端點 */
	public function tracing_endpoint(): string {
		return self::AMBIENT === $this ? 'BLNListTracing' : 'ColdBLNListTracing';
	}
}
