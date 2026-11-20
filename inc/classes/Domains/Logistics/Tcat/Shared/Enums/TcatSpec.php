<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Enums;

/**
 * 黑貓包裹規格 Spec（api-reference.md §13.4；宅配 / 逆物流四種皆可用）
 */
enum TcatSpec: string {
	/** 60cm */
	case CM60 = '0001';
	/** 90cm */
	case CM90 = '0002';
	/** 120cm */
	case CM120 = '0003';
	/** 150cm（低溫不可用，E020） */
	case CM150 = '0004';

	/** @return string 中文標籤 */
	public function label(): string {
		return match ( $this ) {
			self::CM60  => '60cm',
			self::CM90  => '90cm',
			self::CM120 => '120cm',
			self::CM150 => '150cm',
		};
	}
}
