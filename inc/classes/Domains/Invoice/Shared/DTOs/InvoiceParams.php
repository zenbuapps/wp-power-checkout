<?php

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Shared\DTOs;

use J7\WpUtils\Classes\DTO;

final class InvoiceParams extends DTO {
	/** @var string 電子發票服務提供商 id */
	public string $provider = '';

	/** @var string 電子發票類型 */
	public string $invoiceType = '';
	/** @var string 個人發票類型 */
	public string $individual = '';
	/** @var string 載具 */
	public string $carrier = '';
	/** @var string 自然人憑證 */
	public string $moica = '';
	/** @var string 公司名稱 */
	public string $companyName = '';
	/** @var string 公司統編 */
	public string $companyId = '';
	/** @var string 捐贈馬 */
	public string $donateCode = '';
}
