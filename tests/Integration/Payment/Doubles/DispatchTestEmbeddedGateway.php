<?php

declare( strict_types=1 );

namespace Tests\Integration\Payment\Doubles;

use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IEmbeddedPaymentProvider;

/**
 * 測試替身：嵌入式 gateway
 */
final class DispatchTestEmbeddedGateway extends AbstractPaymentGateway implements IEmbeddedPaymentProvider {

	/** @var string gateway ID */
	const ID = 'pc_test_dispatch_embedded_gateway';

	/** @var array<int, array{order_id: int, payload: array<string, mixed>}> dispatch 呼叫紀錄 */
	public static array $calls = [];

	/** @var \Throwable|null dispatch 時拋出的例外 */
	public static ?\Throwable $throw = null;

	/** @var string gateway ID（WC_Payment_Gateway 屬性） */
	public $id = self::ID;

	/**
	 * 最小設定
	 *
	 * @param bool $with_default 是否帶預設值
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		return [
			'enabled' => 'yes',
			'title'   => '測試嵌入式替身',
		];
	}

	/**
	 * 兩段式 dispatch
	 *
	 * @param \WC_Order            $order   訂單
	 * @param array<string, mixed> $payload 前端 payload
	 * @return array<string, mixed>
	 * @throws \Throwable 測試注入的例外
	 */
	public function dispatch( \WC_Order $order, array $payload ): array {
		self::$calls[] = [
			'order_id' => $order->get_id(),
			'payload'  => $payload,
		];
		if ( null !== self::$throw ) {
			throw self::$throw;
		}
		return [
			'mode'     => 'client-sdk',
			'provider' => self::ID,
			'phase'    => isset( $payload['pay_session'] ) ? 'confirm' : 'init',
		];
	}
}
