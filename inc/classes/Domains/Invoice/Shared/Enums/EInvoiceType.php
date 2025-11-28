<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Enums;

enum EInvoiceType: string {
	case INDIVIDUAL = 'individual';

	case COMPANY = 'company';

	case DONATE = 'donate';

	/** 標籤 */
	public function label(): string {
		return match ($this) {
			self::INDIVIDUAL => '個人',
			self::COMPANY => '公司',
			self::DONATE => '捐贈',
		};
	}
}
