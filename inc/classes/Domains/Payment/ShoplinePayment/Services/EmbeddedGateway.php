<?php

declare ( strict_types = 1 );

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\DTOs\PaymentCapabilitiesDTO;
use J7\PowerCheckout\Domains\Payment\Shared\DTOs\ProviderMetaDTO;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderCreationStrategy;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\PaymentMode;
use J7\PowerCheckout\Domains\Payment\Shared\Exceptions\DispatchException;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\BlocksIntegration;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\MetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IEmbeddedPaymentProvider;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\EmbeddedSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\RedirectSettingsDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\CreateSdkPaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment\PaymentDTO;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\ApiClient;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Http\WebHook;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Managers\EmbeddedSyncManager;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Abstracts\PaymentGateway;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ErrorCode as SlpErrorCode;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Enums\ResponseStatus;
use J7\PowerCheckout\Domains\Payment\ShoplinePayment\Shared\Helpers\EmbeddedReference;
use J7\PowerCheckout\Domains\Settings\Services\SettingTabService;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Enums\Mode;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;

/**
 * SHOPLINE Payments 嵌入式（站內付，JS SDK + trade/payment/create）
 *
 * 流程（計劃流程 B）：
 *  1. process_payment：只寫 _pc_slp_embedded_pending，回 order-received（不打 API）
 *  2. order-received：localize SDK 掛載資料 → 前端 POST /pay/{id}/dispatch（init）取 SDK 初始化參數
 *  3. 顧客按付款 → SDK createPayment() 取 paySession → dispatch（confirm）→ 後端 trade/payment/create
 *     → 回 nextAction（原樣 JSON 字串，R6）→ 前端 payment.pay(nextAction) → 3DS / 導回 order-received
 *  4. 結果以 trade.* webhook（共用既有 /power-checkout/slp/webhook，ASM-3）或 order-received 主動查詢為準
 *
 * 商店憑證（merchantId / apiKey / clientKey / signKey / mode）共用導轉式 RedirectSettingsDTO（藍圖 §9 修正 3），
 * 本 gateway 設定只放顯示欄位（EmbeddedSettingsDTO）。第一版只開信用卡（含分期；ASM-4）。
 */
final class EmbeddedGateway extends PaymentGateway implements IGateway, IEmbeddedPaymentProvider {

	/** @var string 付款方式 ID */
	public const ID = 'shopline_payment_embedded';

	/**
	 * @var string SLP 官方 SDK CDN（藍圖 §9 修正 10）
	 * 官方只提供 major 版本路徑 /sdk/v1/，無法鎖定精確版本；刻意不改用 npm 打包（以官方 CDN 為準）。
	 * @see .claude/skills/shopline-payments-v1/references/embedded-sdk.md §1
	 */
	public const SDK_URL = 'https://cdn.shoplinepayments.com/sdk/v1/payment-web.js';

	/** @var string 前端 SDK 收銀台容器 id */
	public const CONTAINER_ID = 'pc-slp-embedded-container';

	/** @var string process_payment 已執行、等待前端 SDK 付款的標記 */
	public const PENDING_META_KEY = '_pc_slp_embedded_pending';

	/** @var string confirm 防雙擊鎖 transient 前綴 */
	public const LOCK_PREFIX = 'pc_slp_emb_lock_';

	/** @var int confirm 鎖秒數 */
	private const LOCK_TTL = 30;

	/** @var int paySession 長度上限（bytes） */
	public const MAX_PAY_SESSION_BYTES = 10240;

	/** @var int webhook 簽章 timestamp 容差（毫秒，與既有 WebHook 一致） */
	private const SIGNATURE_TOLERANCE_MS = 300000;

	/** @var string 前端 localize 物件名稱 */
	public const LOCALIZE_NAME = 'power_checkout_slp_embedded_data';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示付款方式標題 */
	public $method_title = 'SHOPLINE Payments 信用卡（站內付）';

	/** @var string 後台顯示付款方式描述 */
	public $method_description = '顧客於本站內以 SHOPLINE Payments 收銀台輸入信用卡完成付款（支援分期），商店憑證共用 SHOPLINE Payments 導轉式設定';

	// region 可用性

