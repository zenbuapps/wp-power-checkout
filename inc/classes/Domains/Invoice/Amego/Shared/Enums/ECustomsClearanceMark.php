<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums;

enum ECustomsClearanceMark: int {
	// 非經海關出口
	case NONCUSTOMS = 1;
	// 經海關出口
	case CUSTOMS = 2;
}
