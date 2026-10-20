<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgRequestParams;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\DTOs\MpgSettingsDTO;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\DoActionClient;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\EWalletRefundClient;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\MpgCallback;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Http\QueryTradeClient;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgPaymentMethod;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgStatus;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgOrderNo;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgPaymentType;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\BlocksIntegration;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\PowerCheckout\Shared\Utils\ProviderUtils;
use J7\WpUtils\Classes\WP;

/**
 * 藍新 NewebPay MPG（多功能支付，導轉式）付款閘道
 *
 * 與綠界 AIO 同型（導轉式 + order-received 階段 auto-form POST 到第三方託管頁）：
 *  - before_process_payment 僅回傳 order-received URL（不發 API）。
 *  - before_order_received 組裝 MpgRequestParams（TradeInfo 加密 + TradeSha）並 render auto-form
 *    POST 至藍新 MPG mpg_gateway。
 *
 * 仿 EcpayAIO\Services\AioRedirectGateway。
 *
 * @see .claude/skills/newebpay-mpg/SKILL.md
 */
final class MpgRedirectGateway extends AbstractPaymentGateway implements IGateway {

	/** @var string 付款方式 ID */
	public const ID = 'newebpay_mpg';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示付款方式標題 */
	public $method_title = '藍新金流（導轉）';

	/** @var string 後台顯示付款方式描述 */
	public $method_description = '藍新 NewebPay MPG 多功能支付，支援信用卡、ATM、超商代碼、超商條碼、WebATM、LINE Pay、Apple Pay 等付款方式';

	/**
	 * @var array<int, bool> 本次 HTTP 請求對各訂單的導轉嘗試（true = 已輸出導轉表單；false = 嘗試過但建單失敗）
	 *
	 * 同請求防重入：每個 gateway 實例都在建構時掛了 wp hook，before_order_received 可能被呼叫多次，
	 * 有記錄即不再產生第二個 MerchantOrderNo。thank-you 繳費資訊 / 付款提示僅在「已輸出表單」時略過。
	 */
	private static array $redirect_attempts = [];

