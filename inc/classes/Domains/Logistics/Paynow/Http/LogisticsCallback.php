<?php
/**
 * PayNow（立吉富體系 1）物流 callback 接收（選店回呼 + 貨態通知）
 *
 * 兩個 REST 端點（namespace power-checkout/paynow，permission_callback __return_true）：
 *  - logistics/selection-callback（選店回呼 returnUrl）：Form POST 門市資訊，委派 provider 解析寫 meta。
 *  - logistics/status-callback（貨態通知）：Form POST 貨態推送，以 orderno 反查訂單 + 冪等 + 更新 meta。
 *
 * ★ R1 鐵律（貨態 callback 所有路徑，含例外）：
 *   一律回 HTTP 200，避免 PayNow 重送風暴；任何 \Throwable catch 後仍回 200。
 *
 * 安全清單（permission __return_true；PayNow 物流回呼本身沒有簽章可驗）：
 *   - 選店回呼：returnUrl 編入 orderid + cid（order_key），回呼時兩者都必須帶回且 hash_equals 相符才寫門市 meta（防 IDOR）。
 *   - 貨態通知：推送內容一律不信任，只當「觸發」；以 Get_Order_Info 主動查詢 PayNow 的結果寫回貨態 / COD 收款旗標。
 *   - 節流：同一訂單 10 秒內只查一次（防偽造推送灌爆 PayNow API）。
 *
 * @see specs/open-issue/paynow-logistics-invoice-implementation-plan.md §A-Cycle 2 步驟 9 / §R1
 * @see inc/classes/Domains/Logistics/Ecpay/Http/LogisticsCallback.php（鏡像）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Paynow\Http;

use J7\PowerCheckout\Domains\Logistics\Paynow\Services\PaynowLogisticsProvider;
use J7\PowerCheckout\Domains\Logistics\Paynow\Shared\Helpers\PaynowLogisticsMetaKeys;
use J7\PowerCheckout\Plugin;
use J7\WpUtils\Classes\ApiBase;
use J7\WpUtils\Traits\SingletonTrait;

/** PayNow 物流 callback 接收（選店回呼 + 貨態通知） */
final class LogisticsCallback extends ApiBase {
	use SingletonTrait;

	/** @var string Namespace power-checkout/paynow */
	protected $namespace = 'power-checkout/paynow';

	/** @var string 貨態主動查詢節流 transient 前綴（+ order_id） */
	private const SYNC_THROTTLE_PREFIX = 'pc_paynow_logistics_sync_';

	/** @var int 貨態主動查詢節流秒數（同一訂單此時間內只查一次 PayNow） */
	private const SYNC_THROTTLE_SECONDS = 10;

	/**
	 * APIs
	 *
	 * Callback 於 constructor 綁定至 handle_* 方法（覆寫 ApiBase 自動推導），
	 * 使 REST 路由與測試直呼共用同一組方法。
	 *
	 * @var array<array{endpoint: string, method: string, permission_callback?: callable|null, callback?: callable|null, schema?: array<string, mixed>|null}> API 列表
	 */
	protected $apis = [];

