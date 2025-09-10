<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Contracts;

interface IGateway {

	/**
	 * 支付邏輯
	 *
	 * @param int $order_id 訂單 ID
	 * @return array{result: ProcessResult::SUCCESS | ProcessResult::FAILED, redirect?: string}
	 * @throws \Exception 如果訂單不存在
	 */
	public function process_payment( $order_id ): array;
}
