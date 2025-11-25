<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs\Components;

use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\ETaxType;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;

final class ProductItemDTO extends DTO {
	/** @var string 品名，不可超過256字 */
	public string $Description;

	/** @var float 數量，小數精準度到7位數 */
	public float $Quantity;

	/** @var string 單位，不可超過6字 */
	public string $Unit;

	/** @var float 單價，預設含稅，小數精準度到7位數。若發票打統編需用未稅價，DetailVat 欄位可設定為 0 */
	public float $UnitPrice;

	/** @var float 小計，小數精準度到7位數。小計可透過 DetailAmountRound 欄位可設定為1:一律四捨五入到整數 */
	public float $Amount;

	/** @var string 備註，不可超過40字 */
	public string $Remark;

	/** @var ETaxType 課稅別　1：應稅　2：零稅率　3：免稅 */
	public ETaxType $TaxType;

	/** @var string[] 必填  */
	protected array $require_properties = [
		'Description',
		'Quantity',
		'UnitPrice',
		'Amount',
		'TaxType',
	];

	/** 小數點精度 */
	protected function after_init(): void {
		$this->Quantity  = \round( $this->Quantity, 7 );
		$this->UnitPrice = \round( $this->UnitPrice, 7 );
		$this->Amount    = \round( $this->Amount, 7 );
	}

	/** 參數驗證 */
	protected function validate(): void {
		parent::validate();
		( new StrHelper( $this->Description, 'Description', 256) )->validate();
		if (isset( $this->Unit)) {
			( new StrHelper( $this->Unit, 'Unit', 6) )->validate();
		}
		if (isset( $this->Remark)) {
			( new StrHelper( $this->Remark, 'Remark', 40) )->validate();
		}
	}
}
