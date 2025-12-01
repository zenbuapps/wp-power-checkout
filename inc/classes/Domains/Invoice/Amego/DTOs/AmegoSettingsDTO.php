<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\PowerCheckout\Domains\Invoice\Amego\Services\AmegoProvider;
use J7\PowerCheckout\Shared\DTOs\BaseSettingsDTO;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;

final class AmegoSettingsDTO extends BaseSettingsDTO {
	// region 基礎通用欄位

	/** @var string $id Id */
	public string $id = AmegoProvider::ID;

	/** @var string 付款方式 icon */
	public string $icon = 'https://invoice-static.amego.tw/www/images/amego_20231206.svg'; // 'https://invoice-static.amego.tw/www/images/amego_1024_icon.png'

	/** @var string 前台顯示付款方式標題 */
	public string $title = '光貿電子發票';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '光貿電子發票加值中心-電子發票系統，不綁約、無限制開立張數、月費199元開到飽。免費協助營業人快速申請用電子發票，並提供一般商家、各種電子商務系統、蝦皮、松果、雅虎、Pchome、露天、旋轉賣家輕鬆快速開立電子發票。';

	/** @var string $method_title 標題 */
	public string $method_title = '光貿電子發票';

	/** @var string $method_description 描述 */
	public string $method_description = '光貿電子發票加值中心-電子發票系統，不綁約、無限制開立張數、月費199元開到飽。免費協助營業人快速申請用電子發票，並提供一般商家、各種電子商務系統、蝦皮、松果、雅虎、Pchome、露天、旋轉賣家輕鬆快速開立電子發票。';

	// endregion 基礎通用欄位

	/** @var string 統編 */
	public string $invoice = '';

	/** @var string APP KEY */
	public string $app_key = '';

	/** @var float 稅率 0.05 = 5% 的意思 */
	public float $tax_rate = 0.05;


	/** @var self|null $instance 單例 */
	private static ?self $instance = null;

	/** @return self 取得單例實例 */
	public static function instance(): self {
		if (!self::$instance) {
			$args           = ProviderUtils::get_option( AmegoProvider::ID);
			self::$instance = new self($args);
		}
		return self::$instance;
	}

	/**
	 * 實例化後，如果是 測試模式就修改屬性
	 *
	 * @return void
	 */
	protected function after_init(): void {
		if (Mode::TEST === $this->mode) {
			$this->invoice = '12345678';
			$this->app_key = 'sHeq7t8G1wiQvhAuIM27';
		}
	}
}
