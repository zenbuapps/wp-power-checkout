<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Shared\DTOs;

use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Traits\EnableTrait;
use J7\WpUtils\Classes\DTO;

/**
 * 整合的設定項基類
 */
class BaseSettingsDTO extends DTO {
	use EnableTrait;

	// region 基礎通用欄位

	/** @var string $id Id */
	public string $id = '';

	/** @var string 付款方式 icon */
	public string $icon = '';

	/** @var string 前台顯示付款方式標題 */
	public string $title = '';

	/** @var string 前台顯示付款方式描述 */
	public string $description = '';

	/** @var string  標題 */
	public string $method_title = '';

	/** @var string  描述 */
	public string $method_description = '';

	// endregion

	/** @var Mode Mode 模式  */
	public Mode $mode = Mode::TEST;


	/**
	 * @param string $class_name 物流、電子發票類別
	 *
	 * @return self
	 */
	public static function create( string $class_name ): self {
		$settings_array = \call_user_func( [ $class_name , 'get_settings' ]);
		if (isset($settings_array['mode'])) {
			$settings_array['mode'] = Mode::from($settings_array['mode']);
		}
		return new self($settings_array);
	}
}
