<?php

declare (strict_types = 1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\SettingsDTO;
use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\PowerCheckout\Shared\Abstracts\BaseRegisterIntegration;
use J7\PowerCheckout\Shared\Enums\DomainType;
use J7\PowerCheckout\Utils\IntegrationUtils;

/**
 * Payment Integrations (API 為 base)
 * 整合不同的 Payment Gateway
 * 例如 ECPayAIO 裡面有 ATM, Credit, CVS 等等 Payment Gateway
 */
final class RegisterIntegration extends BaseRegisterIntegration {

	/** @var string* Integration KEY 唯一識別 */
	public static string $integration_key = 'shopline_payment';

	/** @var string* Setting_key KEY 唯一識別 */
	public static string $setting_key = 'shopline_payment_settings';

	/** @var string* Integration 名稱 */
	public static string $name = 'Shopline Payment';

	/** @var string Integration 描述 */
	public static string $description = '';

	/** @var string Integration 圖示 URL */
	public static string $icon_url = 'https://img.shoplineapp.com/media/image_clips/62297669a344ad002979d725/original.png';


	/** @var string 儲存識別碼的參數 key，例如 SLP 的 sessionId */
	public static string $identity_array_key = 'sessionId';

	/** Register hooks */
	public static function register_hooks(): void {
		IntegrationUtils::register(__CLASS__, DomainType::PAYMENTS->value);
		RegisterGateway::register_hooks();
	}


	/** 儲存，可以部分更新
	 *
	 * @param array $data 儲存這個 integration data
	 * @return void
	 * @throws \Exception 如果驗證失敗
	 */
	public static function save_settings( array $data ): void {
		$integration_setting = new SettingsDTO($data);

		$all_settings = SettingTabService::get_settings();

		// 覆寫這個 integration_key 的設定
		$all_settings[ self::$setting_key ] = $integration_setting->to_array(true);
		SettingTabService::save_settings($all_settings);
	}


	/** @return array 取得設定 */
	public static function get_settings(): array {
		$data = SettingTabService::get_settings( self::$setting_key );
		return ( new SettingsDTO( $data) )->to_array(true);
	}
}
