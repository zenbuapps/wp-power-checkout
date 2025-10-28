<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\DTOs;

use J7\WpUtils\Classes\DTO;

/**
 * @see https://invoice.amego.tw/api_doc/#api-%E7%99%BC%E7%A5%A8-Invoice
 */
final class UniParamsDTO extends DTO {

	/** @var string 統一編號 */
	public string $invoice;

	/** @var string API 的 JSON 格式字串 */
	public string $data;

	/** @var int 時間戳記，僅接受與伺服器時間誤差+-60秒 */
	public int $time;

	/** @var string 簽名，md5加密，加密規則：md5(data 轉 json 格式字串 + time + APP Key) */
	public string $sign;

	/** @return self 取得實例 */
	public static function create( DTO $dto ): self {
		$settings = AmegoSettingsDTO::instace();
		$data     = \wp_json_encode( $dto->to_array() );
		$time     = \time();
		$args     = [
			'invoice' => $settings->sInvoice,
			'data'    => $data,
			'time'    => $time,
			'sign'    => \md5("{$data}{$time}{$settings->sApp_Key}"),
		];

		return new self($args);
	}
}
