<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Linepay\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\Linepay\DTOs\LinepaySettingsDTO;
use J7\PowerCheckout\Domains\Payment\Linepay\DTOs\RequestParams;
use J7\PowerCheckout\Domains\Payment\Linepay\Http\LinepayApiClient;
use J7\PowerCheckout\Domains\Payment\Linepay\Http\LinepayReturnController;
use J7\PowerCheckout\Domains\Payment\Linepay\Managers\LinepayReconcileJob;
use J7\PowerCheckout\Domains\Payment\Linepay\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Enums\LinepayConfirmResult;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Enums\LinepayReturnCode;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Exceptions\LinepayApiException;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers\LinepayMetaKeys;
use J7\PowerCheckout\Domains\Payment\Linepay\Shared\Helpers\LinepayOrderId;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\DTOs\PaymentCapabilitiesDTO;
use J7\PowerCheckout\Domains\Payment\Shared\DTOs\ProviderMetaDTO;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderCreationStrategy;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\PaymentMode;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\WebhookOutcome;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\BlocksIntegration;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\WpUtils\Classes\WP;

/**
 * LINE Pay 直連付款閘道 `linepay`（Online API v4，商家自有 Channel ID / Secret）
 *
 * 與 SLP / 藍新 / PAYUNi「代收 LINE Pay」無關（那些走各自金流商 API；既有 LinePayTest 測的是 SLP 代收）。
 *
 * 流程（api-redirect，自動請款）：
 *  1. process_payment → POST /v4/payments/request（金額後端算）→ 存 transactionId（19 位字串）→
 *     排 AS 補單任務（+1500 秒）→ redirect info.paymentUrl.web
 *  2. 顧客認證 → 瀏覽器 GET confirmUrl（自帶 order_id + order_key，LINE Pay 補 transactionId / orderId）→
 *     統一外框 handle_webhook()：驗 order_key + transactionId + orderId → confirm → payment_complete → 302 order-received
 *  3. LINE Pay **沒有 webhook**：顧客關掉瀏覽器時由 LinepayReconcileJob 補單；後台另有「查詢補單」order action
 *  4. 自動請款後只能 refund（不能 void）→ capture / void 不支援
 *
 * @see .claude/skills/linepay/SKILL.md
 * @see zenbu payments/linepay/linepay.service.ts
 */
final class LinepayGateway extends AbstractPaymentGateway implements IGateway {

	/** @var string 付款方式 ID */
	public const ID = 'linepay';

	/** @var string 後台訂單操作：查詢補單 */
	public const ACTION_QUERY = 'pc_linepay_query_trade';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示標題 */
	public $method_title = 'LINE Pay（直連）';

	/** @var string 後台顯示描述 */
	public $method_description = 'LINE Pay Online API v4 直連（商家自有 Channel ID / Channel Secret），導向 LINE Pay 付款頁，自動請款';

	/** @var LinepayConfirmResult|null 本次回呼的認列結果（決定 302 導回頁；每次回呼於 verify_callback 重置） */
	private ?LinepayConfirmResult $callback_result = null;

	/** @var bool 本次回呼是否為顧客取消（cancelUrl） */
	private bool $callback_cancelled = false;

	// region 設定 / 可用性

	/**
	 * @param bool $with_default 是否含預設值（本 DTO 一律合併預設）
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		return LinepaySettingsDTO::instance()->to_array();
	}

	/**
	 * 是否可用：父類（啟用 + 最小 / 最大金額）+ TWD + 憑證齊全
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		return \is_admin() || $this->is_available_for_currency( \get_woocommerce_currency() );
	}

	/**
	 * 指定幣別下是否可用（不含 WC 啟用狀態）：LINE Pay 台灣只收 TWD
	 *
	 * @param string $currency 幣別
	 * @return bool
	 */
	public function is_available_for_currency( string $currency ): bool {
		return 'TWD' === $currency && LinepaySettingsDTO::instance()->has_credentials();
	}

