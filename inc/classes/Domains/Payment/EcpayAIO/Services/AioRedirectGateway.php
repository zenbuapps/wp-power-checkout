<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\EcpayAIO\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\AioSettingsDTO;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\DTOs\RequestParams;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\AioCallback;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Http\DoActionClient;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\AioBlocksIntegration;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayMetaKeys;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\EcpayPaymentType;
use J7\PowerCheckout\Domains\Payment\EcpayAIO\Shared\Helpers\TradeNo;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Domains\Payment\Shared\DTOs\PaymentCapabilitiesDTO;
use J7\PowerCheckout\Domains\Payment\Shared\DTOs\ProviderMetaDTO;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderCreationStrategy;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\PaymentMode;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\WP;

/**
 * 綠界 ECPay 全方位金流（AIO，導轉式）付款閘道
 *
 * 與 SLP RedirectGateway 不同處：SLP 在 process_payment 階段呼叫 API 取得 sessionUrl 再跳轉；
 * AIO 不在 process_payment 發 API，而是在 order-received 頁以 auto-form 自動 submit 建單參數
 * （含 CheckMacValue SHA256）POST 至綠界 Cashier 託管頁，故：
 *  - before_process_payment 僅回傳 order-received URL
 *  - before_order_received 負責組裝 RequestParams 並 render auto-form
 *
 * 仿 ShoplinePayment\Services\RedirectGateway。
 */
final class AioRedirectGateway extends AbstractPaymentGateway implements IGateway {

	/** @var string 付款方式 ID */
	public const ID = 'ecpay_aio';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示付款方式標題 */
	public $method_title = '綠界 ECPay 全方位金流（導轉）';

	/** @var string 後台顯示付款方式描述 */
	public $method_description = '綠界 ECPay AIO 全方位金流，支援信用卡、ATM、超商代碼、超商條碼、WebATM、Apple Pay 等付款方式';

	/**
	 * @var array<int, bool> 本次 HTTP 請求對各訂單的導轉嘗試（true = 已輸出導轉表單；false = 嘗試過但建單失敗）
	 *
	 * 同請求防重入：每個 gateway 實例都在建構時掛了 wp hook，before_order_received 可能被呼叫多次，
	 * 有記錄即不再產生第二個 MerchantTradeNo。thank-you 付款提示僅在「已輸出表單」時略過。
	 */
	private static array $redirect_attempts = [];

	/**
	 * 綠界 AIO 核心支付邏輯
	 *
	 * AIO 不在此階段發 API，僅回傳 order-received URL；實際跳轉在 before_order_received
	 * 以 auto-form submit 至綠界 Cashier。
	 *
	 * 顧客於結帳頁選了信用卡分期期數時，先依後台白名單驗證（validate_fields 已驗過，
	 * 此處為 order-pay 等其他路徑的雙重保險）再依 InstallmentCheckoutField::apply_to_order 的規則更新 order meta
	 * （本次選「不分期」會清掉上一次的分期），交給 RequestParams 組 CreditInstallment。
	 *
	 * 最後標記「待導轉」：本方法只在顧客實際發起付款時執行（classic / block 結帳、WC 沿用 pending 訂單再結帳、
	 * 我的帳戶 → 付款），order-received 頁據此以新的 MerchantTradeNo 重新導轉綠界。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string order-received URL
	 * @throws \Exception 分期期數不在允許範圍
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		$periods     = AioSettingsDTO::instance()->get_checkout_installment_periods();
		$installment = InstallmentCheckoutField::get_posted_installment( $periods );
		InstallmentCheckoutField::apply_to_order( $order, $installment, InstallmentCheckoutField::is_submitted( $periods ) );

		( new EcpayMetaKeys( $order ) )->mark_redirect_pending();

		return $this->get_return_url( $order );
	}

	// region 結帳頁信用卡分期欄位

	/**
	 * 是否有結帳欄位（有可選分期期數時為 true，classic checkout 才會渲染 payment_box）
	 *
	 * @return bool
	 */
	public function has_fields(): bool {
		return [] !== AioSettingsDTO::instance()->get_checkout_installment_periods();
	}

