<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums;

/* 明細的單價及小計 為 含稅價 或 未稅價 預設為含稅價 */
enum EDetailAmountRound: int {

	// 小數精準度到7位數
	case DECIMAL = 0;
	// 一律四捨五入到整數
	case ROUND_TO_INT = 1;
}
