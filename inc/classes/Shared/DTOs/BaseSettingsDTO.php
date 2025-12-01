<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Shared\DTOs;

use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Traits\EnableTrait;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
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

	/** @var string[] 自動開立發票的訂單狀態  */
	public array $auto_issue_order_statuses = [];

	/** @var string[] 自動作廢發票的訂單狀態  */
	public array $auto_cancel_order_statuses = [ 'wc-refunded' ];

	// endregion 基礎通用欄位

	/** @var Mode Mode 模式  */
	public Mode $mode = Mode::PROD;

	/**
	 * @param string $class_name 物流、電子發票類別
	 *
	 * @return static
	 */
	public static function create( string $class_name ): static {
		$settings_array = \call_user_func( [ $class_name , 'get_settings' ]);
		return new static($settings_array);
	}

	/**
	 * 實例化後，如果是 測試模式就修改屬性
	 *
	 * @return void
	 */
	protected function before_init(): void {
		if (isset($this->dto_data['mode']) && \is_string($this->dto_data['mode'])) {
			$this->dto_data['mode'] = Mode::from($this->dto_data['mode']);
		}
	}
}
