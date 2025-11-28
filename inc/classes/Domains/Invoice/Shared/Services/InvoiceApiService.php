<?php
/**
 * 發票 Api Service
 * 1. 開立發票
 * 2. 做廢發票
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Invoice\Shared\Services;

use _PHPStan_6597ef616\Nette\Neon\Exception;
use J7\PowerCheckout\Domains\Invoice\Shared\DTOs\InvoiceParams;
use J7\PowerCheckout\Domains\Invoice\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Invoice\Shared\Interfaces\IInvoiceService;
use J7\PowerCheckout\Domains\Invoice\Shared\Utils\InvoiceUtils;
use J7\PowerCheckout\Shared\Utils\OrderUtils;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
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

		[$service, $order] = self::get_service( $order_id, $args );
		( new MetaKeys($order) )->update_issue_params( $args );
		$result = $service->issue( $order  );
		return new \WP_REST_Response($result, 200 );
	}

	/**
	 * 做廢發票
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return \WP_REST_Response 回應
	 */
	public function post_cancel_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id    = $request['id'] ?? '';
		$order       = OrderUtils::get_order( $order_id);
		$provider_id = ( new MetaKeys( $order) )->get_provider_id();
		$provider    = ProviderUtils::get_provider( $provider_id);
		if (!$provider instanceof IInvoiceService) {
			throw new Exception("{$provider_id} 不是 Invoice Service");
		}
		/** @var IInvoiceService $provider */
		$result = $provider->cancel( $order );
		return new \WP_REST_Response( $result, 200 );
	}


	/**
	 * 從請求體解析出服務 & 訂單
	 *
	 * @param string|int $order_id 訂單號
	 * @param array      $args API 帶進來的參數
	 *
	 * @return array{0: IInvoiceService, 1: \WC_Order} 服務, 訂單
	 * @throws \Exception 解析失敗
	 */
	private static function get_service( string|int $order_id, array $args = [] ): array {
		$order    = OrderUtils::get_order( $order_id);
		$args_dto = InvoiceParams::create($args);
		$provider = ProviderUtils::get_provider( $args_dto->provider);

		if (!$provider) {
			throw new \Exception("找不到電子發票服務 id: {$args_dto->provider}，請檢查是否啟用");
		}
		if (!$provider instanceof IInvoiceService) {
			throw new Exception("{$args_dto->provider} 不是 Invoice Service");
		}

		return [ $provider, $order ];
	}
}
