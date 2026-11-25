<?php
/**
 * 物流 Api Service（對外 REST 端點，namespace power-checkout/v1）
 *
 * 鏡像 Invoice\Shared\Services\InvoiceApiService：extends ApiBase + SingletonTrait，
 * callback 取 provider（ProviderUtils）→ 委派 ILogisticsProvider 對應方法 → 回 {code,message,data}。
 *
 * 5 個端點：
 *  - POST  logistics/{id}/store-selection  選店導轉（階段 A）→ data.redirect_target
 *  - POST  logistics/{id}/create-shipment  成立物流單（階段 B）→ data.logistics_id
 *  - GET   logistics/{id}                   查詢物流單 → data.{logistics_id,status,store_info}
 *  - POST  logistics/{id}/print             列印託運單 → HTML 或 PDF（%PDF 開頭 → application/pdf；rest_pre_echo_response 直接 echo）
 *  - POST  logistics/{id}/cancel            C2C 取消物流單 → data.{logistics_id,cancelled}
 *  - POST  logistics/{id}/return            建立退貨單（逆物流）→ data.return_logistics_id
 *  - GET   logistics/providers/meta         已啟用 provider 的 meta（id => {supported_delivery_methods, capabilities}）
 *
 * Capability 檢查：操作前讀 provider meta，宣告不支援 → HTTP 501 + error_code UNSUPPORTED（不呼叫 provider）。
 *   既有 3 家（CAPABILITY_CHECK_EXEMPT_IDS）豁免，維持原回應碼。
 * Provider 解析清單可經 filter `power_checkout_logistics_provider_ids` 擴充（既有 3 家順序在前）。
 *
 * ⚠️ 錯誤映射：provider 各方法失敗一律 throw，本層 catch \Throwable 轉對應 HTTP code
 *   （400 參數 / 403 狀態 / 404 找不到 / 500 例外）。訊息關鍵字對應計畫資料流：
 *     - 「未啟用」→ 403
 *     - 「找不到訂單」→ 404
 *     - 「尚未選店」/「尚未成立物流單」/「僅支援 C2C」→ 403
 *     - 「必須為公開可訪問的 URL」/「已啟用的綠界物流子類型」/「寄貨編號」/「TransCode」/「RtnCode」→ 400
 *   不外洩內部細節（細節已由 provider / ApiClient 寫 order note / log）。
 *   例外帶正規化 ErrorCode（新 provider，如 TcatLogisticsException）→ 改依 ErrorCode::to_http_status() 並回 error_code / raw_code；
 *   其中原本映射為 502 的 provider 端失敗（NETWORK / PROVIDER）改回 424 Failed Dependency——站點常掛在
 *   Cloudflare 等 CDN 後，origin 的 502 本文會被改寫成 CDN 錯誤頁，後台看不到診斷訊息（與 LogisticsPingApiService 一致）。
 *
 * @see .claude/skills/ECPay-API-Skill/guides/07-logistics-allinone.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Shared\Services;

use J7\PowerCheckout\Domains\Logistics\Shared\Interfaces\ILogisticsProvider;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** 物流 Api Service */
final class LogisticsApiService extends ApiBase {
	use SingletonTrait;

	/**
	 * 已知物流 provider id 解析順序（綠界 / PAYUNi / PayNow 並存可切換）
	 *
	 * Resolve 依此順序找「第一個啟用」的 provider 作為委派目標（請求未帶 provider 參數時）；
	 * 帶 provider 參數時優先用該 provider（須在本清單且已啟用）。
	 * ⚠️ ECPay / PAYUNi 共用 LogisticsMetaKeys（_pc_logistics_*）；PayNow 用獨立 meta
	 *   （_pc_paynow_logistics_*），print/cancel/query 委派正常，但貨態 / 選店 callback 反查
	 *   走 PayNow 自己的 callback class（power-checkout/paynow namespace）。
	 * 黑貓宅急便直串（tcat_logistics，P4）/ 新竹物流（hct_logistics，P5）/ 順豐（sf_express，P6）/ 嘉里大榮（kerrytj_logistics，P7）用獨立 meta（_pc_{id}_*），排在最後（未指定 provider 時不搶先）。
	 *
	 * @var array<int, string>
	 */
	private const PROVIDER_IDS = [
		'ecpay_logistics',
		'payuni_logistics',
		'paynow_logistics',
		'tcat_logistics',
		'hct_logistics',
		'sf_express',
		'kerrytj_logistics',
	];