	/**
	 * Classic checkout 付款方式欄位：描述（+ 既有 tokenization UI）+ 信用卡分期下拉
	 *
	 * @return void
	 */
	public function payment_fields(): void {
		parent::payment_fields();

		echo InstallmentCheckoutField::render( AioSettingsDTO::instance()->get_checkout_installment_periods() );
	}

	/**
	 * 結帳欄位驗證（classic checkout / Store API legacy / order-pay 皆會在 process_payment 前呼叫）
	 *
	 * @return bool 分期期數合法（或不分期）回 true；否則加 error notice 並回 false
	 */
	public function validate_fields(): bool {
		try {
			InstallmentCheckoutField::get_posted_installment( AioSettingsDTO::instance()->get_checkout_installment_periods() );
			return true;
		} catch ( \Throwable $e ) {
			\wc_add_notice( \__( '所選的信用卡分期期數不在允許範圍，請重新選擇', 'power_checkout' ), 'error' );
			return false;
		}
	}

	// endregion

	/**
	 * 第三方金流頁面 render 前（order-received）：以新的 MerchantTradeNo 組裝 RequestParams 並 render auto-form
	 *
	 * 何時導轉綠界（全部成立才導轉）：
	 *  1. 訂單仍待付款（needs_payment：pending / failed 且金額 > 0）——已付款絕不再導轉。
	 *  2. 網址 order key 正確（防匿名列舉 /checkout/order-received/{id}/ 觸發建單、看到訂單內容）。
	 *  3. 本請求尚未為此訂單輸出過表單（每個 gateway 實例都掛 wp hook，同請求不得產生兩個編號）。
	 *  4. 顧客剛發起付款（process_payment 留下待導轉旗標），或此訂單從未送出過編號（首次 / 升級前建立的訂單）。
	 *     單純重訪（重新整理 / 上一頁 / 綠界頁按「返回商店」= ClientBackURL）不自動導轉，避免顧客被困在
	 *     「返回商店 → 又被導回綠界」的迴圈；thank-you 頁改由 {@see self::render_pending_payment_notice()} 提供付款連結。
	 *
	 * 每次導轉都用新的 MerchantTradeNo：綠界同一編號未付款前再送也會拒絕（10300028 訂單編號重覆）。
	 * 舊編號保留於歷史（EcpayMetaKeys::start_trade_attempt），其 ReturnURL / PaymentInfoURL 仍能反查本訂單。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	protected function before_order_received( \WC_Order $order ): void {
		$order_id = $order->get_id();
		if ( ! $order->needs_payment() || ! self::is_valid_order_key( $order ) || isset( self::$redirect_attempts[ $order_id ] ) ) {
			return;
		}

		try {
			$meta_keys = new EcpayMetaKeys( $order );
			if ( '' !== $meta_keys->get_trade_no() && ! $meta_keys->is_redirect_pending() ) {
				return;
			}

			// 先佔位：即使下方組參數失敗，同一請求的其他 gateway 實例也不再重試建單
			self::$redirect_attempts[ $order_id ] = false;

			$trade_no       = TradeNo::encode_unique( $order_id, $meta_keys->get_used_trade_nos() );
			$request_params = RequestParams::instance( $order, $this, $trade_no );

			// 參數組裝成功才記錄本次編號（目前編號 + 歷史）並清除待導轉旗標
			$meta_keys->start_trade_attempt( $trade_no );

			// render auto-form（隱藏表單自動 submit 至綠界 Cashier）
			Plugin::load_template(
				'auto-form',
				[
					'params' => $request_params->to_form_params(),
					'url'    => AioSettingsDTO::instance()->endpoint,
				]
			);
			self::$redirect_attempts[ $order_id ] = true;
		} catch ( \Throwable $e ) {
			$this->logger( "❌ {$this->title} 建單時發生錯誤<br>{$e->getMessage()}", 'error', [], 5 );
		}
	}

	/**
	 * 檢查 order-received 網址的 order key 是否與訂單相符（timing-safe）
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	private static function is_valid_order_key( \WC_Order $order ): bool {
		// order-received 為 GET 頁面，僅以 order key 驗擁有權（同 WC_Shortcode_Checkout::order_received），無 nonce
		$key = isset( $_GET['key'] ) && \is_string( $_GET['key'] ) ? \sanitize_text_field( \wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return '' !== $key && \hash_equals( $order->get_order_key(), $key );
	}

	/**
	 * [thank-you 頁] 待付款訂單顯示「前往付款」連結（woocommerce_thankyou_ecpay_aio）
	 *
	 * 單純重訪 order-received 不自動導轉綠界（見 before_order_received），此處提供重新付款入口：
	 * 連到 WC 的 order-pay 頁 → process_payment → 回 order-received 以新的 MerchantTradeNo 導轉。
	 * 已付款 / failed（WC 模板已自帶付款按鈕）/ 本請求已輸出自動導轉表單 → 不顯示（建單失敗時仍顯示，讓顧客有入口重試）。
	 * classic thankyou.php 與 block 主題 Order Confirmation（AdditionalInformation block）皆會觸發此 hook。
	 *
	 * @param int $order_id 訂單 ID
	 * @return void
	 */
	public static function render_pending_payment_notice( int $order_id ): void {
		if ( true === ( self::$redirect_attempts[ $order_id ] ?? false ) ) {
			return;
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || self::ID !== $order->get_payment_method() ) {
			return;
		}
		if ( ! $order->has_status( 'pending' ) || ! $order->needs_payment() ) {
			return;
		}

		// 以 printf + 逐一 escape 輸出（heredoc 插值會被 EscapeOutput sniff 視為未 escape）
		\printf(
			'<div class="woocommerce-info pc-ecpay-aio-pending-payment">%1$s <a href="%2$s" class="button pay">%3$s</a></div>',
			\esc_html__( '此訂單尚未完成付款。若已取得 ATM 虛擬帳號或超商繳費代碼，請於期限內依代碼繳費即可；如需重新付款或改用其他付款方式，請點選「前往付款」。', 'power_checkout' ),
			\esc_url( $order->get_checkout_payment_url() ),
			\esc_html__( '前往付款', 'power_checkout' )
		);
	}