	/**
	 * 藍新 MPG 核心支付邏輯
	 *
	 * MPG 不在此階段發 API，僅回傳 order-received URL；實際跳轉在 before_order_received
	 * 以 auto-form submit 至藍新 mpg_gateway。
	 *
	 * 標記「待導轉」：本方法只在顧客實際發起付款時執行（classic / block 結帳、WC 沿用 pending 訂單再結帳、
	 * 我的帳戶 → 付款），order-received 頁據此以新的 MerchantOrderNo 導轉藍新一次。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string order-received URL
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		( new MpgMetaKeys( $order ) )->mark_redirect_pending();

		return $this->get_return_url( $order );
	}

	/**
	 * 第三方金流頁面 render 前（order-received）：以新的 MerchantOrderNo 組裝 MpgRequestParams 並 render auto-form
	 *
	 * 何時導轉藍新（全部成立才導轉；order key 已由 before_page_render 驗過）：
	 *  1. 訂單仍待付款（needs_payment）——已付款絕不再導轉（sandbox 實測：重複導轉被拒單，買家被甩出完成頁）。
	 *  2. 本請求尚未為此訂單輸出過表單（每個 gateway 實例都掛 wp hook，同請求不得產生兩個編號）。
	 *  3. 顧客剛發起付款（process_payment 留下待導轉旗標），或此訂單從未送出過編號（升級前建立的訂單）。
	 *     單純重訪（ATM / 超商取號後導回、付款失敗導回、MPG03008 導回、重新整理、上一頁）不自動導轉——
	 *     取號型付款取號後訂單本來就維持待付款，舊版每次進完成頁都重送同一編號，藍新回 MPG03008 再導回完成頁，
	 *     形成無限循環（issue #25）。thank-you 頁改由 {@see self::render_thankyou_payment_info()} 顯示繳費資訊或付款連結。
	 *
	 * 每次導轉都用新的 MerchantOrderNo：藍新同一編號送出後不可再送（MPG03008）。
	 * 舊編號保留於歷史（MpgMetaKeys::start_payment_attempt），其取號 / 繳費通知仍能反查本訂單。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	protected function before_order_received( \WC_Order $order ): void {
		$order_id = $order->get_id();
		if ( ! $order->needs_payment() || isset( self::$redirect_attempts[ $order_id ] ) ) {
			return;
		}

		try {
			$meta_keys = new MpgMetaKeys( $order );
			if ( '' !== $meta_keys->get_order_no() && ! $meta_keys->is_redirect_pending() ) {
				return;
			}

			// 先佔位：即使下方組參數失敗，同一請求的其他 gateway 實例也不再重試建單
			self::$redirect_attempts[ $order_id ] = false;

			$order_no       = MpgOrderNo::encode_unique( $order_id, $meta_keys->get_used_order_nos() );
			$request_params = MpgRequestParams::instance( $order, $this, $order_no );

			// 參數組裝成功才記錄本次編號（目前編號 + 歷史）並清除待導轉旗標
			$meta_keys->start_payment_attempt( $order_no );

			// render auto-form（隱藏表單自動 submit 至藍新 mpg_gateway）
			Plugin::load_template(
				'auto-form',
				[
					'params' => $request_params->to_form_params(),
					'url'    => MpgSettingsDTO::instance()->endpoint,
				]
			);
			self::$redirect_attempts[ $order_id ] = true;
		} catch ( \Throwable $e ) {
			$this->logger( "❌ {$this->title} 建單時發生錯誤<br>{$e->getMessage()}", 'error', [], 5 );
		}
	}

	/**
	 * [thank-you 頁] 顯示取號繳費資訊，或待付款訂單的「前往付款」連結（woocommerce_thankyou_newebpay_mpg）
	 *
	 *  - 已取號（ATM 虛擬帳號 / 超商代碼 / 超商條碼）：顯示繳費資訊。CustomerURL 指向本站，藍新不會在它的頁面顯示帳號，
	 *    這裡是顧客唯一看得到帳號的地方。
	 *  - 尚未取號、仍待付款：單純重訪不再自動導轉（見 before_order_received），提供 order-pay 連結重新付款
	 *    （order-pay → process_payment → 回 order-received 以新的 MerchantOrderNo 導轉）。
	 *  - 已付款 / 本請求已輸出自動導轉表單 → 不顯示（建單失敗時仍顯示，讓顧客有入口重試）。
	 *
	 * @param int $order_id 訂單 ID
	 * @return void
	 */
	public static function render_thankyou_payment_info( int $order_id ): void {
		if ( true === ( self::$redirect_attempts[ $order_id ] ?? false ) ) {
			return;
		}

		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || self::ID !== $order->get_payment_method() || ! $order->needs_payment() ) {
			return;
		}

		$payment_info = ( new MpgMetaKeys( $order ) )->get_payment_info();
		if ( $payment_info ) {
			echo self::get_payment_info_html( $payment_info ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 內容已逐欄 escape
			return;
		}

		if ( ! $order->has_status( 'pending' ) ) {
			return; // failed 訂單 WC 模板已自帶付款按鈕
		}

		// 以 printf + 逐一 escape 輸出（heredoc 插值會被 EscapeOutput sniff 視為未 escape）
		\printf(
			'<div class="woocommerce-info pc-newebpay-mpg-pending-payment">%1$s <a href="%2$s" class="button pay">%3$s</a></div>',
			\esc_html__( '此訂單尚未完成付款。如需重新付款或改用其他付款方式，請點選「前往付款」。', 'power_checkout' ),
			\esc_url( $order->get_checkout_payment_url() ),
			\esc_html__( '前往付款', 'power_checkout' )
		);
	}

	/**
	 * 組前台取號繳費資訊 HTML（逐欄 escape）
	 *
	 * @param array<string, mixed> $payment_info 取號繳費資訊（StatusManager 寫入的 _pc_newebpay_payment_info）
	 * @return string HTML
	 */
	private static function get_payment_info_html( array $payment_info ): string {
		$method = MpgPaymentMethod::tryFrom( (string) ( $payment_info['PaymentType'] ?? '' ) );

		$expire     = \trim( ( (string) ( $payment_info['ExpireDate'] ?? '' ) ) . ' ' . ( (string) ( $payment_info['ExpireTime'] ?? '' ) ) );
		$code_label = MpgPaymentMethod::VACC === $method ? \__( '轉帳帳號', 'power_checkout' ) : \__( '繳費代碼', 'power_checkout' );

		$rows = [
			\__( '付款方式', 'power_checkout' ) => $method?->label() ?? (string) ( $payment_info['PaymentType'] ?? '' ),
			\__( '銀行代碼', 'power_checkout' ) => (string) ( $payment_info['BankCode'] ?? '' ),
			$code_label                     => (string) ( $payment_info['CodeNo'] ?? '' ),
			\__( '條碼一', 'power_checkout' )  => (string) ( $payment_info['Barcode_1'] ?? '' ),
			\__( '條碼二', 'power_checkout' )  => (string) ( $payment_info['Barcode_2'] ?? '' ),
			\__( '條碼三', 'power_checkout' )  => (string) ( $payment_info['Barcode_3'] ?? '' ),
			\__( '繳費期限', 'power_checkout' ) => $expire,
		];

		$items = '';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$items .= \sprintf( '<li>%1$s：<strong>%2$s</strong></li>', \esc_html( (string) $label ), \esc_html( $value ) );
		}