	/**
	 * 不受 capability 檢查的既有 provider（維持原回應碼，計劃 P0「只加不改」）
	 *
	 * 這 3 家宣告 false 的操作（PAYUNi 取消 / PayNow 退貨）原本由 provider 自己拋例外回 400，保留該行為；
	 * 新 provider（P1 之後）一律受檢查，宣告 false 回 501 + UNSUPPORTED。
	 *
	 * @var array<int, string>
	 */
	private const CAPABILITY_CHECK_EXEMPT_IDS = [
		'ecpay_logistics',
		'payuni_logistics',
		'paynow_logistics',
	];

	/** @var string 擴充 provider 解析清單的 filter（新 provider / 測試替身用） */
	public const PROVIDER_IDS_FILTER = 'power_checkout_logistics_provider_ids';

	/** @var string REST API namespace */
	protected $namespace = 'power-checkout/v1';

	/**
	 * APIs
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [
		[
			'endpoint' => 'logistics/(?P<id>\d+)/store-selection',
			'method'   => 'post',
		],
		[
			'endpoint' => 'logistics/(?P<id>\d+)/create-shipment',
			'method'   => 'post',
		],
		[
			'endpoint' => 'logistics/(?P<id>\d+)/print',
			'method'   => 'post',
		],
		[
			'endpoint' => 'logistics/(?P<id>\d+)/cancel',
			'method'   => 'post',
		],
		[
			'endpoint' => 'logistics/(?P<id>\d+)/return',
			'method'   => 'post',
		],
		[
			'endpoint' => 'logistics/providers/meta',
			'method'   => 'get',
		],
		[
			'endpoint' => 'logistics/(?P<id>\d+)',
			'method'   => 'get',
		],
	];

	/** Register hooks @return void */
	public static function register_hooks(): void {
		self::instance();
	}

	// region REST callbacks（回 {code, message, data}）