	/** [Admin] 在後台 order detail 頁地址下方顯示資訊 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$meta_keys = new EcpayMetaKeys( $order );

		$payment_info = $meta_keys->get_payment_info();
		if ( $payment_info ) {
			echo WP::array_to_html( $payment_info, [ 'title' => '綠界繳費資訊' ] );
		}

		$payment_detail = $meta_keys->get_payment_detail();
		if ( $payment_detail ) {
			echo WP::array_to_html( $payment_detail, [ 'title' => '綠界付款明細' ] );
		}
	}

	// region 設定

	/**
	 * @param bool $with_default 是否含預設值，還是只拿 DB 值
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if ( ! $with_default ) {
			$default_array = ( new AioSettingsDTO() )->to_array();
			$unset_keys    = [ 'merchantId', 'hashKey', 'hashIv', 'endpoint' ];
			foreach ( $unset_keys as $key ) {
				unset( $default_array[ $key ] );
			}

			$option = ProviderUtils::get_option( self::ID );
			return \wp_parse_args( \is_array( $option ) ? $option : [], $default_array );
		}
		return AioSettingsDTO::instance()->to_array();
	}

	/**
	 * [後台] 自訂欄位驗證邏輯（min/max 金額）
	 *
	 * @return bool was anything saved?
	 * @see WC_Settings_API::process_admin_options
	 */
	public function process_admin_options(): bool {
		$min_amount_name = $this->get_field_key( 'minAmount' );
		$max_amount_name = $this->get_field_key( 'maxAmount' );

		@[
			$min_amount_name => $min_amount,
			$max_amount_name => $max_amount,
		] = $this->get_post_data();

		$min_amount = (float) $min_amount;
		$max_amount = (float) $max_amount;

		if ( $min_amount < 0 ) {
			$this->errors[] = \sprintf(
				/* translators: %s: gateway title */
				\__( 'Save failed. %s minimum amount out of range.', 'power_checkout' ),
				$this->method_title
			);
		}

		if ( $max_amount > 0 && $max_amount < $min_amount ) {
			$this->errors[] = \sprintf(
				/* translators: %s: gateway title */
				\__( 'Save failed. %s maximum amount out of range.', 'power_checkout' ),
				$this->method_title
			);
		}

		if ( $this->errors ) {
			$this->display_errors();
			return false;
		}

		return parent::process_admin_options();
	}

