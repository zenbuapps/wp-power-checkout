<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\Contracts\IGatewayService;
use J7\WpUtils\Classes\General;
use J7\PowerCheckout\Domains\Payment\Shared\BlocksIntegration;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\WebHook;

/**
 * Init 初始化付款方式 單例
 */
final class RedirectGatewayService implements IGatewayService {

	/** @var string 付款方式 callback 的 action 前綴 */
	public const PREFIX = 'pc_slp_';

	/** Register hooks */
	public static function register_hooks(): void {
		WebHook::instance();
		// 添加付款方式
		\add_filter( 'woocommerce_payment_gateways', [ __CLASS__ , 'add_method' ] );

		// 整合區塊結帳
		\add_action( 'woocommerce_blocks_payment_method_type_registration', [ __CLASS__, 'register_checkout_blocks' ] );
	}

	/** 添加付款方式 @param array<string> $methods 付款方式 @return array<string> */
	public static function add_method( array $methods ): array {
		$methods[] = RedirectGateway::class;
		return $methods;
	}

	/** 註冊區塊結帳支援 */
	public static function register_checkout_blocks( PaymentMethodRegistry $payment_method_registry ): void {
		if (!\class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
			return;
		}

		$gateways = \WC()->payment_gateways()->payment_gateways;

		$gateway = General::array_find($gateways, static fn( $gateway ) => $gateway->id === RedirectGateway::ID);
		if (!$gateway) {
			return;
		}

		$payment_method_registry->register(new BlocksIntegration($gateway));
	}
}
