<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Traits\TradeOrderIdTrait;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

/**
 * Shopline Payment 跳轉式支付 RequestParams
 *
 * @see https://docs.shoplinepayments.com/api/trade/query/
 */
final class GetPaymentDTO extends DTO {
	use TradeOrderIdTrait;

	/** @var int tradeOrderId 長度上限，SLP 文件標示可達 64 字元 */
	private const MAX_TRADE_ORDER_ID_LENGTH = 64;

	/** @var array<string> 原始資料 */
	protected array $require_properties = [ 'tradeOrderId' ];

	/**
	 * 創建實例
	 *
	 * 由呼叫端負責取得並清理 tradeOrderId（不再硬讀 $_GET，以利測試與重用）
	 *
	 * @param string $trade_order_id SLP 付款交易訂單編號
	 *
	 * @throws \Exception 如果 tradeOrderId 為空
	 */
	public static function create( string $trade_order_id ): self {
		if (!$trade_order_id) {
			throw new \Exception('tradeOrderId is null');
		}

		$args = [
			'tradeOrderId' => $trade_order_id,
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
		( new StrHelper( $this->tradeOrderId, 'tradeOrderId', self::MAX_TRADE_ORDER_ID_LENGTH ) )->validate_strlen();
	}
}
