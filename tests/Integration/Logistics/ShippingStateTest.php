<?php
/**
 * ShippingState 物流統一 11 態測試（P0 統一介面，ASM-1）
 *
 * 值對齊 zenbu `apps/api-gateway/src/commerce/shipping/interfaces/shipping-gateway.types.ts` 的 ShippingState。
 * 終態（is_terminal = true）：delivered / picked_up / returned / cancelled / expired_unclaimed / failed。
 *
 * 執行：
 *   vendor/bin/phpunit tests/Integration/Logistics/ --filter ShippingState --no-coverage
 */

declare( strict_types=1 );

namespace Tests\Integration\Logistics;

use J7\PowerCheckout\Domains\Logistics\Shared\Enums\ShippingState;
use Tests\Integration\TestCase;

/**
 * ShippingState 測試
 *
 * @group integration
 * @group logistics
 */
final class ShippingStateTest extends TestCase {

	/**
	 * Happy：11 個 case 值與 zenbu 完全一致（順序亦同）
	 *
	 * @group happy
	 */
	public function test_11個狀態值對齊zenbu(): void {
		$this->assertSame(
			[
				'pending',
				'accepted',
				'in_transit',
				'arrived',
				'ready_for_pickup',
				'picked_up',
				'delivered',
				'returned',
				'failed',
				'expired_unclaimed',
				'cancelled',
			],
			\array_map( static fn( ShippingState $state ): string => $state->value, ShippingState::cases() )
		);
	}

	/**
	 * Happy：終態判定
	 *
	 * @group happy
	 */
	public function test_is_terminal_6個終態為true(): void {
		$terminal = \array_values(
			\array_map(
				static fn( ShippingState $state ): string => $state->value,
				\array_filter( ShippingState::cases(), static fn( ShippingState $state ): bool => $state->is_terminal() )
			)
		);

		$this->assertSame(
			[ 'picked_up', 'delivered', 'returned', 'failed', 'expired_unclaimed', 'cancelled' ],
			$terminal
		);
	}

	/**
	 * Edge：非終態不可誤判（進行中狀態）
	 *
	 * @group edge
	 */
	public function test_進行中狀態非終態(): void {
		foreach ( [ 'pending', 'accepted', 'in_transit', 'arrived', 'ready_for_pickup' ] as $value ) {
			$this->assertFalse( ShippingState::from( $value )->is_terminal(), "{$value} 不應為終態" );
		}
	}

	/**
	 * Edge：未知值 tryFrom 回 null（不 silent 映射）
	 *
	 * @group edge
	 */
	public function test_未知值tryFrom回null(): void {
		$this->assertNull( ShippingState::tryFrom( 'shipped' ) );
		$this->assertNull( ShippingState::tryFrom( '' ) );
	}
}
