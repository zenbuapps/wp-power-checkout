<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\Contracts\IRegisterGateway;
use J7\WpUtils\Classes\General;
use J7\PowerCheckout\Domains\Payment\Shared\BlocksIntegration;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\WebHook;
use J7\PowerCheckout\Utils\IntegrationUtils;

/**
 * Payment Gateway (API 為 base)
 * 整合不同的 Payment Gateway
 * 例如 ECPayAIO 裡面有 ATM, Credit, CVS 等等 Payment Gateway
 */
final class RegisterGateway implements IRegisterGateway {

	/** Register hooks */
	public static function register_hooks(): void {
		$integration = IntegrationUtils::get_integration( RegisterIntegration::$integration_key);

		if (!$integration?->enabled) {
			return;
		}

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
