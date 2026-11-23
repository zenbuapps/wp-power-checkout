<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\Managers;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfExpressMetaKeys;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfOpcodeMapper;

/**
 * 順豐貨態套用（查詢與推送共用唯一入口）
 *
 * 只准前進：
 *  - opCode 認不得 → 不套用
 *  - 事件時間早於目前已套用的軌跡 → 不套用（推送跨批無序）
 *  - 目前已是終態 → 不套用；唯一例外：已簽收 / 已取件後的「退回」（opCode 99 + otherInfo 46 簽收後退回）
 * 套用時寫 meta；opCode 有變才寫 order note。
 */
final class TrackStatusManager {

	/**
	 * 套用一筆軌跡
	 *
	 * @param \WC_Order            $order  訂單
	 * @param array<string, mixed> $event  軌跡（opCode / opCodeTranslation / localTm / gmt / otherInfo）
	 * @param string               $source 來源（查詢 / 推送；寫入 note）
	 * @return bool 是否已套用
	 */
	public static function apply( \WC_Order $order, array $event, string $source ): bool {
		$op_code = SfOpcodeMapper::field( $event, 'opCode' );
		$state   = SfOpcodeMapper::map( $op_code, SfOpcodeMapper::field( $event, 'otherInfo' ) );
		if (null === $state) {
			return false;
		}

		$local_tm = SfOpcodeMapper::field( $event, 'localTm' );
		$gmt      = SfOpcodeMapper::field( $event, 'gmt' );
		$ts       = SfOpcodeMapper::parse_time( $local_tm, $gmt ) ?? \time();

		$meta    = new SfExpressMetaKeys( $order );
		$current = ShippingState::tryFrom( $meta->get_unified_state() );
		if ($ts < $meta->get_status_ts() || !self::can_transition( $current, $state )) {
			return false;
		}

		$previous = $meta->get_status()['status'];
		$name     = SfOpcodeMapper::field( $event, 'opCodeTranslation' );
		$meta->save_status( $op_code, $name, $state->value, \trim( "{$local_tm} {$gmt}" ), $ts );
		if ($previous !== $op_code || $current !== $state) {
			$order->add_order_note( \sprintf( '順豐貨態更新（%1$s）：%2$s %3$s（%4$s）', $source, $op_code, $name, $meta->get_ref() ) );
		}
		return true;
	}

	/**
	 * 狀態是否可轉移
	 *
	 * @param ShippingState|null $current 目前狀態
	 * @param ShippingState      $next    新狀態
	 * @return bool
	 */
	private static function can_transition( ?ShippingState $current, ShippingState $next ): bool {
		if (null === $current || !$current->is_terminal()) {
			return true;
		}
		return ShippingState::RETURNED === $next && \in_array( $current, [ ShippingState::DELIVERED, ShippingState::PICKED_UP ], true );
	}
}
