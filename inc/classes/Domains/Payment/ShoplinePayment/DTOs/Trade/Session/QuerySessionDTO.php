<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Session;

use J7\PowerCheckout\Domains\Payment\Shared\Params;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\SessionIdTrait;
use J7\PowerCheckout\Utils\Helper;
use J7\WpUtils\Classes\DTO;

/**
 * Shopline Payment 跳轉式支付 RequestParams
 *
 * @see https://docs.shoplinepayments.com/api/trade/sessionQuery/
 */
final class QuerySessionDTO extends DTO {
	use SessionIdTrait;

	/** @var array<string, string|int> 原始資料 */
	protected array $require_properties = [ 'sessionId' ];

	/**
	 * 組成變數的主要邏輯可以寫在裡面
	 *
	 * @param \WC_Order $order 訂單
	 * @throws \Exception 如果找不到 session id
	 */
	public static function create( \WC_Order $order ): self {
		$session_id = ( new Params( $order) )->get_identity();
		if (!$session_id) {
			throw new \Exception( "Session ID not found, order_id #{$order->get_id()}" );
		}
		$args = [
			'sessionId' => $session_id,
		];
		return new self($args);
	}

	/**
	 * 自訂驗證邏輯
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	protected function validate(): void {
		parent::validate();
		( new Helper( $this->sessionId, 'sessionId', 32) )->validate_strlen();
	}
}
