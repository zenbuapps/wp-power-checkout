<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Amego\Services;

use J7\PowerCheckout\Domains\Invoice\Amego\DTOs\AmegoSettingsDTO;
use J7\PowerCheckout\Domains\Invoice\Amego\Http\ApiClient;
use J7\PowerCheckout\Domains\Invoice\Amego\Shared\Helpers\Requester;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Shared\Abstracts\BaseService;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\WP;

final class AmegoProvider extends BaseService implements IInvoiceService {
	use \J7\WpUtils\Traits\SingletonTrait;

	public const ID = 'amego';

	/**
	 * 記錄 log
	 * info, error, warning 會同步記錄到 order note
	 *
	 * @param string               $message     訊息
	 * @param string               $level       等級 info | error | alert | critical | debug | emergency | warning | notice
	 * @param array<string, mixed> $args        附加資訊
	 * @param int                  $trace_limit 追蹤堆疊層數
	 * @param \WC_Order|null       $order       是否紀錄在 order note
	 */
	public static function logger( string $message, string $level = 'debug', array $args = [], int $trace_limit = 0, \WC_Order|null $order = null ): void {
		\J7\WpUtils\Classes\WC::logger( $message, $level, $args, 'power_checkout_' . self::ID, $trace_limit );
		if (!$order) {
			return;
		}

		if ($args) {
			$message .= "<p style='margin-bottom: 0;'>&nbsp;</p>";
		}

		$order_note = WP::array_to_html( $args, [ 'title' => $message ] );
		$order->add_order_note( $order_note );
	}

	/**
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array
	 */
	public function issue( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id);

		// region 如果已經發行過，就不重複發行
		$meta_keys   = new MetaKeys( $order);
		$issued_data = $meta_keys->get_issued_data();
		if ($issued_data && \is_array($issued_data)) {
			return $issued_data;
		}
		// endregion 如果已經發行過，就不重複發行

		$requester = new Requester( $order );
		$client    = new ApiClient( $order, $requester);
		$result    = $client->issue(self::ID);
		return $result?->to_array() ?? [];
	}

	/**
	 * @param \WC_Order|int $order_or_id 訂單
	 *
	 * @return array
	 */
	public function cancel( \WC_Order|int $order_or_id ): array {
		$order = ( $order_or_id instanceof \WC_Order ) ? $order_or_id : OrderUtils::get_order( $order_or_id);
		// region 如果已經取消過，就不重複發行
		$meta_keys      = new MetaKeys( $order);
		$cancelled_data = $meta_keys->get_cancelled_data();
		if ($cancelled_data) {
			return $cancelled_data;
		}
		// endregion 如果已經取消過，就不重複發行

		$requester = new Requester( $order );
		$client    = new ApiClient( $order, $requester);
		$result    = $client->cancel();
		return $result?->to_array() ?? [];
	}

	/**
	 * @param bool $with_default 是否有預設值，還是只拿 DB 值
	 * false = 只拿 db, true = 會給預設值
	 *
	 * @return array 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if (!$with_default) {
			return ProviderUtils::get_option( self::ID);
		}
		return AmegoSettingsDTO::instance()->to_array();
	}

	/**
	 * 取得發票號碼
	 *
	 * @param \WC_Order $order 訂單
	 *
	 * @return string
	 */
	public function get_invoice_number( \WC_Order $order ): string {
		$meta_keys   = new MetaKeys( $order);
		$issued_data = $meta_keys->get_issued_data();
		return $issued_data['invoice_number'] ?? '';
	}
}
