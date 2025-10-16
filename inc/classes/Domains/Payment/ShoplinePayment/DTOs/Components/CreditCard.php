<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\CreditCardType;
use J7\WpUtils\Classes\DTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums;

/**
 * CreditCard
 *  */
final class CreditCard extends DTO {
	/** @var Enums\CreditCardType::value *卡類型 */
	public string $type;

	/** @var string *卡號前六位 */
	public string $bin;

	/** @var string *卡號後四位 */
	public string $last4;

	/** @var string 卡類別 */
	public string $category;

	/** @var string 發卡行 */
	public string $issuer;

	/** @var string 發卡國家 */
	public string $issuerCountry;

	/** @var string 卡組織 */
	public string $brand;

	/** @var array<string> 必填屬性 */
	protected array $required_properties = [ 'type', 'bin', 'last4' ];

	/** 自訂驗證 */
	protected function validate(): void {
		parent::validate();
		if (isset( $this->type)) {
			Enums\CreditCardType::from( $this->type );
		}
	}


	/** 轉換成人類可讀的陣列 */
	public function to_human_array(): array {
		$issuer_mapper = [
			'TAISHIN INTERNATIONAL BANK' => '台新銀行',
		];

		return [
			'信用卡卡別' => "{$this->brand} " . ( CreditCardType::tryFrom($this->type)?->label() ?? $this->type ), // Visa, MasterCard, JCB, etc.
			'發卡行'   => $issuer_mapper[ $this->issuer ] ?? $this->issuer, // 銀行
			'卡號前六碼' => $this->bin,
			'卡號後四碼' => $this->last4,
			'卡類別'   => $this->category, // SIGNATURE 簽賬卡?
			'發卡國家'  => $this->issuerCountry,
		];
	}
}