	/**
	 * 選店導轉（階段 A）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_with_id_store_selection_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->run(
			$request,
			function ( ILogisticsProvider $provider, \WC_Order $order ) use ( $request ): array {
				$sub_type = (string) ( $request->get_param( 'sub_type' ) ?? '' );
				$scenario = (string) ( $request->get_param( 'payment_scenario' ) ?? '' );

				$ctx = [ 'sub_type' => $sub_type ];
				if ('' !== $scenario) {
					$ctx['payment_scenario'] = $scenario;
				}

				$result = $provider->get_store_selection( $order, $ctx );
				return [
					'message' => \__( '取得選店頁成功', 'power_checkout' ),
					'data'    => $result,
				];
			},
			'has_cvs_map'
		);
	}

	/**
	 * 成立物流單（階段 B）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_with_id_create_shipment_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->run(
			$request,
			static function ( ILogisticsProvider $provider, \WC_Order $order ): array {
				$result = $provider->create_shipment( $order );
				// 建單成功 → 排程自動查詢貨態（沒設推播網址時的保底；收到推播會自動取消）
				ShipmentStatusPoller::schedule( $order, $provider->get_provider_meta()->id );
				return [
					'message' => \__( '物流單成立成功', 'power_checkout' ),
					'data'    => $result,
				];
			},
			'can_create_shipment'
		);
	}

	/**
	 * 查詢物流單
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function get_logistics_with_id_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->run(
			$request,
			static function ( ILogisticsProvider $provider, \WC_Order $order ): array {
				$result = $provider->query_shipment( $order );
				return [
					'message' => \__( '查詢成功', 'power_checkout' ),
					'data'    => $result,
				];
			},
			'can_query_shipment'
		);
	}

	/**
	 * 列印託運單（回 HTML body；黑貓等回 PDF bytes 者以 application/pdf 輸出）
	 *
	 * 透過 rest_pre_echo_response 直接 echo 原文，避免 WP 序列化為 JSON（鏡像 EcpgCallback 技巧）。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_with_id_print_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			[ $provider, $order ] = $this->resolve( $request );
			$unsupported          = self::unsupported_response( $provider, 'can_print' );
			if (null !== $unsupported) {
				return $unsupported;
			}
			$document = $provider->print_document( $order );
			return self::document_response( $document, $order );
		} catch ( \Throwable $e ) {
			return self::exception_response( $e );
		}
	}

	/**
	 * C2C 取消物流單
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_with_id_cancel_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->run(
			$request,
			static function ( ILogisticsProvider $provider, \WC_Order $order ): array {
				$result = $provider->cancel_shipment( $order );
				return [
					'message' => \__( '取消成功', 'power_checkout' ),
					'data'    => $result,
				];
			},
			'can_cancel'
		);
	}

	/**
	 * 建立退貨單（逆物流；後台手動觸發）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function post_logistics_with_id_return_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return $this->run(
			$request,
			static function ( ILogisticsProvider $provider, \WC_Order $order ): array {
				$result = $provider->create_return( $order );
				return [
					'message' => \__( '退貨單成立成功', 'power_checkout' ),
					'data'    => $result,
				];
			},
			'can_return'
		);
	}

	/**
	 * 已啟用物流 provider 的 meta（admin；Vue 依此隱藏不支援的操作）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function get_logistics_providers_meta_callback( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => 'success',
				'message' => \__( '取得物流 provider meta 成功', 'power_checkout' ),
				'data'    => self::get_enabled_provider_metas(),
			],
			200
		);
	}

	/**
	 * 取得已啟用物流 provider 的 meta（id => meta 陣列）
	 *
	 * @return array<string, array{id: string, supported_delivery_methods: array<int, string>, capabilities: array{can_create_shipment: bool, can_query_shipment: bool, has_cvs_map: bool, can_print: bool, can_return: bool, can_cancel: bool}}>
	 */
	public static function get_enabled_provider_metas(): array {
		$metas = [];
		foreach ( self::provider_ids() as $id ) {
			$provider = ProviderUtils::is_enabled( $id ) ? ProviderUtils::get_provider( $id ) : null;
			if ($provider instanceof ILogisticsProvider) {
				$metas[ $id ] = $provider->get_provider_meta()->to_array();
			}
		}
		return $metas;
	}

	// endregion

	// region helpers

	/**
	 * 共用執行框架：解析 provider + 訂單 → 執行 handler → 回 {code,message,data}；失敗轉 HTTP code
	 *
	 * Handler 簽章：fn(ILogisticsProvider $provider, \WC_Order $order): array{message: string, data: mixed}
	 *
	 * @param \WP_REST_Request $request    請求
	 * @param callable         $handler    業務 handler，回 {message, data}
	 * @param string           $capability 本操作需要的 capability 鍵（ShipmentCapabilitiesDTO::to_array 的鍵）
	 *
	 * @return \WP_REST_Response
	 */
	private function run( \WP_REST_Request $request, callable $handler, string $capability ): \WP_REST_Response {
		try {
			[ $provider, $order ] = $this->resolve( $request );
			$unsupported          = self::unsupported_response( $provider, $capability );
			if (null !== $unsupported) {
				return $unsupported;
			}
			$result = $handler( $provider, $order );

			return new \WP_REST_Response(
				[
					'code'    => 'success',
					'message' => (string) ( $result['message'] ?? '成功' ),
					'data'    => $result['data'] ?? null,
				],
				200
			);
		} catch ( \Throwable $e ) {
			return self::exception_response( $e );
		}
	}

	/**
	 * 解析請求：取得已啟用的 provider 與訂單
	 *
	 * Provider 解析委派 resolve_provider()（未啟用 / 不是物流服務時拋 \Exception 向上傳遞）。
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return array{0: ILogisticsProvider, 1: \WC_Order}
	 * @throws \InvalidArgumentException 訂單不存在
	 */
	private function resolve( \WP_REST_Request $request ): array {
		$provider = $this->resolve_provider( $request );

		// 訂單不存在 → 404
		$order_id = (int) ( $request['id'] ?? 0 );
		$order    = \wc_get_order( $order_id );
		if (!$order instanceof \WC_Order) {
			throw new \InvalidArgumentException( \__( '找不到訂單', 'power_checkout' ) );
		}

		return [ $provider, $order ];
	}

