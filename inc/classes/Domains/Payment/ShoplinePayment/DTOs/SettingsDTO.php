<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services\RegisterIntegration;
use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

/**
 * Shopline 跳轉支付設定，單例
 */
final class SettingsDTO extends DTO {

	/** @var string $mode Enums\Mode::value 模式  */
	public string $mode;
	/** @var string SLP 平台 ID，平台特店必填，平台特店底下會有子特店 */
	public string $platformId;
	/** @var string *直連特店串接：SLP 分配的特店 ID；平台特店串接：SLP 分配的子特店 ID */
	public string $merchantId = '';
	/** @var string *API 介面金鑰 */
	public string $apiKey = '';
	/** @var string 客戶端金鑰 */
	public string $clientKey = '';
	/** @var string 端點 */
	public string $apiUrl = 'https://api.shoplinepayments.com';
	/** @var string 簽名密鑰，需要設定完 webhook 後，由 shopline 窗口提供 @see https://docs.shoplinepayments.com/api/event/model/#簽章演算法 */
	public string $signKey = '';

	/** @var array<string> $allowPaymentMethodList array<Enums\PaymentMethod::value> 允許的付款方式 */
	public array $allowPaymentMethodList = [
		'CreditCard',
		'VirtualAccount',
		'JKOPay',
		'ApplePay',
		'LinePay',
		'ChaileaseBNPL',
	];

	/**
	 * 實例化後，如果是 測試模式就修改屬性
	 *
	 * @return void
	 */
	protected function after_init(): void {
		$integration_settings = SettingTabService::get_settings(RegisterIntegration::$setting_key);

		$mode = $integration_settings['mode'] ?? Enums\Mode::TEST->value;
		if (Enums\Mode::TEST->value === $mode) {
			$this->mode       = Enums\Mode::TEST->value;
			$this->merchantId = '3252264968486264832';
			$this->apiKey     = 'sk_sandbox_fc8d1884a9064b6ba4b2cc16d124663c';
			$this->clientKey  = 'pk_sandbox_f03ae82192c946888fbf0901b8d2053a';
			$this->apiUrl     = 'https://api-sandbox.shoplinepayments.com';
			// TODO 這 signKey 是 partnerdemo 的 signKey，需要改成實際的 signKey
			$this->signKey = 'fea6681d4e8f4889ac06f944450e43b7';
		}

		if ( !empty( $this->signKey ) ) {
			$this->signKey = \mb_convert_encoding($this->signKey, 'UTF-8', 'auto');
		}
	}

	/**
	 * @param bool $raw true: 拿原始資料
	 *
	 * @return array
	 */
	public function to_array( bool $raw = false ): array {
		if ($raw) {
			$default_array = [
				'mode'                   => Enums\Mode::TEST->value,
				'allowPaymentMethodList' => [
					'CreditCard',
					'VirtualAccount',
					'JKOPay',
					'ApplePay',
					'LinePay',
					'ChaileaseBNPL',
				],
			];
			return \wp_parse_args( $this->dto_data, $default_array);
		}

		return parent::to_array();
	}


	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *
	 * @noinspection PhpExpressionResultUnusedInspection
	 */
	public function validate(): void {
		parent::validate();
		Enums\Mode::from( $this->mode );
		foreach ( $this->allowPaymentMethodList as $payment_method ) {
			Enums\PaymentMethod::from( $payment_method );
		}
	}
}
