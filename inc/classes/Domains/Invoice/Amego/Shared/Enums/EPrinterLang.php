<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums;

/**
 * 熱感應機編碼
 *
 * @see https://invoice.amego.tw/info_detail?mid=77
 */
enum EPrinterLang: int {
	case BIG5  = 1;
	case GBK   = 2;
	case UTF_8 = 3;
}
