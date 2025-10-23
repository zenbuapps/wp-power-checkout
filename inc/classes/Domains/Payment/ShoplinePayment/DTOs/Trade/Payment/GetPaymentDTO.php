<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Traits\TradeOrderIdTrait;
use J7\PowerCheckout\Utils\Helper;
use J7\WpUtils\Classes\DTO;

/**
 * Shopline Payment 跳轉式支付 RequestParams
 *
 * @see https://docs.shoplinepayments.com/api/trade/query/
 */
final class GetPaymentDTO extends DTO {
	use TradeOrderIdTrait;

	/** @var array<string, string|int> 原始資料 */
	protected array $require_properties = [ 'tradeOrderId' ];

	/**
	 * 創建實例
	 *
	 * @throws \Exception 如果找不到 session id
	 */
	public static function create(): self {
		$tradeOrderId = $_GET['tradeOrderId'] ?? null; // phpcs:ignore
		if (!$tradeOrderId) { // phpcs:ignore
			throw new \Exception('tradeOrderId is null');
		}
		$args = [
			'tradeOrderId' => $tradeOrderId, // phpcs:ignore
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
		( new Helper( $this->tradeOrderId, 'tradeOrderId', 32) )->validate_strlen();
	}
}
