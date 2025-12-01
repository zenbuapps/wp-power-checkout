<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Shared\Traits;

trait EnableTrait {

	/** @var string 'yes'|'no'  */
	public string $enabled = 'no';

	/** @return bool 是否啟用 */
	final public function is_enabled(): bool {
		return 'yes' === $this->enabled;
	}
}
