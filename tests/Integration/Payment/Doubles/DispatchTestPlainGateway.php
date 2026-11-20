<?php

declare( strict_types=1 );

namespace Tests\Integration\Payment\Doubles;

use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;

/**
 * 測試替身：非嵌入式 gateway
 */
final class DispatchTestPlainGateway extends AbstractPaymentGateway {

	/** @var string gateway ID */
	const ID = 'pc_test_dispatch_plain_gateway';

	/** @var string gateway ID（WC_Payment_Gateway 屬性） */
	public $id = self::ID;

	/**
	 * 最小設定
	 *
	 * @param bool $with_default 是否帶預設值
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		return [
			'enabled' => 'yes',
			'title'   => '測試一般替身',
		];
	}
}
