<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\Components\ProductItemDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\ECarrierType;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EDetailVat;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EPrintDetail;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EPrinterLang;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EPrinterType;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\ETaxType;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EZeroTaxRateReason;
use J7\PowerCheckout\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;


final class IssueInvoiceDTO extends DTO {

	/** @var string 訂單編號，不可重複，不可超過40字 */
	public string $OrderId;

	/** @var string 指定字軌開立，需要在後台 發票作業 > 發票字軌列表 設定 API指定代碼，若不指定字軌，則依照後台發票字軌列表的排序去開立 */
	public string $TrackApiCode;

	/** @var string 買方統一編號，沒有則填入 0000000000 */
	public string $BuyerIdentifier = '0000000000';

	/** @var string 買方名稱，不打統編：可以填寫客人、消費者；打統編：如不能填入買方公司名稱，請填買方統一編號；不可填0、00、000及0000 */
	public string $BuyerName = '消費者';

	/** @var string 買方地址 */
	public string $BuyerAddress;

	/** @var string 買方電話 */
	public string $BuyerTelephoneNumber;

	/** @var string 買方電子信箱，寄送通知信用，若不希望寄送，留空即可。測試環境不會主動發送信件 */
	public string $BuyerEmailAddress = '';

	/** @var string 總備註，不可超過200字 */
	public string $MainRemark;

	/** @var ECarrierType 載具類別，手機條碼 3J0002，自然人憑證條碼 CQ0001，光貿會員載具 amego */
	public ECarrierType $CarrierType;

	/** @var string 載具明碼及載具隱碼需為 a+手機號碼 或 電子信箱 */
	public string $CarrierId1;

	/** @var string 載具隱碼 */
	public string $CarrierId2;

	/** @var string 捐贈碼 */
	public string $NPOBAN;

	/** @var ProductItemDTO[] 商品陣列，最多 9999 筆 */
	public array $ProductItem;

	/** @var float 應稅銷售額合計 */
	public float $SalesAmount;

	/** @var float 免稅銷售額合計 */
	public float $FreeTaxSalesAmount;

	/** @var float 零稅率銷售額合計 */
	public float $ZeroTaxSalesAmount;

	/** @var ETaxType 課稅別　1：應稅　2：零稅率　3：免稅　4：應稅(特種稅率)　9：混合應稅與免稅或零稅率(限訊息C0401使用) */
	public ETaxType $TaxType;

	/** @var string 稅率，為5%時本欄位值為0.05 */
	public string $TaxRate = '0.05';

	/** @var float 營業稅額。有打統編才需計算5%稅額，沒打統編發票一律帶0 */
	public float $TaxAmount = 0;

	/** @var float 總計 */
	public float $TotalAmount;

	/** @var int 通關方式註記，若為零稅率發票，則此欄位為必填。 1:非經海關出口 2:經海關出口 */
	public int $CustomsClearanceMark;

	/** @var EZeroTaxRateReason 零稅率原因，若為零稅率發票，則此欄位為必填。71-79各項原因詳見說明 */
	public EZeroTaxRateReason $ZeroTaxRateReason;

	/** @var string 品牌名稱 */
	public string $BrandName;

	/** @var EDetailVat 明細的單價及小計 為 含稅價 或 未稅價，預設為含稅價，0:未稅價 1：含稅價 */
	public EDetailVat $DetailVat;

	/** @var int 明細的小計處理方式，預設為小數精準度到7位數，0:小數精準度到7位數 1:一律四捨五入到整數 */
	public int $DetailAmountRound;

	/** @var EPrinterType 熱感應機型號代碼 */
	public EPrinterType $PrinterType;

	/** @var EPrinterLang 熱感應機編碼 1：BIG5 2：GBK 3：UTF-8 */
	public EPrinterLang $PrinterLang;

	/** @var EPrintDetail 熱感應機是否列印明細 1:列印(預設) 0:不列印 */
	public EPrintDetail $PrintDetail;

	/** @var string[] 必填 */
	protected array $required_properties = [
		'OrderId',
		'BuyerIdentifier',
		'BuyerName',
		'ProductItem',
		'SalesAmount',
		'FreeTaxSalesAmount',
		'ZeroTaxSalesAmount',
		'TaxType',
		'TaxRate',
		'TaxAmount',
		'TotalAmount',
	];

	/** 初始化後 */
	protected function after_init(): void {
		// 有打統編才需計算5%稅額，沒打統編發票一律帶0。
		$this->TaxAmount = 0;
		if ($this->BuyerIdentifier !== '0000000000') {
			$this->TaxAmount = $this->SalesAmount * (float) $this->TaxRate;
		}
	}

	/**
	 * 驗證參數
	 *
	 * @throws \Exception 驗證失敗
	 */
	protected function validate(): void {
		parent::validate();
		( new StrHelper( $this->OrderId, 'OrderId', 40) )->validate();
		if (\in_array($this->BuyerName, [ '0','00','000','0000' ], true)) {
			throw new \Exception('BuyerName 不能是 0,00,000,0000');
		}

		if (isset($this->MainRemark)) {
			( new StrHelper( $this->MainRemark, 'MainRemark', 200) )->validate();
		}
	}

	/** 從訂單創建實例 */
	public static function create( \WC_Order $order ): self {
		$args = [];
		return new self($args);
	}
}