	/**
	 * 解析委派的物流 provider（綠界 / PAYUNi 並存可切換）
	 *
	 * 1. 請求帶 provider 參數 → 須在 PROVIDER_IDS 且已啟用，否則 403。
	 * 2. 未帶參數 → 取 PROVIDER_IDS 中第一個啟用的 provider；皆未啟用 → 403。
	 *
	 * @param \WP_REST_Request $request 請求
	 *
	 * @return ILogisticsProvider
	 * @throws \Exception Provider 未啟用 / 不是物流服務
	 */
	private function resolve_provider( \WP_REST_Request $request ): ILogisticsProvider {
		$requested = (string) ( $request->get_param( 'provider' ) ?? '' );

		// 指定 provider：須在已知清單且已啟用
		if ('' !== $requested) {
			if (!\in_array( $requested, self::provider_ids(), true ) || !ProviderUtils::is_enabled( $requested )) {
				throw new \Exception( \__( '指定的物流服務未啟用', 'power_checkout' ) );
			}
			$provider = ProviderUtils::get_provider( $requested );
			if ($provider instanceof ILogisticsProvider) {
				return $provider;
			}
			throw new \Exception( \__( '指定的物流服務未啟用', 'power_checkout' ) );
		}

		// 未指定：取第一個啟用的 provider
		foreach ( self::provider_ids() as $id ) {
			if (!ProviderUtils::is_enabled( $id )) {
				continue;
			}
			$provider = ProviderUtils::get_provider( $id );
			if ($provider instanceof ILogisticsProvider) {
				return $provider;
			}
		}

		throw new \Exception( \__( '物流服務未啟用', 'power_checkout' ) );
	}

	/**
	 * 物流 provider 解析清單（既有 3 家在前，可經 filter 擴充）
	 *
	 * @return array<int, string>
	 */
	private static function provider_ids(): array {
		$ids = \apply_filters( self::PROVIDER_IDS_FILTER, self::PROVIDER_IDS );
		if (!\is_array( $ids )) {
			return self::PROVIDER_IDS;
		}
		return \array_values( \array_unique( \array_filter( $ids, 'is_string' ) ) );
	}

	/**
	 * Capability 檢查：provider meta 宣告不支援 → 501 + UNSUPPORTED；支援或豁免 → null
	 *
	 * @param ILogisticsProvider $provider   provider
	 * @param string             $capability capability 鍵
	 * @return \WP_REST_Response|null
	 */
	private static function unsupported_response( ILogisticsProvider $provider, string $capability ): ?\WP_REST_Response {
		$meta = $provider->get_provider_meta();
		if (\in_array( $meta->id, self::CAPABILITY_CHECK_EXEMPT_IDS, true )) {
			return null;
		}

		$capabilities = $meta->capabilities->to_array();
		if (true === ( $capabilities[ $capability ] ?? false )) {
			return null;
		}

		return new \WP_REST_Response(
			[
				'code'       => ErrorCode::UNSUPPORTED->value,
				'error_code' => ErrorCode::UNSUPPORTED->value,
				'message'    => \__( '此物流服務不支援此操作', 'power_checkout' ),
				'data'       => null,
			],
			501
		);
	}

	/**
	 * 依錯誤訊息關鍵字映射 HTTP 狀態碼
	 *
	 * @param string $message 錯誤訊息
	 * @return int HTTP 狀態碼
	 */
	private static function map_status( string $message ): int {
		// 404：找不到訂單
		if (\str_contains( $message, '找不到訂單' )) {
			return 404;
		}

		// 403：狀態類前置（未啟用 / 尚未選店 / 尚未成立物流單 / 僅支援 C2C）
		$status_keywords = [ '未啟用', '尚未選店', '尚未成立物流單', '僅支援 C2C' ];
		foreach ( $status_keywords as $keyword ) {
			if (\str_contains( $message, $keyword )) {
				return 403;
			}
		}

		// 其餘（參數錯誤 / 傳輸層 / 業務層 / 缺寄貨編號 / reply URL）→ 400
		return 400;
	}

