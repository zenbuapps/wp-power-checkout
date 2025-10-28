<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Services;

use J7\PowerCheckout\Domains\Invoice\Shared\Abstracts\AbstractInvoiceService;
use J7\WpUtils\Classes\WP;

final class AmegoService extends AbstractInvoiceService {

	const ID = 'amego';

	/**
	 * 記錄 log
	 * info, error, warning 會同步記錄到 order note
	 *
	 * @param string               $message     訊息
	 * @param string               $level       等級 info | error | alert | critical | debug | emergency | warning | notice
	 * @param array<string, mixed> $args        附加資訊
	 * @param int                  $trace_limit 追蹤堆疊層數
	 * @param \WC_Order|null       $order 是否紀錄在 order note
	 */
	public static function logger( string $message, string $level = 'debug', array $args = [], $trace_limit = 0, \WC_Order|null $order = null ): void {
		\J7\WpUtils\Classes\WC::logger( $message, $level, $args, 'power_checkout_' . self::ID, $trace_limit );
		if (!$order) {
			return;
		}

		$order_note = WP::array_to_html( $args, [ 'title' => "{$message} <p style='margin-bottom: 0;'>&nbsp;</p>" ] );
		$order->add_order_note( $order_note );
	}
}