	/**
	 * 是否可用：TWD + 導轉式商店憑證齊全 + 父類（啟用 / 金額範圍）
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( \is_admin() ) {
			return parent::is_available();
		}

		if ( 'TWD' !== \get_woocommerce_currency() || ! $this->has_store_credentials() ) {
			return false;
		}

		return parent::is_available();
	}

	/**
	 * 導轉式商店憑證是否齊全（merchantId / apiKey / clientKey）
	 *
	 * @return bool
	 */
	public function has_store_credentials(): bool {
		$settings = RedirectSettingsDTO::instance();
		return '' !== $settings->merchantId && '' !== $settings->apiKey && '' !== $settings->clientKey;
	}

	// endregion

	// region 付款流程

	/**
	 * 結帳：只標記待付款，回 order-received（SDK 在該頁掛載；不打 API）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		$order->update_meta_data( self::PENDING_META_KEY, 'yes' );
		$order->save();

		return $this->get_return_url( $order );
	}

	/**
	 * 於 order-received 頁 render 前執行（before_page_render 已驗 order_key + needs_payment）
	 *
	 *  1. 已建立交易（伺服器端 _pc_payment_identity）→ 主動查詢一次補單（節流、never-throw）
	 *  2. 仍需付款 → localize 前端 SDK 掛載資料；最近一次查詢為處理中 → 標記 awaiting，前端不再渲染收銀台（防重複扣款）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	protected function before_order_received( \WC_Order $order ): void {
		$last_payment = null;
		try {
			$sync = new EmbeddedSyncManager( $this, $order );
			$sync->sync();
			$last_payment = $sync->last_payment;
		} catch ( \Throwable $e ) {
			$this->logger( "❌ {$this->title} order-received 主動查詢發生錯誤<br>{$e->getMessage()}", 'error', [], 5 );
		}

		$fresh = \wc_get_order( $order->get_id() );
		if ( ! $fresh instanceof \WC_Order || ! $fresh->needs_payment() ) {
			return;
		}

		if ( ! $this->has_store_credentials() ) {
			$this->logger( "⚠️ {$this->title} 商店憑證未設定（共用 SHOPLINE Payments 導轉式設定），無法渲染收銀台", 'warning', [], 0, false );
			return;
		}

		SettingTabService::enqueue_vue_app();
		\wp_localize_script(
			SettingTabService::$handle,
			self::LOCALIZE_NAME,
			[
				'dispatch_url'          => \get_rest_url( null, "power-checkout/v1/pay/{$fresh->get_id()}/dispatch" ),
				'order_id'              => (string) $fresh->get_id(),
				'order_key'             => $fresh->get_order_key(),
				'sdk_url'               => self::SDK_URL,
				'container_id'          => self::CONTAINER_ID,
				'order_received_url'    => $this->get_return_url( $fresh ),
				'awaiting_confirmation' => self::is_awaiting( $last_payment ) ? 'yes' : 'no',
			]
		);
	}

	/**
	 * 兩段式 dispatch（由 PaymentDispatchApiService 呼叫；REST 層已驗 order_key / gateway / needs_payment）
	 *
	 * @param \WC_Order            $order   訂單
	 * @param array<string, mixed> $payload 前端參數（無 pay_session → init；有 → confirm）
	 * @return array<string, mixed>
	 * @throws DispatchException 參數錯誤（400）/ 重複送出（409）
	 * @throws \Throwable 憑證未設定、SLP API 失敗、無 nextAction（REST 層回 500 通用訊息）
	 */
	public function dispatch( \WC_Order $order, array $payload ): array {
		if ( ! \array_key_exists( 'pay_session', $payload ) ) {
			return $this->dispatch_init( $order );
		}

		return $this->dispatch_confirm( $order, $payload['pay_session'] );
	}

