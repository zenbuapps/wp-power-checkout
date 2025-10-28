<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\PowerCheckout\Shared\Traits\EnableTrait;
use J7\WpUtils\Classes\DTO;

final class AmegoSettingsDTO extends DTO {
	use EnableTrait;

	// region 基礎通用欄位

	/** @var string 付款方式 icon */
	public string $icon = 'https://invoice-static.amego.tw/www/images/amego_20231206.svg';

	/** @var string 前台顯示付款方式標題 */
	public string $title = '光貿電子發票';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '電子發票系統，不綁約、無限制開立張數、月費199元開到飽。';

	// endregion

	/** @var string $mode Enums\Mode::value 模式  */
	public string $mode = 'test';

	/** @var string 統編 */
	public string $sInvoice = '';

	/** @var string API KEY */
	public string $sApp_Key = '';



	/** @return self 取得實例 */
	public static function instance(): self {
		$args = [];
		return new self($args);
	}
}
