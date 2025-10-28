<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums;

enum ECarrierType: string {

	// 光貿會員載具
	case AMEGO = 'amego';

	// 手機條碼
	case MOBILE = '3J0002';

	// 自然人憑證條碼
	case MOICA = 'CQ0001';
}