	// endregion

	// region 付款

	/**
	 * Before process payment：付款請求 → 回 paymentUrl.web
	 *
	 * @param \WC_Order $order 訂單
	 * @return string redirect URL
	 * @throws LinepayApiException 回應碼非 0000 / 傳輸失敗（金額不合法的 \InvalidArgumentException 亦同由 process_payment 統一處理：通用 notice + order note）
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		$settings = LinepaySettingsDTO::instance();
		$meta     = new LinepayMetaKeys( $order );
		$lp_order = LinepayOrderId::generate( $order->get_id(), $meta->next_attempt() );
		$params   = RequestParams::from_order(
			$order,
			$lp_order,
			LinepayReturnController::get_confirm_url( $order ),
			LinepayReturnController::get_cancel_url( $order )
		);

		$response = ( new LinepayApiClient( $settings ) )->request( $params->to_array() );
		$code     = LinepayApiClient::return_code( $response );
		$info     = \is_array( $response['info'] ?? null ) ? $response['info'] : [];
		$tx       = \is_scalar( $info['transactionId'] ?? null ) ? (string) $info['transactionId'] : '';
		$urls     = \is_array( $info['paymentUrl'] ?? null ) ? $info['paymentUrl'] : [];
		$web_url  = \is_string( $urls['web'] ?? null ) ? $urls['web'] : '';

		if ( LinepayReturnCode::SUCCESS->value !== $code || 1 !== \preg_match( '/^\d{1,32}$/', $tx ) || '' === $web_url ) {
			throw new LinepayApiException(
				"LINE Pay 付款請求失敗：{$code} " . LinepayApiClient::return_message( $response ) . "（orderId {$lp_order}）",
				LinepayReturnCode::error_code_for( $code ),
				$code
			);
		}

		$meta->update_request( $lp_order, $tx, $params->amount, $web_url );
		LinepayReconcileJob::schedule( $order->get_id() );
		$order->add_order_note( "LINE Pay 已建立付款請求，orderId {$lp_order}，transactionId {$tx}，金額 {$params->amount} 元" );

		// 一律用 web 付款頁：手機瀏覽器開啟後 LINE Pay 會引導進 App；app deep link 在 sandbox 打不開
		return $web_url;
	}

	/**
	 * Thank-you 頁：尚未付款時提示並提供重新付款連結
	 *
	 * @param int $order_id 訂單 ID
	 * @return void
	 */
	public static function render_thankyou( int $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || self::ID !== $order->get_payment_method() || ! $order->needs_payment() ) {
			return;
		}