	/** Constructor — 綁定 REST callback 至自訂方法名（覆寫 ApiBase 自動推導） */
	public function __construct() {
		$this->apis = [
			[
				'endpoint'            => 'logistics/selection-callback',
				'method'              => 'post',
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'handle_selection_callback' ],
			],
			[
				'endpoint'            => 'logistics/status-callback',
				'method'              => 'post',
				'permission_callback' => '__return_true',
				'callback'            => [ $this, 'handle_status_callback' ],
			],
		];
		parent::__construct();
	}

	/**
	 * Register hooks
	 *
	 * 以 instance() 建立單例（constructor 會掛 rest_api_init → register_apis）。
	 * ⚠️ SingletonTrait 的 instance 跨請求 / 跨測試持久；當 WP 在請求 / 測試結束還原 hooks 後，
	 *   後續再呼叫 register_hooks() 不會重建 instance，導致 rest_api_init 未被重新掛載、路由消失。
	 *   故此處在 instance 已存在時，以 has_action 守衛補掛 register_apis，確保路由必被註冊。
	 *
	 * @return void
	 */
	public static function register_hooks(): void {
		$instance = self::instance();
		if (false === \has_action( 'rest_api_init', [ $instance, 'register_apis' ] )) {
			\add_action( 'rest_api_init', [ $instance, 'register_apis' ] );
		}
	}

	// region 選店回呼（returnUrl；回 HTTP 200）

	/**
	 * 選店回呼（returnUrl，Form POST 門市資訊）
	 *
	 * 流程：合併 query + body → 委派 provider 解析寫門市 meta → 回 success / error。
	 * 缺門市資訊 / 反查不到訂單 → 回非 success，仍回 HTTP 200（瀏覽器導轉，不拋 500）。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response
	 */
	public function handle_selection_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$params = \array_merge(
				(array) $request->get_query_params(),
				(array) $request->get_body_params()
			);

			$parsed = PaynowLogisticsProvider::instance()->parse_store_selection( $params );

			// 反查不到訂單 → 不視為成功（避免偽造回呼誤判）
			if (false === ( $parsed['order_found'] ?? false )) {
				return new \WP_REST_Response(
					[
						'code'    => 'order_not_found',
						'message' => \__( '選店回呼找不到對應訂單', 'power_checkout' ),
					],
					200
				);
			}

			return new \WP_REST_Response(
				[
					'code'    => 'success',
					'message' => \__( '選店成功', 'power_checkout' ),
					'data'    => $parsed,
				],
				200
			);
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'PayNow 物流選店回呼處理失敗',
				'warning',
				[ 'error' => $e->getMessage() ]
			);
			return new \WP_REST_Response(
				[
					'code'    => 'selection_failed',
					'message' => $e->getMessage(),
				],
				400
			);
		}
	}

	// endregion

	// region 貨態通知（status-callback；R1 恆回 200）

	/**
	 * 貨態通知（status-callback，Form POST 貨態推送）
	 *
	 * ★ R1：所有路徑（含例外）一律回 HTTP 200，避免 PayNow 重送風暴。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WP_REST_Response 恆 HTTP 200
	 */
	public function handle_status_callback( \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$this->process_status_push( $request );
		} catch ( \Throwable $e ) {
			// 任何未預期例外仍回 200，避免 PayNow 60 分重送風暴
			Plugin::logger(
				'PayNow 物流貨態 callback 例外',
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}

		return new \WP_REST_Response( [ 'status' => 'ok' ], 200 );
	}

	/**
	 * 貨態推送處理（推播只當「觸發」，貨態以後端主動查詢 PayNow 為準）
	 *
	 * PayNow 物流貨態推送沒有任何簽章 / 驗證碼可供驗證來源，任何人都能偽造 POST。
	 * 因此推送內容（PayNowLogisticCode / Detail_Status_Description / paymentno）一律不信任、不寫入：
	 *  1. 以 orderno 反查訂單；查無 → 結束。
	 *  2. 尚未建立物流單（無 LogisticNumber）→ 結束（真實推送只會發生在建單之後）。
	 *  3. 節流：同一訂單 {@see self::SYNC_THROTTLE_SECONDS} 秒內只查一次（防偽造推送灌爆 PayNow API）。
	 *  4. 以 Get_Order_Info 主動查詢（帶商店 apicode，偽造者無法冒充回應）→ query_shipment() 寫回
	 *     status / delivery_status / logistic_code，COD 取貨完成才標記 collection_paid。
	 *  5. 冪等：以「查詢結果」的 {OrderNo}:{LogisticCode} 防重複加 order note（不再以推送值判斷，
	 *     避免攻擊者預先把某貨態碼標成已處理，讓真實推送被吞掉）。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return void
	 */
	private function process_status_push( \WP_REST_Request $request ): void {
		$params = \array_merge(
			(array) $request->get_query_params(),
			(array) $request->get_body_params()
		);

		$order_no = (string) ( $params['orderno'] ?? '' );

		// 缺 orderno → 不處理（仍回 200）
		if ('' === \trim( $order_no )) {
			return;
		}

		$order = PaynowLogisticsMetaKeys::get_order_by_order_no( $order_no );
		if (!$order instanceof \WC_Order) {
			Plugin::logger(
				'PayNow 物流貨態 callback 查無對應訂單',
				'warning',
				[ 'orderno' => $order_no ]
			);
			return;
		}

		$meta = new PaynowLogisticsMetaKeys( $order );

		// 尚未建立物流單 → 無從查詢，也不可能是真實推送
		if ('' === \trim( $meta->get_ref() )) {
			Plugin::logger(
				'PayNow 物流貨態 callback：訂單尚無物流單，略過（疑似偽造）',
				'warning',
				[
					'orderno'  => $order_no,
					'order_id' => $order->get_id(),
				]
			);
			return;
		}

		// 節流：同一訂單短時間內只查一次
		$throttle_key = self::SYNC_THROTTLE_PREFIX . $order->get_id();
		if (false !== \get_transient( $throttle_key )) {
			return;
		}
		\set_transient( $throttle_key, 1, self::SYNC_THROTTLE_SECONDS );

		// 以後端主動查詢結果為準（query_shipment 內寫回貨態 meta + COD collection_paid）
		$result        = PaynowLogisticsProvider::instance()->query_shipment( $order );
		$logistic_code = (string) ( $result['logistic_code'] ?? '' );
		$delivery      = (string) ( $result['delivery_status'] ?? '' );

		// 冪等：同一（OrderNo + 查詢到的貨態碼）只加一次 note
		$dedup_code = '' !== $logistic_code ? $logistic_code : $delivery;
		if ($meta->is_processed( $order_no, $dedup_code )) {
			return;
		}
		$meta->mark_processed( $order_no, $dedup_code );

		PaynowLogisticsProvider::logger(
			\sprintf( '📦 PayNow 物流貨態更新（主動查詢確認）：%s（%s）', $logistic_code, $delivery ),
			'info',
			[
				'orderno'       => $order_no,
				'logistic_code' => $logistic_code,
				'delivery'      => $delivery,
			],
			0,
			$order
		);
	}

	// endregion

	// region helpers

	/** @return string 選店回呼 URL（returnUrl，Form POST） */
	public static function get_selection_callback_url(): string {
		return \site_url( 'wp-json/power-checkout/paynow/logistics/selection-callback', 'https' );
	}

	/** @return string 貨態通知 URL（status-callback，Form POST） */
	public static function get_status_callback_url(): string {
		return \site_url( 'wp-json/power-checkout/paynow/logistics/status-callback', 'https' );
	}

	// endregion
}