	/**
	 * 統一錯誤回應（{code, message, data}）
	 *
	 * @param string $message 錯誤訊息
	 * @param int    $status  HTTP 狀態碼
	 * @return \WP_REST_Response
	 */
	private static function error_response( string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			[
				'code'    => 'error',
				'message' => $message,
				'data'    => null,
			],
			$status
		);
	}

	/**
	 * 例外 → 錯誤回應
	 *
	 * 例外帶正規化錯誤碼（新 provider 的 public readonly ErrorCode $error_code）→ 依 ErrorCode 映射 HTTP 並回 error_code / raw_code；
	 * 其餘（既有 3 家拋的 \Exception）維持訊息關鍵字映射，回應不變。
	 *
	 * @param \Throwable $e 例外
	 * @return \WP_REST_Response
	 */
	private static function exception_response( \Throwable $e ): \WP_REST_Response {
		$error_code = \property_exists( $e, 'error_code' ) && $e->error_code instanceof ErrorCode ? $e->error_code : null;
		if (null === $error_code) {
			return self::error_response( $e->getMessage(), self::map_status( $e->getMessage() ) );
		}

		return new \WP_REST_Response(
			[
				'code'       => 'error',
				'error_code' => $error_code->value,
				'raw_code'   => \property_exists( $e, 'raw_code' ) && \is_string( $e->raw_code ) ? $e->raw_code : null,
				'message'    => $e->getMessage(),
				'data'       => null,
			],
			self::http_status_for( $error_code )
		);
	}

	/**
	 * 正規化錯誤碼 → HTTP 狀態（UNSUPPORTED 501；provider 端失敗 502 → 424，避免 CDN 改寫本文）
	 *
	 * @param ErrorCode $error_code 正規化錯誤碼
	 * @return int
	 */
	private static function http_status_for( ErrorCode $error_code ): int {
		if (ErrorCode::UNSUPPORTED === $error_code) {
			return 501;
		}

		$status = $error_code->to_http_status();
		return 502 === $status ? LogisticsPingApiService::PROVIDER_FAILURE_STATUS : $status;
	}

	/**
	 * 列印文件回應：HTML（既有 3 家）或 PDF（以 %PDF 開頭，如黑貓 DownloadOBT）
	 *
	 * 透過 rest_pre_echo_response 直接 echo 原文，避免 WP 序列化為 JSON。
	 *
	 * @param string    $document 託運單 HTML 或 PDF bytes
	 * @param \WC_Order $order    訂單（PDF 檔名用）
	 * @return \WP_REST_Response
	 */
	private static function document_response( string $document, \WC_Order $order ): \WP_REST_Response {
		$response = new \WP_REST_Response( $document, 200 );
		if (\str_starts_with( $document, '%PDF' )) {
			$response->header( 'Content-Type', 'application/pdf' );
			$response->header( 'Content-Disposition', self::pdf_disposition( $order ) );
		} else {
			$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		}
		\add_filter(
			'rest_pre_echo_response',
			static function ( $result, $server, $request ) use ( $document ) {
				$route = $request->get_route();
				if (\is_string( $route ) && \str_contains( $route, '/logistics/' ) && \str_ends_with( $route, '/print' )) {
					echo $document; // phpcs:ignore
					return null;
				}
				return $result;
			},
			10,
			3
		);
		return $response;
	}

	/**
	 * PDF Content-Disposition（檔名含物流單號，無則訂單 ID）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private static function pdf_disposition( \WC_Order $order ): string {
		$ref = '';
		foreach ( [ '_pc_tcat_logistics_ref', '_pc_kerrytj_logistics_ref', '_pc_logistics_ref' ] as $key ) {
			$value = $order->get_meta( $key );
			if (\is_scalar( $value ) && '' !== (string) $value) {
				$ref = (string) $value;
				break;
			}
		}
		$name = (string) \preg_replace( '/[^A-Za-z0-9_-]/', '', '' !== $ref ? $ref : 'order-' . $order->get_id() );
		return "inline; filename=\"shipping-label-{$name}.pdf\"";
	}

	// endregion
}
