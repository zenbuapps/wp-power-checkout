<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Contracts;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;

interface IGatewayService {

	/** 開關 GateWay */
	public static function toggle(): void;

	/** Register hooks */
	public static function register_hooks(): void;

	/** 添加付款方式 @param array<string> $methods 付款方式 @return array<string> */
	public static function add_method( array $methods ): array;

	/** 註冊區塊結帳支援 */
	public static function register_checkout_blocks( PaymentMethodRegistry $payment_method_registry ): void;
}