	/**
	 * Init 階段：回 SDK 初始化參數（不建單、不打 API；金額一律後端算）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 * @throws \RuntimeException 商店憑證未設定
	 */
	private function dispatch_init( \WC_Order $order ): array {
		if ( ! $this->has_store_credentials() ) {
			throw new \RuntimeException( 'SHOPLINE Payments 商店憑證未設定（merchantId / apiKey / clientKey）' );
		}

		$settings = RedirectSettingsDTO::instance();
		$sdk      = [
			'clientKey'         => $settings->clientKey,
			'merchantId'        => $settings->merchantId,
			'paymentMethod'     => CreateSdkPaymentDTO::PAYMENT_METHOD,
			'currency'          => 'TWD',
			'amount'            => CreateSdkPaymentDTO::to_cents( (float) $order->get_total() ),
			'env'               => Mode::TEST->value === $settings->mode ? 'sandbox' : 'production',
			'language'          => 'zh_TW' === \get_locale() ? 'zh-TW' : 'en',
			'installmentCounts' => self::get_installment_counts( $settings ),
		];
		if ( '' !== $settings->platformId ) {
			$sdk['platformId'] = $settings->platformId;
		}

		return [
			'mode'     => PaymentMode::CLIENT_SDK->value,
			'provider' => self::ID,
			'phase'    => 'init',
			'sdk'      => $sdk,
		];
	}

	/**
	 * Confirm 階段：以 paySession 建立付款交易，回 nextAction（原樣 JSON 字串）
	 *
	 * @param \WC_Order $order       訂單
	 * @param mixed     $pay_session SDK paySession（前端送來，未驗證）
	 * @return array<string, mixed>
	 * @throws DispatchException 參數錯誤（400）/ 已付款或重複送出 / SLP 重複下單（409）
	 * @throws \RuntimeException 回應無 nextAction（建單失敗）
	 * @throws \Throwable SLP API 失敗（原例外重新拋出）
	 */
	private function dispatch_confirm( \WC_Order $order, mixed $pay_session ): array {
		if ( ! \is_string( $pay_session ) || '' === \trim( $pay_session ) || \strlen( $pay_session ) > self::MAX_PAY_SESSION_BYTES ) {
			throw new DispatchException(
				ErrorCode::VALIDATION,
				\__( '付款資料格式錯誤，請重新整理頁面後再試', 'power_checkout' ),
				400
			);
		}

		// 已付款 / 無需付款的訂單不可再建交易（REST 層已擋；此處為 gateway 層防線，防過期分頁重複扣款）
		if ( ! $order->needs_payment() ) {
			throw new DispatchException(
				ErrorCode::CONFLICT,
				\__( '訂單已付款或無需付款', 'power_checkout' ),
				409
			);
		}

		$lock_key = self::LOCK_PREFIX . $order->get_id();
		if ( \get_transient( $lock_key ) ) {
			throw new DispatchException(
				ErrorCode::CONFLICT,
				\__( '付款處理中，請勿重複送出', 'power_checkout' ),
				409
			);
		}
		\set_transient( $lock_key, 1, self::LOCK_TTL );

		$this->record_exception_to_order_note( $order );
		try {
			// 每次嘗試唯一 referenceOrderId（SLP 拒絕重複單號 1001），送出前先寫入歷史 meta
			$reference = EmbeddedReference::next( $order );
			$result    = ( new ApiClient( $this, $order ) )->create_sdk_payment(
				CreateSdkPaymentDTO::create( $order, $pay_session, $this->get_return_url( $order ), $reference )
			);
		} catch ( \Throwable $e ) {
			\delete_transient( $lock_key );
			$order->add_order_note( "❌ {$this->method_title} 建立交易失敗：{$e->getMessage()}" );
			if ( self::is_duplicate_order_error( $e ) ) {
				throw new DispatchException(
					ErrorCode::CONFLICT,
					\__( '此訂單的付款交易已存在，請重新整理頁面後再試；若仍無法付款請聯繫商家', 'power_checkout' ),
					409
				);
			}
			throw $e;
		}

		// 官方：沒有 nextAction 代表建單失敗，不可呼叫 pay()
		if ( null === $result['next_action'] ) {
			\delete_transient( $lock_key );
			$order->add_order_note(
				\sprintf(
					'❌ %1$s 建立交易失敗：回應無 nextAction（tradeOrderId: %2$s，狀態: %3$s）%4$s',
					$this->method_title,
					$result['trade_order_id'],
					$result['status'],
					'' !== $result['payment_msg'] ? "<br>{$result['payment_msg']}" : ''
				)
			);
			throw new \RuntimeException( 'SLP trade/payment/create 回應無 nextAction' );
		}

		if ( '' !== $result['trade_order_id'] ) {
			// 建立後即寫入，供 webhook / order-received 主動查詢找單（伺服器端值，非前端帶入）
			( new MetaKeys( $order ) )->update_payment_identity( $result['trade_order_id'] );
			$order->add_order_note( "{$this->method_title} 交易已建立，等待顧客完成付款（tradeOrderId: {$result['trade_order_id']}）" );
		}

		return [
			'mode'        => PaymentMode::CLIENT_SDK->value,
			'provider'    => self::ID,
			'phase'       => 'confirm',
			'next_action' => $result['next_action'],
		];
	}

