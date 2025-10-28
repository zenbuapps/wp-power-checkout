<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums;

/**
 * 熱感應機是否列印明細 1:列印(預設) 0:不列印
 * 打統編一律列印明細
 * 目前僅支援 EPrinterType = 2 可以設定此參數
 */
enum EPrintDetail: int {
	case TRUE  = 1;
	case FALSE = 0;
}
