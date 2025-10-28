<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\WpUtils\Classes\DTO;

final class AmegoSettingsDTO extends DTO {

	// region 基礎通用欄位

	/** @var string 是否啟用 */
	public string $enabled = 'yes';

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


	/** @return bool 是否啟用 */
	public function is_enabled(): bool {
		return 'yes' === $this->enabled;
	}
}
