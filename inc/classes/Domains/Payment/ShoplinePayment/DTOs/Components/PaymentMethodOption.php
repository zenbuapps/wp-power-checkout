<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

use J7\WpUtils\Classes\DTO;
use J7\WpUtils\Classes\General;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\PaymentMethod;

/**
 * PaymentMethodOption
 * PaymentMethodOptions 裡面的選項
 * 實例化時需要宣告 $type
 * */
final class PaymentMethodOption extends DTO {

	/** @var 'CreditCardOption' | 'ChaileaseBNPLOption' | 'JKOPayOption' | 'VirtualAccountOption' 付款方式類型 */
	public string $type;

	/**
	 * @var array<string> 設定支援的分期期數，0 指一般交易
	 *
	 * 預設為空陣列以確保 to_array() 一定輸出此 key。
	 * 若宣告為未初始化屬性，DTO::to_array() 的 isInitialized() check 會跳過該屬性，
	 * 送給 SLP 的 payload 就不會有 installmentCounts key，SLP 會 fallback 顯示全部期數
	 * （含 0 期），導致商家明明取消勾選 0 期但結帳頁仍顯示的 Bug（Issue #12）。
	 */
	public array $installmentCounts = [];

	/** @var int 設定付款方式的逾時時間，單位：min。為了顧客體驗，建議帶入4320（即3天）。若不帶入則默認為 4320（即3天）。若不滿足整數天，則會向上取整 */
	public int $paymentExpireTime;

	/**
	 * 創建實例
	 *
	 * @param array<string, mixed> $args 參數
	 * @param string               $type 類型
	 * @return self
	 */
	public static function create( array $args, string $type ): self {
		$args['type'] = $type;
		return new self($args);
	}


	/**
	 * 驗證 installmentCounts 的值是否都是數字
	 *
	 * @throws \Exception 如果驗證失敗
	 *  */
	protected function validate(): void {
		parent::validate();
		if (!in_array( $this->type, PaymentMethod::get_option_names(), true )) {
			throw new \Exception('type 必須為 ' . implode( ',', PaymentMethod::get_option_names() ) . ' 其中一個');
		}

		// installmentCounts 預設為 []（避免 SLP fallback），只在有值時驗證數字格式。
		if ( [] !== $this->installmentCounts ) {
			if (!General::array_every( $this->installmentCounts, 'is_numeric' )) {
				throw new \Exception('installmentCounts 必須為數字，' . implode( ',', $this->installmentCounts ) . ' 不是數字');
			}
		}

		if ('CreditCardOption' === $this->type && isset($this->paymentExpireTime)) {
			throw new \Exception('CreditCardOption 不需要 paymentExpireTime 設定');
		}

		// JKOPayOption / VirtualAccountOption 不接受分期，若有傳入非空 installmentCounts 則拋錯。
		if ( in_array( $this->type, [ 'JKOPayOption', 'VirtualAccountOption' ], true ) && [] !== $this->installmentCounts ) {
			throw new \Exception('JKOPayOption 不需要 installmentCounts 設定');
		}
	}


	/** @return array<string, mixed> 改寫 to_array */
	public function to_array(): array {
		$array = parent::to_array();
		if (\is_array($array['installmentCounts'])) {
			\sort($array['installmentCounts']);
		}
		unset($array['type']);
		return $array;
	}
}