		$url   = \esc_url( $order->get_checkout_payment_url() );
		$title = \esc_html__( 'LINE Pay 付款尚未完成（若剛完成付款，系統確認中，請稍後重新整理）', 'power_checkout' );
		$link  = \esc_html__( '重新前往付款', 'power_checkout' );
		$html  = <<<HTML
		<section class="pc-linepay-pending"><p>{$title}</p><p><a class="button" href="{$url}">{$link}</a></p></section>
		HTML;
		echo \wp_kses_post( $html );
	}

	/**
	 * [Admin] 訂單頁顯示付款明細 / 退款紀錄
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( self::ID !== $order->get_payment_method() ) {
			return;
		}
		$meta = new LinepayMetaKeys( $order );
		if ( $meta->get_payment_detail() ) {
			echo WP::array_to_html( $meta->get_payment_detail(), [ 'title' => 'LINE Pay 付款明細' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		$refunds = [];
		foreach ( $meta->get_refund_detail() as $index => $record ) {
			$refunds[ '#' . ( $index + 1 ) ] = $record;
		}
		if ( $refunds ) {
			echo WP::array_to_html( $refunds, [ 'title' => 'LINE Pay 退款紀錄' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	// endregion

	// region confirmUrl / cancelUrl（統一 webhook 外框掛鉤）

	/**
	 * 驗證導回：order_key（hash_equals）+ LINE Pay 訂單 + transactionId / orderId 與 meta 一致
	 *
	 * 取消導回（cancelUrl）的 transactionId / orderId 為「有條件帶」：有帶才比對。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return bool
	 */
	public function verify_callback( \WP_REST_Request $request ): bool {
		$this->callback_result    = null;
		$this->callback_cancelled = self::is_cancel_request( $request );

		$order = self::find_order_with_key( $request );
		if ( null === $order || self::ID !== $order->get_payment_method() ) {
			return false;
		}

		$meta     = new LinepayMetaKeys( $order );
		$tx       = self::query_string( $request, 'transactionId' );
		$lp_order = self::query_string( $request, 'orderId' );
		if ( $this->callback_cancelled ) {
			return ( '' === $tx || \hash_equals( $meta->get_transaction_id(), $tx ) )
			&& ( '' === $lp_order || \hash_equals( $meta->get_linepay_order_id(), $lp_order ) );
		}

		return '' !== $meta->get_transaction_id()
		&& \hash_equals( $meta->get_transaction_id(), $tx )
		&& \hash_equals( $meta->get_linepay_order_id(), $lp_order );
	}

	/**
	 * 以 order_id 找單（verify_callback 已驗 order_key）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WC_Order|null
	 */
	protected function resolve_callback_order( \WP_REST_Request $request ): ?\WC_Order {
		return self::find_order_with_key( $request );
	}

	/**
	 * 外框冪等鍵停用（回空字串）
	 *
	 * GET 導回沒有 body，預設 sha256(body) 對所有導回都相同；冪等改由 StatusManager（已付款不再認列、不打 API）負責，
	 * 且網路逾時後顧客重新整理仍可再 confirm。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @param \WC_Order        $order   訂單
	 * @return string
	 */
	protected function get_callback_idempotency_key( \WP_REST_Request $request, \WC_Order $order ): string {
		return '';
	}

	/**
	 * 導回業務處理：cancel 留 note；confirm 呼叫 confirm 認列（網路逾時向上拋 → 不改狀態，留給補單）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @param \WC_Order        $order   訂單
	 * @return void
	 * @throws LinepayApiException 呼叫 confirm 時傳輸失敗
	 */
	protected function process_callback( \WP_REST_Request $request, \WC_Order $order ): void {
		if ( $this->callback_cancelled ) {
			if ( ! StatusManager::is_settled( $order ) ) {
				$order->add_order_note(
					\sprintf(
						/* translators: %s: LINE Pay orderId */
						\__( 'LINE Pay：顧客於付款頁取消付款（orderId %s），訂單保持待付款', 'power_checkout' ),
						( new LinepayMetaKeys( $order ) )->get_linepay_order_id()
					)
				);
			}
			return;
		}

		$manager               = new StatusManager( $order, new LinepayApiClient( LinepaySettingsDTO::instance() ) );
		$this->callback_result = $manager->confirm( \__( 'confirmUrl 導回', 'power_checkout' ) );
	}

	/**
	 * Ack：一律 302（顧客瀏覽器導回）
	 *  - 驗證失敗 / 找不到單 → 結帳頁（不揭露訂單）
	 *  - 取消 / 逾期 / 授權失敗 → order-pay（重新付款）
	 *  - 其他（成功、確認中、網路逾時）→ order-received
	 *
	 * @param \WP_REST_Request $request 請求
	 * @param WebhookOutcome   $outcome 結果
	 * @return \WP_REST_Response
	 */
	protected function callback_ack_response( \WP_REST_Request $request, WebhookOutcome $outcome ): \WP_REST_Response {
		$order = self::find_order_with_key( $request );

		if ( WebhookOutcome::SIGNATURE_INVALID === $outcome || null === $order ) {
			$location = null !== $order ? $order->get_checkout_order_received_url() : \wc_get_checkout_url();
		} elseif ( ! $order->is_paid() && ( $this->callback_cancelled || ( $this->callback_result?->needs_repay() ?? false ) ) ) {
			$location = $order->get_checkout_payment_url();
		} else {
			$location = $order->get_checkout_order_received_url();
		}

		$response = new \WP_REST_Response( null, 302 );
		$response->header( 'Location', $location );
		return $response;
	}

	/**
	 * 是否為 cancelUrl
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return bool
	 */
	private static function is_cancel_request( \WP_REST_Request $request ): bool {
		return \str_ends_with( (string) $request->get_route(), '/linepay/cancel' );
	}

	/**
	 * 以 order_id + key（hash_equals）找單；不符回 null
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WC_Order|null
	 */
	private static function find_order_with_key( \WP_REST_Request $request ): ?\WC_Order {
		$order_id = self::query_string( $request, 'order_id' );
		$key      = self::query_string( $request, 'key' );
		if ( 1 !== \preg_match( '/^\d+$/', $order_id ) || '' === $key ) {
			return null;
		}
		$order = \wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || ! \hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * 取 query 字串參數（transactionId 為 19 位整數，一律當字串處理）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @param string           $key     參數名
	 * @return string
	 */
	private static function query_string( \WP_REST_Request $request, string $key ): string {
		$value = $request->get_param( $key );
		return \is_string( $value ) ? \sanitize_text_field( \wp_unslash( $value ) ) : '';
	}

	// endregion

	// region 退款 / 查詢

	/**
	 * 退款前置檢查（admin 點退款時）
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   金額
	 * @param string     $reason   原因
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		$amount = (float) $amount;
		if ( $amount <= 0 ) {
			return false;
		}
		if ( $amount > (float) $order->get_total() ) {
			return NormalizedError::from( ErrorCode::VALIDATION, \__( '退款金額不合法（超出可退餘額）', 'power_checkout' ), [ 'provider' => $this->id ] );
		}
		if ( '' === ( new LinepayMetaKeys( $order ) )->get_transaction_id() ) {
			return NormalizedError::from( ErrorCode::VALIDATION, \__( '此訂單沒有 LINE Pay 交易序號，無法線上退款', 'power_checkout' ), [ 'provider' => $this->id ] );
		}
		return true;
	}

	/**
	 * 退款 API（woocommerce_order_refunded）：一律帶明確 refundAmount
	 *
	 * @param int $order_id  訂單 ID
	 * @param int $refund_id 退款 ID
	 * @return void
	 */
	public function process_gateway_refund( int $order_id, int $refund_id ): void {
		if ( ! $this->is_this_gateway( $order_id ) ) {
			return;
		}

		$refund = \wc_get_order( $refund_id );
		$order  = \wc_get_order( $order_id );
		if ( ! $refund instanceof \WC_Order_Refund || ! $order instanceof \WC_Order || ! $refund->get_refunded_payment() ) {
			return;
		}

		try {
			$meta     = new LinepayMetaKeys( $order );
			$amount   = (int) \round( (float) $refund->get_amount() );
			$response = ( new LinepayApiClient( LinepaySettingsDTO::instance() ) )->refund( $meta->get_transaction_id(), $amount );
			$code     = LinepayApiClient::return_code( $response );
			if ( LinepayReturnCode::SUCCESS->value !== $code ) {
				throw new LinepayApiException(
					"{$code} " . LinepayApiClient::return_message( $response ),
					LinepayReturnCode::error_code_for( $code ),
					$code
				);
			}

			$info      = \is_array( $response['info'] ?? null ) ? $response['info'] : [];
			$refund_tx = \is_scalar( $info['refundTransactionId'] ?? null ) ? (string) $info['refundTransactionId'] : '';
			$meta->add_refund_detail(
				[
					'refundTransactionId'   => $refund_tx,
					'refundAmount'          => $amount,
					'refundTransactionDate' => \is_scalar( $info['refundTransactionDate'] ?? null ) ? (string) $info['refundTransactionDate'] : '',
				]
			);
			$order->add_order_note( "✅ LINE Pay 退款成功，金額 {$amount} 元，退款交易序號 {$refund_tx}。原因：{$refund->get_reason()}" );
		} catch ( LinepayApiException $e ) {
			self::fail_gateway_refund( $order, $refund, 'LINE Pay', $e->getMessage(), $e->raw_code, $e->error_code );
		} catch ( \Throwable $e ) {
			self::fail_gateway_refund( $order, $refund, 'LINE Pay', $e->getMessage() );
		}
	}

	/**
	 * 查詢交易：付款明細中本訂單 transactionId 的付款交易
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> 查無回空陣列
	 * @throws LinepayApiException 查詢失敗
	 */
	public function query_trade( \WC_Order $order ): array {
		if ( '' === ( new LinepayMetaKeys( $order ) )->get_transaction_id() ) {
			return [];
		}
		return ( new StatusManager( $order, new LinepayApiClient( LinepaySettingsDTO::instance() ) ) )->find_payment_detail() ?? [];
	}

	/**
	 * 後台訂單操作：查詢補單
	 *
	 * @param array<string, string> $actions 既有操作
	 * @param \WC_Order|null        $order   訂單
	 * @return array<string, string>
	 */
	public static function add_order_actions( array $actions, ?\WC_Order $order = null ): array {
		if ( $order instanceof \WC_Order && self::ID === $order->get_payment_method() ) {
			$actions[ self::ACTION_QUERY ] = \__( 'LINE Pay 重新查詢付款狀態（補單）', 'power_checkout' );
		}
		return $actions;
	}

	/**
	 * 「查詢補單」handler（與 AS 補單同一流程：先查狀態再決定 confirm / 查明細）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function handle_query_action( \WC_Order $order ): void {
		if ( self::ID !== $order->get_payment_method() ) {
			return;
		}
		if ( ! LinepayReconcileJob::should_reconcile( $order ) ) {
			$order->add_order_note( \__( 'LINE Pay 查詢補單：訂單已非待付款或尚未建立付款請求，略過', 'power_checkout' ) );
			return;
		}
		try {
			LinepayReconcileJob::reconcile( $order, \__( '後台查詢補單', 'power_checkout' ) );
		} catch ( \Throwable $e ) {
			$order->add_order_note( "❌ LINE Pay 查詢補單失敗：{$e->getMessage()}" );
		}
	}

	// endregion

	// region 共用

	/**
	 * Provider meta：api-redirect；退款 / 部分退款 / 查詢；自動請款 → 不支援 capture / void
	 *
	 * @return ProviderMetaDTO
	 */
	public function get_provider_meta(): ProviderMetaDTO {
		return new ProviderMetaDTO(
			id: self::ID,
			payment_mode: PaymentMode::API_REDIRECT,
			order_creation_strategy: OrderCreationStrategy::PRE_CREATE_PENDING,
			capabilities: new PaymentCapabilitiesDTO(
				refund: true,
				partial_refund: true,
				capture: false,
				void: false,
				query: true,
				recurring: false,
			),
		);
	}

	/** 初始化（gateway 啟用時）：thank-you 提示、後台查詢補單 @return void */
	public static function init(): void {
		\add_action( 'woocommerce_thankyou_' . self::ID, [ __CLASS__, 'render_thankyou' ] );
		\add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_order_actions' ], 10, 2 );
		\add_action( 'woocommerce_order_action_' . self::ACTION_QUERY, [ __CLASS__, 'handle_query_action' ] );
	}

	/** 註冊區塊結帳整合 @return void */
	public static function register_checkout_blocks(): void {
		\add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( PaymentMethodRegistry $registry ): void {
				if ( ! \class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
					return;
				}
				$gateway = GatewayUtils::get_gateway( self::ID );
				if ( $gateway instanceof AbstractPaymentGateway ) {
					$registry->register( new BlocksIntegration( $gateway ) );
				}
			}
		);
	}

	// endregion
}
