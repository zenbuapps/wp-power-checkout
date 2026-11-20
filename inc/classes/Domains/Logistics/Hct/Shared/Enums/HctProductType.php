<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\Shared\Enums;

/**
 * 新竹物流商品種類（TransData `eprdcl2`；溫層）
 *
 * @see .claude/skills/hct-logistics/references/api-reference.md §1 field 20
 */
enum HctProductType: string {

	/** 一般（常溫） */
	case NORMAL = '001';

	/** 冷凍 */
	case FROZEN = '003';

	/** 冷藏 */
	case CHILLED = '008';

	/** @return string 顯示名稱 */
	public function label(): string {
		return match ( $this ) {
			self::NORMAL => '常溫',
			self::FROZEN => '冷凍',
			self::CHILLED => '冷藏',
		};
	}
}
