<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Enums;

enum EIndividual: string {
	case CLOUD = 'cloud';

	case BARCODE = 'barcode';

	case MOICA = 'moica';

	case PAPER = 'paper';

	/** 標籤 */
	public function label(): string {
		return match ($this) {
			self::CLOUD => '雲端發票',
			self::BARCODE => '手機條碼',
			self::MOICA => '自然人憑證',
			self::PAPER => '紙本發票',
		};
	}
}
