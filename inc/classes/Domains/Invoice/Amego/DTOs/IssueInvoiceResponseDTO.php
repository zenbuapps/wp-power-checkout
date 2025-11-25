<?php

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\Components\ProductItemDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\ECarrierType;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EDetailVat;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EPrintDetail;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EDetailAmountRound;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EPrinterLang;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EPrinterType;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\ETaxType;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Enums\EZeroTaxRateReason;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\PowerCheckout\Shared\Utils\StrHelper;
use J7\WpUtils\Classes\DTO;


final class IssueInvoiceResponseDTO extends DTO {

	/** @var string 代表正確 其他代碼請參考錯誤代碼 https://invoice.amego.tw/info_detail?mid=71 */
	public int $code;

	/** @var string 錯誤訊息 */
	public string $msg;

	/** @var string 發票號碼，正確才會回傳 */
	public string $invoice_number;

	/** @var int 發票開立時間，Unix 時間戳記，正確才會回傳 */
	public int $invoice_time;

	/** @var string 隨機碼，正確才會回傳 */
	public string $random_number;

	/** @var string 電子發票的條碼內容，正確才會回傳 */
	public string $barcode;

	/** @var string 電子發票的左側 QRCODE 內容，正確才會回傳 0元發票會回傳空字串 */
	public string $qrcode_left;

	/** @var string 電子發票的右側 QRCODE 內容，正確才會回傳 0元發票會回傳空字串 */
	public string $qrcode_right;

	/**
	 * @var string
	 * PrinterType = 1，base64編碼的 XML 列印格式字串(mC-Print3 熱感應機專用)，正確且需要列印才會回傳。
	 * PrinterType >= 2，base64編碼的 ESC/POS 列印格式字串，正確且需要列印才會回傳。
	 * 如何設定熱感應機及印出發票，請洽客服。
	 * 0元發票不會回傳此欄位
	 */
	public string $base64_data;

	/** @var string[] 必填 */
	protected array $required_properties = [
		'code',
		'msg',
	];


	/** @return bool 回應是否成功 */
	public function is_success(): bool {
		return $this->code === 0;
	}
}
