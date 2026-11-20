<?php
/**
 * 嘉里大榮溫層（ShipmentDelivery `Temperature`）
 *
 * 1 常溫 / 2 冷藏 / 3 冷凍；常溫用常溫號段（11 碼），冷藏 / 冷凍用低溫號段（10 碼）。
 *
 * @see .claude/skills/kerrytj-logistics/references/api-reference.md §3
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums;

/** 溫層 */
enum KerrytjTemperature: string {

	case AMBIENT = '1';
	case CHILLED = '2';
	case FROZEN  = '3';

	/** @return string 中文名稱 */
	public function label(): string {
		return match ( $this ) {
			self::AMBIENT => '常溫',
			self::CHILLED => '冷藏',
			self::FROZEN  => '冷凍',
		};
	}

	/** @return KerrytjBlnKind 對應的號段種類 */
	public function bln_kind(): KerrytjBlnKind {
		return self::AMBIENT === $this ? KerrytjBlnKind::AMBIENT : KerrytjBlnKind::COLD;
	}
}
