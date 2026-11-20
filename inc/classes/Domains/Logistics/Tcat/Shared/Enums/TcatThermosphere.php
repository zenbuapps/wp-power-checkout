<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Enums;

/**
 * 黑貓溫層 Thermosphere（api-reference.md §13.3）
 */
enum TcatThermosphere: string {
	/** 常溫 */
	case NORMAL = '0001';
	/** 冷藏 */
	case REFRIGERATED = '0002';
	/** 冷凍 */
	case FROZEN = '0003';

	/** @return string 中文標籤 */
	public function label(): string {
		return match ( $this ) {
			self::NORMAL       => '常溫',
			self::REFRIGERATED => '冷藏',
			self::FROZEN       => '冷凍',
		};
	}

	/** @return bool 是否低溫（低溫不可 150cm，E020） */
	public function is_cold(): bool {
		return self::NORMAL !== $this;
	}
}