	// endregion

	// region 退款

	/**
	 * 能否處理退款（admin 點按退款時觸發的前置檢查）
	 *
	 * 退款分流（資安：判定依據為綠界回傳並存於 _pc_ecpay_payment_detail 的 PaymentType，非前端）：
	 *  - 信用卡類（Credit_* / Flexible_*）→ 回 true，實際 API 退款於 handle_payment_gateway_refund。
	 *  - 非信用卡（ATM/WebATM/CVS/BARCODE/ApplePay）→ 回 WP_Error('refund_unsupported')，不呼叫任何退款 API。
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   退款金額（WC 計算，僅作非空檢查）
	 * @param string     $reason   退款原因
	 * @return bool|\WP_Error
	 * @see WC_Payment_Gateway::process_refund
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $amount ) {
			return false;
		}

		// 非信用卡一律擋下，不呼叫退款 API（D5：ATM/CVS/BARCODE/WebATM/ApplePay 須人工）
		if ( ! EcpayPaymentType::order_is_credit( $order ) ) {
			return NormalizedError::from(
				ErrorCode::UNSUPPORTED,
				\__( '此付款方式不支援 API 退款，請至綠界商家後台人工處理', 'power_checkout' ),
				[ 'provider' => $this->id ]
			);
		}

		// 信用卡類：允許退款，實際 DoAction API 於 handle_payment_gateway_refund 發送
		return true;
	}

	/**
	 * 退款 API 發送（退款創建 woocommerce_order_refunded 時觸發）
	 *
	 * 信用卡類訂單呼叫綠界 DoAction(Action=R)；非信用卡 process_refund 已擋下故不會走 API。
	 * wpdb 交易；失敗收尾走 fail_gateway_refund（刪 refund + 全額退款狀態回滾 + 失敗原因透出給 REST 層）。
	 *
	 * @param int $order_id  訂單 id
	 * @param int $refund_id 退款 id
	 * @return void
	 */
	public function process_gateway_refund( int $order_id, int $refund_id ): void {
		if ( ! $this->is_this_gateway( $order_id ) ) {
			return;
		}

		/** @var \WC_Order_Refund $refund */
		$refund = \wc_get_order( $refund_id );
		if ( ! $refund->get_refunded_payment() ) { // 手動退款（未經 gateway）不發 API
			return;
		}

		/** @var \WC_Order $order */
		$order = \wc_get_order( $order_id );

		// 防禦：非信用卡不發 API（process_refund 理應已擋，雙重保險）
		if ( ! EcpayPaymentType::order_is_credit( $order ) ) {
			$order->add_order_note( '⚠️ 非信用卡付款方式不支援 API 退款，請至綠界商家後台人工處理' );
			return;
		}

		global $wpdb;
		try {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore

			$trade_no = EcpayPaymentType::get_trade_no( $order );
			$amount   = (float) $refund->get_amount(); // 金額一律來自 WC refund 物件，非前端
			$reason   = (string) $refund->get_reason();

			$response = ( new DoActionClient( $order ) )->refund( $trade_no, $amount );

			$note = \sprintf(
				'✅ 綠界 ECPay 信用卡退款成功，金額 %1$s 元，TradeNo：%2$s，RtnMsg：%3$s。退款原因：%4$s',
				(int) \ceil( $amount ),
				$trade_no,
				$response['RtnMsg'],
				$reason
			);
			$order->add_order_note( $note );

			$wpdb->query( 'COMMIT' ); // phpcs:ignore
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore

			// raw_code：DoActionClient 訊息含「失敗 RtnCode=XXXX」時抽出；「回應無法解析」/ 連線失敗 / 缺 TradeNo 等無綠界 RtnCode → null
			$raw_code = \preg_match( '/失敗 RtnCode=([A-Za-z0-9]+)/u', $e->getMessage(), $m ) ? $m[1] : null;
			self::fail_gateway_refund( $order, $refund, '綠界 ECPay', $e->getMessage(), $raw_code );
		}
	}

