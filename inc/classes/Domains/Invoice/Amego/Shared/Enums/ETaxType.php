<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums;

enum ETaxType: int {
	// 應稅
	case TAXABLE = 1;
	// 零稅率
	case ZERO_RATED = 2;
	// 免稅
	case EXEMPT = 3;
	// 應稅（特種稅率）
	case SPECIAL = 4;
	// 混合應稅與免稅或零稅率（限 C0401）
	case MIXED = 9;
}