		return \sprintf(
			'<section class="woocommerce-order-details pc-newebpay-mpg-payment-info"><h2 class="woocommerce-order-details__title">%1$s</h2><p>%2$s</p><ul>%3$s</ul></section>',
			\esc_html__( '繳費資訊', 'power_checkout' ),
			\esc_html__( '請於繳費期限內完成付款，付款完成後訂單會自動更新。', 'power_checkout' ),
			$items
		);
	}

	/** [Admin] 在後台 order detail 頁地址下方顯示資訊 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$meta_keys = new MpgMetaKeys( $order );

		$payment_info = $meta_keys->get_payment_info();
		if ( $payment_info ) {
			echo WP::array_to_html( $payment_info, [ 'title' => '藍新金流繳費資訊' ] );
		}

		$payment_detail = $meta_keys->get_payment_detail();
		if ( $payment_detail ) {
			echo WP::array_to_html( $payment_detail, [ 'title' => '藍新金流付款明細' ] );
		}
	}

	// region 設定

	/**
	 * @param bool $with_default 是否含預設值，還是只拿 DB 值
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if ( ! $with_default ) {
			$default_array = ( new MpgSettingsDTO() )->to_array();
			$unset_keys    = [ 'merchantId', 'hashKey', 'hashIv', 'endpoint' ];
			foreach ( $unset_keys as $key ) {
				unset( $default_array[ $key ] );
			}

			$option = ProviderUtils::get_option( self::ID );
			return \wp_parse_args( \is_array( $option ) ? $option : [], $default_array );
		}
		return MpgSettingsDTO::instance()->to_array();
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

	// region 退款（信用卡 Close CloseType=2 / e-wallet refund）

	/**
	 * 能否處理退款（admin 點按退款時觸發的前置檢查）
	 *
	 * 退款分流（資安：判定依據為藍新回傳並存於 _pc_newebpay_payment_detail 的 PaymentType，非前端）：
	 *  - 信用卡（CREDIT）→ 回 true，實際 Close API 退款於 handle_payment_gateway_refund。
	 *  - e-wallet（LINEPAY/TAIWANPAY/ESUNWALLET）→ 回 true，實際 EWallet refund 於 handle_payment_gateway_refund。
	 *  - 其他（VACC/WEBATM/CVS/BARCODE/APPLEPAY/TWQR/AFTEE）→ 回正規化 ErrorCode::UNSUPPORTED \WP_Error，不呼叫任何退款 API。
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

		// 信用卡或 e-wallet 才允許 API 退款；其餘擋下
		if ( MpgPaymentType::order_is_credit( $order ) || MpgPaymentType::order_is_ewallet( $order ) ) {
			return true;
		}

		return NormalizedError::from(
			ErrorCode::UNSUPPORTED,
			\__( '此付款方式不支援 API 退款，請至藍新金流商家後台人工處理', 'power_checkout' ),
			[ 'provider' => $this->id ]
		);
	}

	/**
	 * 退款 API 發送（退款創建 woocommerce_order_refunded 時觸發）
	 *
	 * 信用卡 → Close CloseType=2；e-wallet → /API/EWallet/refund。
	 * 非信用卡 / 非 e-wallet process_refund 已擋下故不會走 API。
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
		$order        = \wc_get_order( $order_id );
		$payment_type = MpgPaymentType::from_order( $order );

		// 防禦：非信用卡且非 e-wallet 不發 API（process_refund 理應已擋，雙重保險）
		if ( ! MpgPaymentType::is_credit( $payment_type ) && ! MpgPaymentType::is_ewallet( $payment_type ) ) {
			$order->add_order_note( '⚠️ 此付款方式不支援 API 退款，請至藍新金流商家後台人工處理' );
			return;
		}

		global $wpdb;
		try {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore

			$trade_no = MpgPaymentType::get_trade_no( $order );
			$amount   = (float) $refund->get_amount(); // 金額一律來自 WC refund 物件，非前端
			$reason   = (string) $refund->get_reason();

			if ( MpgPaymentType::is_ewallet( $payment_type ) ) {
				( new EWalletRefundClient( $order ) )->refund( $trade_no, $amount, $payment_type );
				$channel = "e-wallet（{$payment_type}）";
			} else {
				( new DoActionClient( $order ) )->refund( $trade_no, $amount );
				$channel = '信用卡';
			}

			$order->add_order_note(
				\sprintf(
					'✅ 藍新金流%1$s退款成功，金額 %2$d 元，TradeNo：%3$s。退款原因：%4$s',
					$channel,
					(int) \ceil( $amount ),
					$trade_no,
					$reason
				)
			);

			$wpdb->query( 'COMMIT' ); // phpcs:ignore
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore

			// raw_code：訊息含藍新 Status 碼時抽出
			$raw_code = \preg_match( '/Status=([A-Z0-9]+)/', $e->getMessage(), $m ) ? $m[1] : null;
			self::fail_gateway_refund( $order, $refund, '藍新金流', $e->getMessage(), $raw_code );
		}
	}

	// endregion

	// region 後台訂單操作（請款 Capture / 取消授權 Cancel-Auth）

	/** @var string 後台訂單操作 — 請款（Close CloseType=1） */
	public const ACTION_CAPTURE = 'pc_newebpay_mpg_capture';

	/** @var string 後台訂單操作 — 取消授權（Cancel） */
	public const ACTION_CANCEL_AUTH = 'pc_newebpay_mpg_cancel_auth';

	/**
	 * 後台訂單操作清單注入請款 / 取消授權（woocommerce_order_actions filter）
	 *
	 * 僅信用卡 MPG 訂單顯示（依藍新回傳並存於 meta 的 PaymentType 判定，非前端）。
	 *
	 * @param array<string, string> $actions 既有訂單操作
	 * @param \WC_Order|null        $order   訂單（WC 於 order detail 頁傳入）
	 * @return array<string, string>
	 */
	public static function add_capture_actions( array $actions, ?\WC_Order $order = null ): array {
		if ( ! $order instanceof \WC_Order ) {
			return $actions;
		}
		if ( $order->get_payment_method() !== self::ID ) {
			return $actions;
		}
		// 僅信用卡可請款 / 取消授權（Close / Cancel 僅信用卡）
		if ( ! MpgPaymentType::order_is_credit( $order ) ) {
			return $actions;
		}

		$actions[ self::ACTION_CAPTURE ]     = \__( '藍新金流信用卡請款（關帳）', 'power_checkout' );
		$actions[ self::ACTION_CANCEL_AUTH ] = \__( '藍新金流信用卡取消授權', 'power_checkout' );
		return $actions;
	}

	/**
	 * 後台「請款」訂單操作 handler（Close CloseType=1）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function handle_capture_action( \WC_Order $order ): void {
		self::do_capture_or_void( $order, 'capture' );
	}

	/**
	 * 後台「取消授權」訂單操作 handler（Cancel）
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
	 * @param string    $method 'capture'（請款）｜'cancel_auth'（取消授權）
	 * @return void
	 */
	private static function do_capture_or_void( \WC_Order $order, string $method ): void {
		$action_zh    = 'capture' === $method ? '請款' : '取消授權';
		$status_value = 'capture' === $method ? 'captured' : 'voided';

		if ( $order->get_payment_method() !== self::ID ) {
			return;
		}

		// 非信用卡一律擋下，不呼叫任何 API（依藍新 PaymentType，非前端）
		if ( ! MpgPaymentType::order_is_credit( $order ) ) {
			$order->add_order_note( "⚠️ 非信用卡付款方式不支援藍新 API {$action_zh}，請至藍新金流商家後台人工處理" );
			return;
		}

		try {
			$trade_no = MpgPaymentType::get_trade_no( $order );
			$amount   = (float) $order->get_total(); // 金額來自訂單，非前端
			$client   = new DoActionClient( $order );

			'capture' === $method
			? $client->capture( $trade_no, $amount )
			: $client->cancel_auth( $trade_no, $amount );

			( new MpgMetaKeys( $order ) )->update_capture_status( $status_value );

			$order->add_order_note(
				\sprintf(
					'✅ 藍新金流信用卡%1$s成功，金額 %2$d 元，TradeNo：%3$s',
					$action_zh,
					(int) \ceil( $amount ),
					$trade_no
				)
			);
		} catch ( \Throwable $e ) {
			// do_action 內已記錄失敗 order note；此處僅記錄至 log，不外露細節
			Plugin::logger(
				"藍新 MPG {$action_zh}失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	// endregion

	// region 後台訂單操作（重新查詢付款狀態 / 對帳補單）

	/** @var string 後台訂單操作 — 重新查詢付款狀態（QueryTradeInfo） */
	public const ACTION_QUERY = 'pc_newebpay_mpg_query';

	/**
	 * 後台訂單操作清單注入「重新查詢付款狀態」（woocommerce_order_actions filter）
	 *
	 * 僅藍新 MPG 訂單顯示。供商家於 callback 漏接時手動對帳補單。
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

		$actions[ self::ACTION_QUERY ] = \__( '藍新金流重新查詢付款狀態', 'power_checkout' );
		return $actions;
	}

	/**
	 * 後台「重新查詢付款狀態」訂單操作 handler
	 *
	 * 呼叫 QueryTradeInfo → 若已付款（TradeStatus=1）且訂單尚未 processing，
	 * 以查詢結果模擬一筆 SUCCESS 通知交 StatusManager 更新（含金額防竄改）。
	 * 任何 \Throwable 一律捕捉並記錄，不外露內部錯誤。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function handle_query_action( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== self::ID ) {
			return;
		}

		try {
			$result = ( new QueryTradeClient( $order ) )->query();

			$order->add_order_note(
				\sprintf(
					'藍新金流交易查詢結果：TradeStatus=%s，PaymentType=%s，TradeNo=%s',
					(string) ( $result['TradeStatus'] ?? '' ),
					(string) ( $result['PaymentType'] ?? '' ),
					(string) ( $result['TradeNo'] ?? '' )
				)
			);

			// 已付款且尚未 processing → 以查詢結果補單（沿用 StatusManager 的金額防竄改）
			if ( QueryTradeClient::is_paid( $result ) && ! $order->has_status( \J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus::PROCESSING->value ) ) {
				$decoded = [
					'Status'  => MpgStatus::SUCCESS->value,
					'Message' => '對帳補單',
					'Result'  => \array_merge( $result, [ 'RespondCode' => MpgStatus::RESPOND_CODE_SUCCESS ] ),
				];
				( new StatusManager( $decoded, $order ) )->update_order_status();
			}
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"藍新 MPG 交易查詢失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	// endregion

	/** 初始化：註冊 callback + blocks + 後台訂單操作 */
	public static function init(): void {
		MpgCallback::instance();

		// 整合區塊結帳
		\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_checkout_blocks' ] );

		// thank-you 頁：取號繳費資訊 / 待付款「前往付款」連結（取代舊版每次進完成頁都自動導轉藍新）
		\add_action( 'woocommerce_thankyou_' . self::ID, [ __CLASS__, 'render_thankyou_payment_info' ] );

		// 後台訂單操作：重新查詢付款狀態（對帳補單）
		\add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_order_actions' ], 10, 2 );
		\add_action( 'woocommerce_order_action_' . self::ACTION_QUERY, [ __CLASS__, 'handle_query_action' ] );

		// 後台訂單操作：信用卡請款 / 取消授權（Close CloseType=1 / Cancel）
		\add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_capture_actions' ], 10, 2 );
		\add_action( 'woocommerce_order_action_' . self::ACTION_CAPTURE, [ __CLASS__, 'handle_capture_action' ] );
		\add_action( 'woocommerce_order_action_' . self::ACTION_CANCEL_AUTH, [ __CLASS__, 'handle_cancel_auth_action' ] );
	}

	/** 註冊區塊結帳支援（仿綠界 AIO） */
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
					$payment_method_registry->register( new BlocksIntegration( $gateway ) );
				}
			}
		);
	}
}
