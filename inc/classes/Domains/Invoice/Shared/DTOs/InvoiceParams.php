<?php

declare( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Invoice\Shared\DTOs;

use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EIndividual;
use J7\PowerCheckout\Domains\Invoice\Shared\Enums\EInvoiceType;
use J7\WpUtils\Classes\DTO;

final class InvoiceParams extends DTO {
	/** @var string 電子發票服務提供商 id */
	public string $provider = '';

	/** @var EInvoiceType 電子發票類型 */
	public EInvoiceType $invoiceType;
	/** @var EIndividual 個人發票類型 */
	public EIndividual $individual;
	/** @var string 載具 */
	public string $carrier = '';
	/** @var string 自然人憑證 */
	public string $moica = '';
	/** @var string 公司名稱 */
	public string $companyName = '';
	/** @var string 公司統編 */
	public string $companyId = '';
	/** @var string 捐贈碼 */
	public string $donateCode = '';

	/** 取得實例 */
	public static function create( array $args ): self {
		if (isset($args['invoiceType'])) {
			$args['invoiceType'] = EInvoiceType::from($args['invoiceType']);
		}
		if (isset($args['individual'])) {
			$args['individual'] = EIndividual::from($args['individual']);
		}

		return new self($args);
	}
}
