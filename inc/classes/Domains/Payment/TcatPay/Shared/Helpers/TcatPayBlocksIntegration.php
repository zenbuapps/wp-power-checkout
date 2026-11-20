<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers;

use J7\PowerCheckout\Domains\Payment\Shared\Helpers\BlocksIntegration;
use J7\PowerCheckout\Domains\Payment\TcatPay\Services\TcatPayGateway;

if ( ! \class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
	return;
}

/**
 * 黑貓PAY 區塊結帳整合
 *
 * 額外提供子方式選項（`tcat_pay_data.methods`）：tcat_pay.tsx 渲染 radio，依購物車金額停用超限選項，
 * 於 onPaymentSetup 以 paymentMethodData 回傳（Store API 會放進 $_POST），後端再依白名單 + 上限驗證。
 */
final class TcatPayBlocksIntegration extends BlocksIntegration {

	/**
	 * 給前端的付款方式資料（共用欄位 + 子方式設定）
	 *
	 * @return array<string, mixed>
	 */
	public function get_payment_method_data(): array {
		return \array_merge(
			parent::get_payment_method_data(),
			[
				'methods' => TcatPayGateway::get_block_method_data(),
			]
		);
	}
}
