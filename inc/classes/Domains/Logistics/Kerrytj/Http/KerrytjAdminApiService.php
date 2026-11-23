<?php
/**
 * 嘉里大榮專屬 admin REST（ILogisticsProvider 沒有的兩個操作）
 *
 *  - POST power-checkout/v1/logistics/{id}/kerrytj/update  修改託運資料（UpdateShipmentDelivery）→ {code, message, data}
 *  - POST power-checkout/v1/logistics/{id}/kerrytj/detail  託運明細表 PDF（GetDetailPdf）→ application/pdf
 *
 * - 權限：ApiBase 預設（manage_options / manage_woocommerce），前端帶 X-WP-Nonce
 * - 只在 kerrytj_logistics 啟用時註冊（Logistics\ProviderRegister）
 * - 錯誤：例外帶 ErrorCode → to_http_status()（UNSUPPORTED → 501）；訂單不存在 → 404
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\Http;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Services\KerrytjLogisticsProvider;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** 嘉里大榮 admin Api Service */
final class KerrytjAdminApiService extends ApiBase {
	use SingletonTrait;

	/** @var string 託運明細路由尾端（PDF 原文輸出判斷用） */
	private const DETAIL_ROUTE_SUFFIX = '/kerrytj/detail';

	/** @var string REST API namespace */
	protected $namespace = 'power-checkout/v1';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint' => 'logistics/(?P<id>\d+)/kerrytj/update',
			'method'   => 'post',
			'callback' => [ self::class, 'post_update_callback' ],
		],
		[
			'endpoint' => 'logistics/(?P<id>\d+)/kerrytj/detail',
			'method'   => 'post',
			'callback' => [ self::class, 'post_detail_callback' ],
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	/**
	 * 修改託運資料
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public static function post_update_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$data = KerrytjLogisticsProvider::instance()->update_shipment( self::order_of( $request ) );
			return new \WP_REST_Response(
				[
					'code'    => 'success',
					'message' => \__( '託運資料已修改', 'power_checkout' ),
					'data'    => $data,
				],
				200
			);
		} catch ( \Throwable $e ) {
			return self::error_response( $e );
		}
	}

	/**
	 * 託運明細表 PDF（透過 rest_pre_echo_response 直接輸出原文，避免序列化為 JSON）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public static function post_detail_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$order    = self::order_of( $request );
			$pdf      = KerrytjLogisticsProvider::instance()->print_detail( $order );
			$response = new \WP_REST_Response( $pdf, 200 );
			$response->header( 'Content-Type', 'application/pdf' );
			$response->header( 'Content-Disposition', "inline; filename=\"kerrytj-detail-{$order->get_id()}.pdf\"" );
			\add_filter(
				'rest_pre_echo_response',
				static function ( $result, $server, $req ) use ( $pdf ) {
					$route = $req instanceof \WP_REST_Request ? $req->get_route() : '';
					if (\str_ends_with( $route, self::DETAIL_ROUTE_SUFFIX )) {
						echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PDF bytes
						return null;
					}
					return $result;
				},
				10,
				3
			);
			return $response;
		} catch ( \Throwable $e ) {
			return self::error_response( $e );
		}
	}

	/**
	 * 取訂單
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WC_Order
	 * @throws \InvalidArgumentException 訂單不存在
	 */
	private static function order_of( \WP_REST_Request $request ): \WC_Order {
		$order = \wc_get_order( (int) ( $request->get_url_params()['id'] ?? 0 ) );
		if (!$order instanceof \WC_Order) {
			throw new \InvalidArgumentException( \__( '找不到訂單', 'power_checkout' ) );
		}
		return $order;
	}

	/**
	 * 例外 → 錯誤回應
	 *
	 * @param \Throwable $e 例外
	 * @return \WP_REST_Response
	 */
	private static function error_response( \Throwable $e ): \WP_REST_Response {
		$error_code = \property_exists( $e, 'error_code' ) && $e->error_code instanceof ErrorCode
		? $e->error_code
		: ( $e instanceof \InvalidArgumentException ? ErrorCode::NOT_FOUND : ErrorCode::UNKNOWN );
		return new \WP_REST_Response(
			[
				'code'       => 'error',
				'error_code' => $error_code->value,
				'raw_code'   => \property_exists( $e, 'raw_code' ) && \is_string( $e->raw_code ) ? $e->raw_code : null,
				'message'    => $e->getMessage(),
				'data'       => null,
			],
			ErrorCode::UNSUPPORTED === $error_code ? 501 : $error_code->to_http_status()
		);
	}
}
