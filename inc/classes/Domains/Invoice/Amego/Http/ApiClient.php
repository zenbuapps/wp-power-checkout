<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Http;

use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers\Requester;

/**
 * 光貿電子方票
 *
 * @see https://invoice.amego.tw/api_doc/
 *  */
final class ApiClient {

	/** @var Requester 請求器 */
	private Requester $requester;

	/** Constructor */
	public function __construct(
		/** @var \WC_Order 訂單 */
		private readonly \WC_Order $order
	) {
		$this->requester = new Requester( $this->order );
	}
}