	// endregion

	// region 後台訂單操作（請款 Capture / 取消授權 Cancel-Auth）

	/** @var string 後台訂單操作 — 請款（Action=C） */
	public const ACTION_CAPTURE = 'pc_ecpay_aio_capture';

	/** @var string 後台訂單操作 — 取消授權（Action=N） */
	public const ACTION_CANCEL_AUTH = 'pc_ecpay_aio_cancel_auth';

	/**
	 * 後台訂單操作清單注入請款 / 取消授權（woocommerce_order_actions filter）
	 *
	 * 僅信用卡類 AIO 訂單顯示（依綠界回傳並存於 _pc_ecpay_payment_detail 的 PaymentType 判定，非前端）。
	 * ATM/CVS/BARCODE/WebATM/ApplePay 與非綠界訂單不顯示。
	 *
	 * @param array<string, string> $actions 既有訂單操作
	 * @param \WC_Order|null        $order   訂單（WC 於 order detail 頁傳入）
	 * @return array<string, string>
	 */
	public static function add_order_actions( array $actions, ?\WC_Order $order = null ): array {
		if ( ! $order instanceof \WC_Order ) {
			return $actions;
		}
		if ( $order->get_payment_method() !== self::ID ) {
			return $actions;
		}
		// 僅信用卡類可請款 / 取消授權（DoAction C/N 僅信用卡）
		if ( ! EcpayPaymentType::order_is_credit( $order ) ) {
			return $actions;
		}

		$actions[ self::ACTION_CAPTURE ]     = \__( '綠界 ECPay 信用卡請款（關帳）', 'power_checkout' );
		$actions[ self::ACTION_CANCEL_AUTH ] = \__( '綠界 ECPay 信用卡取消授權', 'power_checkout' );
		return $actions;
	}

