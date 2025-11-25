<?php
/**
 * 發票 Api Service
 * 1. 開立發票
 * 2. 做廢發票
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Services;

use J7\PowerCheckout\Domains\Invoice\Shared\DTOs\InvoiceParams;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Domains\Invoice\Shared\Utils\InvoiceUtils;
use J7\PowerCheckout\Shared\Utils\IntegrationUtils;
use J7\WpUtils\Classes\ApiBase;

/** Invoice Api Service */
final class InvoiceApiService extends ApiBase {
	use \J7\WpUtils\Traits\SingletonTrait;

	/** @var string $namespace */
	protected $namespace = 'power-checkout/v1/invoices';

	/**
	 * @var array<array{
	 * endpoint:string,
	 * method:string,
	 * permission_callback?: callable|null,
	 * callback?: callable|null,
	 * schema?: array<string, mixed>|null
	 * }> $apis APIs
	 *
	 * @phpstan-ignore-next-line
	 * */
	protected $apis = [
		[
			'endpoint' => 'issue/(?P<id>\d+)', // order_id
			'method'   => 'post',
		],
		[
			'endpoint' => 'cancel/(?P<id>\d+)', // order_id
			'method'   => 'post',
		],
	];

	/**
	 * 開立發票
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return \WP_REST_Response 回應
	 */
	public function post_issue_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id = $request['id'] ?? '';
		$args     = $request->get_params();

		// TEST ----- ▼ 印出 WC Logger 記得移除 ----- //
		\J7\WpUtils\Classes\WC::logger( 'post_issue_with_id_callback', 'info', $args );
		// TEST ---------- END ---------- //
		$args_dto          = new InvoiceParams($args);
		[$service, $order] = $this->get_service( $order_id );
		( new MetaKeys($order) )->update_issue_params( $args );
		return new \WP_REST_Response( $service->issue( $order  ), 200 );
	}

	/**
	 * 做廢發票
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return \WP_REST_Response 回應
	 */
	public function post_cancel_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id          = $request['id'] ?? '';
		[$service, $order] = $this->get_service( $order_id );
		return new \WP_REST_Response( $service->cancel( $order ), 200 );
	}


	/**
	 * 從請求體解析出服務 & 訂單
	 *
	 * @param string|int $order_id 訂單號
	 *
	 * @return array{0: IInvoiceService, 1: \WC_Order} 服務, 訂單
	 * @throws \Exception 解析失敗
	 */
	private function get_service( string|int $order_id ): array {
		$order = \wc_get_order($order_id);
		if (!$order instanceof \WC_Order) {
			throw new \Exception("order_id:{$order_id} not found");
		}

		$invoice_id =( new MetaKeys( $order) )->get_service_id();
		$service    = IntegrationUtils::get_integration_instance( $invoice_id);

		if (!$service) {
			throw new \Exception("找不到電子發票服務 {$invoice_id}，請檢查是否啟用");
		}

		return [ $service, $order ];
	}
}