	/**
	 * 主動查詢交易並補單（後台 / 程式呼叫；不節流）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> { result: ReturnSyncResult::value, status: SLP 付款狀態 }
	 */
	public function query_trade( \WC_Order $order ): array {
		$sync   = new EmbeddedSyncManager( $this, $order );
		$result = $sync->sync( false );

		return [
			'result' => $result->value,
			'status' => null !== $sync->last_payment && isset( $sync->last_payment->status ) ? $sync->last_payment->status : '',
		];
	}

	// endregion

	// region 退款（重用既有 ApiClient::create_refund；與導轉式同一套規則）

	/**
	 * 能否退款（admin 點退款時，WC 先呼叫此方法做能力檢查）
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   退款金額
	 * @param string     $reason   退款原因
	 * @return bool|\WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $amount ) {
			return false;
		}

		try {
			return PaymentDTO::from_order( $order )->get_payment_method()->can_refund( $order, (float) $amount );
		} catch ( \Throwable $e ) {
			$this->logger( "❌ #{$order_id} 退款失敗： {$e->getMessage()}", 'error', [], 5, false );
			return NormalizedError::from(
				ErrorCode::UNKNOWN,
				\__( '退款失敗，詳情請查閱 log 紀錄', 'power_checkout' ),
				[
					'provider'    => $this->id,
					'raw_message' => $e->getMessage(),
				]
			);
		}
	}

	/**
	 * 退款 API 發送（woocommerce_order_refunded 觸發；失敗走共用 fail_gateway_refund）
	 *
	 * @param int $order_id  訂單 id
	 * @param int $refund_id 退款 id
	 * @return void
	 */
	public function process_gateway_refund( int $order_id, int $refund_id ): void {
		if ( ! $this->is_this_gateway( $order_id ) ) {
			return;
		}

		$refund = \wc_get_order( $refund_id );
		if ( ! $refund instanceof \WC_Order_Refund || ! $refund->get_refunded_payment() ) {
			return; // 手動退款不打 API
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		global $wpdb;
		try {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore
			$reason       = $refund->get_reason();
			$response_dto = ( new ApiClient( $this, $order ) )->create_refund( (float) $refund->get_amount(), $reason );
			RedirectGateway::handle_refund_response( $response_dto, $order, $reason );
			$wpdb->query( 'COMMIT' ); // phpcs:ignore
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore
			$raw_code = 0 !== $e->getCode() ? (string) $e->getCode() : null;
			self::fail_gateway_refund( $order, $refund, $this->method_title, $e->getMessage(), $raw_code );
		}
	}

	// endregion

	// region webhook（ASM-3：共用既有 /power-checkout/slp/webhook；此處只提供契約一致的驗簽）

	/**
	 * 以導轉式 signKey 驗 SLP webhook 簽章（HMAC-SHA256 of "{timestamp}.{body}"，timestamp 容差 5 分鐘）
	 *
	 * Fail-closed：signKey / sign / timestamp 缺任一即 false；不因 local 環境略過。
	 *
	 * @param \WP_REST_Request $request 回呼請求
	 * @return bool
	 */
	public function verify_callback( \WP_REST_Request $request ): bool {
		$sign_key  = RedirectSettingsDTO::instance()->signKey;
		$timestamp = (string) $request->get_header( 'timestamp' );
		$sign      = (string) $request->get_header( 'sign' );
		if ( '' === $sign_key || '' === $sign || ! \ctype_digit( $timestamp ) ) {
			return false;
		}

		if ( \abs( \time() * 1000 - (int) $timestamp ) > self::SIGNATURE_TOLERANCE_MS ) {
			return false;
		}

		return \hash_equals( \hash_hmac( 'sha256', "{$timestamp}.{$request->get_body()}", $sign_key ), $sign );
	}

	// endregion

	// region 設定 / 註冊

	/**
	 * 設定（只有顯示欄位；憑證共用導轉式）
	 *
	 * @param bool $with_default 是否含預設值（嵌入式設定無憑證欄位，兩者相同）
	 * @return array<string, mixed>
	 */
	public static function get_settings( bool $with_default = true ): array {
		return EmbeddedSettingsDTO::instance()->to_array();
	}

	/**
	 * Provider meta：client-sdk；refund / partial_refund / query（方法層級：覆寫 process_refund / query_trade）
	 *
	 * @return ProviderMetaDTO
	 */
	public function get_provider_meta(): ProviderMetaDTO {
		return new ProviderMetaDTO(
			id: self::ID,
			payment_mode: PaymentMode::CLIENT_SDK,
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

	/**
	 * 初始化（gateway 啟用時由 ProviderRegister 呼叫）
	 *
	 * 藍圖 §9 修正 2：既有 SLP webhook 只在 RedirectGateway::init() 註冊，只開嵌入式時端點會不存在；
	 * 此處也呼叫 WebHook::instance()（singleton，與導轉式同時啟用時冪等）。
	 *
	 * @return void
	 */
	public static function init(): void {
		WebHook::instance();

		if ( \did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_checkout_blocks();
		} else {
			\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_checkout_blocks' ] );
		}
	}

	/**
	 * 註冊區塊結帳付款方式（block 階段只選付款方式；SDK 收銀台在 order-received 頁掛載）
	 *
	 * @return void
	 */
	public static function register_checkout_blocks(): void {
		\add_action(
			'woocommerce_blocks_payment_method_type_registration',
			function ( PaymentMethodRegistry $payment_method_registry ): void {
				if ( ! \class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
					return;
				}

				if ( ! \class_exists( GatewayUtils::class ) ) {
					require_once Plugin::$dir . '/inc/classes/Domains/Payment/Shared/Utils/GatewayUtils.php';
				}

				$gateway = GatewayUtils::get_gateway( self::ID );
				if ( $gateway instanceof AbstractPaymentGateway ) {
					$payment_method_registry->register( new BlocksIntegration( $gateway ) );
				}
			}
		);
	}

	// endregion

	/**
	 * SDK 分期期數（共用導轉式信用卡分期設定；"0" = 不分期）
	 *
	 * @param RedirectSettingsDTO $settings 導轉式設定
	 * @return array<int, string>
	 */
	private static function get_installment_counts( RedirectSettingsDTO $settings ): array {
		$counts = $settings->paymentMethodOptions['CreditCard']['installmentCounts'] ?? [ '0' ];
		$counts = \array_map( 'strval', $counts );
		return [] === $counts ? [ '0' ] : $counts;
	}

	/**
	 * SLP 是否回「重複下單」（1001 referenceOrderId 重複 / 1006 referenceId 重複）
	 *
	 * @param \Throwable $e 建立交易時的例外（Requester 以 SLP 錯誤碼為 exception code）
	 * @return bool
	 */
	private static function is_duplicate_order_error( \Throwable $e ): bool {
		return \in_array( (string) $e->getCode(), [ SlpErrorCode::E_1001->value, SlpErrorCode::E_1006->value ], true );
	}

	/**
	 * 最近一次查詢的交易是否仍在處理中（此時不可再渲染收銀台，避免重複扣款）
	 *
	 * @param PaymentDTO|null $payment 最近一次查詢結果
	 * @return bool
	 */
	private static function is_awaiting( ?PaymentDTO $payment ): bool {
		if ( null === $payment || ! isset( $payment->status ) ) {
			return false;
		}

		return \in_array(
			ResponseStatus::tryFrom( $payment->status ),
			[ ResponseStatus::PROCESSING, ResponseStatus::PENDING ],
			true
		);
	}
}