	/**
	 * 後台「請款」訂單操作 handler（Action=C）
	 *
	 * 信用卡類 → 呼叫綠界 DoAction(C) 請款，寫 capture meta + order note；
	 * 非信用卡 → 僅記錄不支援提示（雙重防禦，理應已被 add_order_actions 隱藏）。
	 * 任何 \Throwable 一律捕捉並記錄 order note，不外露內部錯誤。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function handle_capture_action( \WC_Order $order ): void {
		self::do_capture_or_void( $order, 'capture' );
	}

	/**
	 * 後台「取消授權」訂單操作 handler（Action=N）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function handle_cancel_auth_action( \WC_Order $order ): void {
		self::do_capture_or_void( $order, 'cancel_auth' );
	}

	/**
	 * 請款 / 取消授權共用流程
	 *
	 * @param \WC_Order $order  訂單
	 * @param string    $method 'capture'（請款 C）｜'cancel_auth'（取消授權 N）
	 * @return void
	 */
	private static function do_capture_or_void( \WC_Order $order, string $method ): void {
		$action_zh    = 'capture' === $method ? '請款' : '取消授權';
		$status_value = 'capture' === $method ? 'captured' : 'voided';

		if ( $order->get_payment_method() !== self::ID ) {
			return;
		}

		// 非信用卡一律擋下，不呼叫任何 API（依綠界 PaymentType，非前端）
		if ( ! EcpayPaymentType::order_is_credit( $order ) ) {
			$order->add_order_note( "⚠️ 非信用卡付款方式不支援綠界 API {$action_zh}，請至綠界商家後台人工處理" );
			return;
		}

		try {
			$trade_no = EcpayPaymentType::get_trade_no( $order );
			$amount   = (float) $order->get_total(); // 金額來自訂單，非前端
			$client   = new DoActionClient( $order );

			$response = 'capture' === $method
			? $client->capture( $trade_no, $amount )
			: $client->cancel_auth( $trade_no, $amount );

			( new EcpayMetaKeys( $order ) )->update_capture_status( $status_value );

			$order->add_order_note(
				\sprintf(
					'✅ 綠界 ECPay 信用卡%1$s成功，金額 %2$s 元，TradeNo：%3$s，RtnMsg：%4$s',
					$action_zh,
					(int) \ceil( $amount ),
					$trade_no,
					$response['RtnMsg']
				)
			);
		} catch ( \Throwable $e ) {
			// do_action 內已記錄失敗 order note；此處僅記錄至 log，不外露細節
			Plugin::logger(
				"綠界 AIO {$action_zh}失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	// endregion

	/** 初始化：註冊 callback + blocks + 後台訂單操作 */
	public static function init(): void {
		AioCallback::instance();

		// 整合區塊結帳
		\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_checkout_blocks' ] );

		// thank-you 頁：待付款訂單提供「前往付款」入口（單純重訪不自動導轉綠界）
		\add_action( 'woocommerce_thankyou_' . self::ID, [ __CLASS__, 'render_pending_payment_notice' ] );

		// 後台訂單操作：請款 / 取消授權（woocommerce_order_actions filter + 對應 handler action）
		\add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_order_actions' ], 10, 2 );
		\add_action( 'woocommerce_order_action_' . self::ACTION_CAPTURE, [ __CLASS__, 'handle_capture_action' ] );
		\add_action( 'woocommerce_order_action_' . self::ACTION_CANCEL_AUTH, [ __CLASS__, 'handle_cancel_auth_action' ] );
	}

	/** 註冊區塊結帳支援（仿 SLP） */
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

				if ( ! $gateway ) {
					return;
				}

				if ( $gateway instanceof AbstractPaymentGateway ) {
					// 用 AIO 專屬 integration，block data 額外帶結帳頁信用卡分期欄位設定
					$payment_method_registry->register( new AioBlocksIntegration( $gateway ) );
				}
			}
		);
	}

	/**
	 * Provider meta（能力為方法層級宣告，見 PaymentCapabilitiesDTO）
	 *
	 * 綠界 AIO：auto-submit 表單導轉（form-redirect）。
	 * 另有 admin action：請款（ACTION_CAPTURE）/ 取消授權（ACTION_CANCEL_AUTH）；未覆寫 capture() / void_auth()，故宣告 false。
	 *
	 * @return ProviderMetaDTO
	 */
	public function get_provider_meta(): ProviderMetaDTO {
		return new ProviderMetaDTO(
			id: self::ID,
			payment_mode: PaymentMode::FORM_REDIRECT,
			order_creation_strategy: OrderCreationStrategy::PRE_CREATE_PENDING,
			capabilities: new PaymentCapabilitiesDTO(
				refund: true,
				partial_refund: true,
				capture: false,
				void: false,
				query: false,
				recurring: false,
			),
		);
	}
}
