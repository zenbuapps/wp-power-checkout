<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums;

/* 明細的單價及小計 為 含稅價 或 未稅價 預設為含稅價 */
enum EDetailVat: int {

	// 未稅價
	case EXCLUDING_TAX = 0;
	// 含稅價
	case INCLUDING_TAX = 1;
}
