<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniRequestParams;
use J7\PowerCheckout\Domains\Payment\Payuni\DTOs\PayuniSettingsDTO;
use J7\PowerCheckout\Domains\Payment\Payuni\Http\DoActionClient;
use J7\PowerCheckout\Domains\Payment\Payuni\Http\PayuniCallback;
use J7\PowerCheckout\Domains\Payment\Payuni\Http\QueryTradeClient;
use J7\PowerCheckout\Domains\Payment\Payuni\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Enums\PayuniPaymentMethod;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniMetaKeys;
use J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers\PayuniTradeNo;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\PowerCheckout\Domains\Payment\Shared\Helpers\BlocksIntegration;
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
 * PAYUNi 統一金流（UPP V2，整合支付頁 / 導轉式）付款閘道
 *
 * 與綠界 AIO / 藍新 MPG 同型（導轉式 + order-received 階段 auto-form POST 至第三方託管頁）：
 *  - before_process_payment 僅回傳 order-received URL（不發 PAYUNi API）；
 *    同時預寫冪等鍵 MerTradeNo（_pc_payuni_trade_no），仿綠界 AIO 的 MerchantTradeNo 預寫機制。
 *  - before_order_received 組裝 PayuniRequestParams（AES-256-GCM EncryptInfo + SHA256 HashInfo）
 *    並 render auto-form POST 至 PAYUNi UPP（/api/upp）。
 *
 * 仿 NewebpayMpg\Services\MpgRedirectGateway。退款 / callback / StatusManager 為 Phase 4-5，
 * 本階段不實作（process_refund / capture / void_auth / query_trade 暫繼承 Abstract 安全預設）。
 *
 * @see .claude/skills/payuni-upp-v2/SKILL.md
 */
final class PayuniUppGateway extends AbstractPaymentGateway implements IGateway {

	/** @var string 付款方式 ID（已拍板，不得更名） */
	public const ID = 'payuni_upp';

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示付款方式標題 */
	public $method_title = 'PAYUNi 統一金流（導轉）';

	/** @var string 後台顯示付款方式描述 */
	public $method_description = 'PAYUNi 統一金流 UPP 整合支付頁，支援信用卡、信用卡分期、ATM 虛擬帳號、超商代碼、icash Pay、LINE Pay、街口支付、Apple Pay、Google Pay 等付款方式';

	/** @var string 交易查詢 CloseStatus：未申請請款（只能取消授權） */
	private const CLOSE_STATUS_NOT_CAPTURED = '9';

	/** @var array<int, string> 交易查詢 CloseStatus：請款申請中 / 處理中（暫時不能退款也不能取消授權） */
	private const CLOSE_STATUS_IN_PROGRESS = [ '1', '7' ];

	/**
	 * 查詢 PAYUNi 請款狀態 CloseStatus（查不到 / 查詢失敗回空字串 → 沿用原本的退款流程）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private static function get_close_status( \WC_Order $order ): string {
		try {
			return (string) ( ( new QueryTradeClient( $order ) )->query()['CloseStatus'] ?? '' );
		} catch ( \Throwable ) {
			return '';
		}
	}

	/** @var array<int, bool> 本請求已處理導轉的訂單（true=已輸出表單；false=已嘗試但失敗）；同請求每個 gateway 實例都掛 wp hook，避免同一訂單產生兩個編號 */
	private static array $redirect_attempts = [];

	/**
	 * PAYUNi UPP 核心支付邏輯
	 *
	 * UPP 不在此階段發 API，僅回傳 order-received URL；實際跳轉在 before_order_received
	 * 以 auto-form submit 至 PAYUNi /api/upp。
	 *
	 * 標記「待導轉」：本方法只在顧客實際發起付款時執行（classic / block 結帳、order-pay 重新付款），
	 * order-received 頁據此以新的 MerTradeNo 導轉 PAYUNi 一次。
	 *
	 * @param \WC_Order $order 訂單
	 * @return string order-received URL
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		( new PayuniMetaKeys( $order ) )->mark_redirect_pending();

		return $this->get_return_url( $order );
	}

	/**
	 * 第三方金流頁面 render 前（order-received）
	 *
	 * 1. PAYUNi 付款後以 ReturnURL（前景 Form POST）帶加密結果導回本頁：先以 NotifyURL 相同的驗章 / 解密 /
	 *    金額防竄改流程同步訂單狀態（Notify 被防火牆擋或延遲時，顧客仍能立即看到結果）。
	 * 2. 只有顧客剛發起付款（process_payment 留下待導轉旗標），或此訂單從未送出過編號，才自動導轉 PAYUNi。
	 *    單純重訪（ATM / 超商取號後導回、付款失敗導回、重新整理、上一頁）不自動導轉——否則會用同一編號
	 *    重送（UPP01007）或替取號訂單再建一筆交易，顧客也永遠看不到繳費資訊。
	 *    thank-you 頁改由 {@see self::render_thankyou_payment_info()} 顯示繳費資訊或「前往付款」。
	 * 3. 每次導轉都用新的 MerTradeNo（PayuniTradeNo::encode_unique），舊編號保留於歷史，其通知仍能反查本訂單。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	protected function before_order_received( \WC_Order $order ): void {
		$order_id = $order->get_id();
		if ( isset( self::$redirect_attempts[ $order_id ] ) ) {
			return;
		}

		$this->sync_from_return_post( $order );
		if ( ! $order->needs_payment() ) {
			return;
		}

		try {
			$meta_keys = new PayuniMetaKeys( $order );
			if ( '' !== $meta_keys->get_trade_no() && ! $meta_keys->is_redirect_pending() ) {
				return;
			}

			// 先佔位：即使下方組參數失敗，同一請求的其他 gateway 實例也不再重試建單
			self::$redirect_attempts[ $order_id ] = false;

			$trade_no       = PayuniTradeNo::encode_unique( $order_id, $meta_keys->get_used_trade_nos() );
			$request_params = PayuniRequestParams::instance( $order, $trade_no );

			// 參數組裝成功才記錄本次編號（目前編號 + 歷史）並清除待導轉旗標
			$meta_keys->start_payment_attempt( $trade_no );

			$settings = PayuniSettingsDTO::instance();

			// render auto-form（隱藏表單自動 submit 至 PAYUNi UPP）
			Plugin::load_template(
				'auto-form',
				[
					'params' => $request_params->to_form_params(),
					'url'    => $settings->api_url,
				]
			);
			self::$redirect_attempts[ $order_id ] = true;
		} catch ( \Throwable $e ) {
			$this->logger( "❌ {$this->title} 建單時發生錯誤<br>{$e->getMessage()}", 'error', [], 5 );
		}
	}

	/**
	 * ReturnURL 前景導回同步：PAYUNi 以 Form POST 帶回加密結果時，走與 NotifyURL 相同的驗證與狀態更新
	 *
	 * 安全：HashInfo（含 HashKey 的 SHA256）驗章 + AES-256-GCM 解密 + MerID / 金額 / 冪等防線全部沿用
	 * {@see PayuniCallback::handle_notify()}；偽造的 POST 無法通過驗章。結果仍以 NotifyURL 為準，
	 * 兩條路徑誰先到誰處理，另一條被冪等擋下。
	 *
	 * 永不拋例外（thank-you 頁不得 500）。
	 *
	 * @param \WC_Order $order 訂單（同步後會刷新狀態）
	 * @return void
	 */
	private function sync_from_return_post( \WC_Order $order ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- PAYUNi 跨站 Form POST，以 HashInfo 驗章取代 nonce
		if ( ! isset( $_POST['EncryptInfo'], $_POST['HashInfo'] ) ) {
			return;
		}

		$params = [];
		foreach ( [ 'Status', 'MerID', 'Version', 'EncryptInfo', 'HashInfo' ] as $key ) {
			$params[ $key ] = isset( $_POST[ $key ] ) && \is_string( $_POST[ $key ] )
			? \sanitize_text_field( \wp_unslash( $_POST[ $key ] ) )
			: '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		try {
			PayuniCallback::instance()->handle_notify( $params );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'PAYUNi UPP ReturnURL 同步失敗',
				'warning',
				[
					'order_id' => $order->get_id(),
					'error'    => $e->getMessage(),
				]
			);
		}

		// handle_notify 以另一個訂單物件更新狀態，重新讀取本頁訂單狀態
		$fresh = \wc_get_order( $order->get_id() );
		if ( $fresh instanceof \WC_Order ) {
			$order->set_status( $fresh->get_status() );
		}
	}

	/**
	 * [thank-you 頁] 顯示 ATM / 超商取號繳費資訊，或待付款訂單的「前往付款」連結（woocommerce_thankyou_payuni_upp）
	 *
	 *  - 已取號：顯示繳費資訊（PAYUNi 導回後顧客在本站唯一看得到帳號 / 代碼的地方）。
	 *  - 尚未取號、仍待付款：單純重訪不再自動導轉（見 before_order_received），提供 order-pay 連結重新付款。
	 *  - 已付款 / 本請求已輸出自動導轉表單 → 不顯示。
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

		$payment_info = ( new PayuniMetaKeys( $order ) )->get_payment_info();
		if ( $payment_info ) {
			echo self::get_payment_info_html( $payment_info ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 內容已逐欄 escape
			return;
		}

		if ( ! $order->has_status( 'pending' ) ) {
			return; // failed 訂單 WC 模板已自帶付款按鈕
		}

		\printf(
			'<div class="woocommerce-info pc-payuni-upp-pending-payment">%1$s <a href="%2$s" class="button pay">%3$s</a></div>',
			\esc_html__( '這筆訂單還沒完成付款。要重新付款或改用其他付款方式，請按「前往付款」。', 'power_checkout' ),
			\esc_url( $order->get_checkout_payment_url() ),
			\esc_html__( '前往付款', 'power_checkout' )
		);
	}

	/**
	 * 組前台取號繳費資訊 HTML（逐欄 escape）
	 *
	 * @param array<string, mixed> $payment_info StatusManager 寫入的 _pc_payuni_payment_info
	 * @return string HTML
	 */
	private static function get_payment_info_html( array $payment_info ): string {
		$is_atm = PayuniPaymentMethod::ATM->payment_type() === (int) ( $payment_info['PaymentType'] ?? 0 );

		$code_label = $is_atm ? \__( '轉帳帳號', 'power_checkout' ) : \__( '繳費代碼', 'power_checkout' );
		$rows       = [
			\__( '付款方式', 'power_checkout' ) => $is_atm ? \__( 'ATM 轉帳', 'power_checkout' ) : \__( '超商代碼繳費', 'power_checkout' ),
			\__( '銀行代碼', 'power_checkout' ) => (string) ( $payment_info['BankType'] ?? '' ),
			\__( '繳費超商', 'power_checkout' ) => (string) ( $payment_info['Store'] ?? '' ),
			$code_label                     => (string) ( $payment_info['PayNo'] ?? '' ),
			\__( '繳費期限', 'power_checkout' ) => (string) ( $payment_info['ExpireDate'] ?? '' ),
		];

		$items = '';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$items .= \sprintf( '<li>%1$s：<strong>%2$s</strong></li>', \esc_html( (string) $label ), \esc_html( $value ) );
		}

		return \sprintf(
			'<section class="woocommerce-order-details pc-payuni-upp-payment-info"><h2 class="woocommerce-order-details__title">%1$s</h2><p>%2$s</p><ul>%3$s</ul></section>',
			\esc_html__( '繳費資訊', 'power_checkout' ),
			\esc_html__( '請在繳費期限內完成付款，付款後訂單狀態會自動更新。', 'power_checkout' ),
			$items
		);
	}

	/** [Admin] 在後台 order detail 頁地址下方顯示資訊 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( $order->get_payment_method() !== $this->id ) {
			return;
		}

		$meta_keys = new PayuniMetaKeys( $order );

		$payment_info = $meta_keys->get_payment_info();
		if ( $payment_info ) {
			echo WP::array_to_html( $payment_info, [ 'title' => 'PAYUNi 繳費資訊' ] );
		}

		$payment_detail = $meta_keys->get_payment_detail();
		if ( $payment_detail ) {
			echo WP::array_to_html( $payment_detail, [ 'title' => 'PAYUNi 付款明細' ] );
		}
	}

	/**
	 * 取得支援的付款方式清單（來自 settings allowed_payments）
	 *
	 * @return array<int|string, mixed>
	 */
	public function get_supported_payment_methods(): array {
		return PayuniSettingsDTO::instance()->allowed_payments;
	}

	// region 退款（信用卡 Close CloseType=2）

	/**
	 * 能否處理退款（admin 點按退款時觸發的前置檢查）
	 *
	 * 退款分流（資安：判定依據為 PAYUNi 回傳並存於 _pc_payuni_payment_detail 的 PaymentType，非前端）：
	 *  - 信用卡（PaymentType=1）→ 回 true，實際 Close API 退款於 handle_payment_gateway_refund。
	 *  - 其他（ATM=2 / CVS=3 / icash=6 / LINE Pay=9 / 街口=11 等）→ 回 WP_Error('refund_unsupported')，不呼叫任何退款 API。
	 *
	 * 邊緣防禦：金額 ≤ 0、超過訂單總額、無付款明細 → 回 false（不允許、不呼叫 API）。
	 *
	 * @param int        $order_id 訂單 ID
	 * @param float|null $amount   退款金額（WC 計算，僅作非空 / 範圍檢查）
	 * @param string     $reason   退款原因
	 * @return bool|\WP_Error
	 * @see WC_Payment_Gateway::process_refund
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ): bool|\WP_Error {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return false;
		}

		// 金額守衛：null / ≤0 → 維持回 false（既有 PaymentProviderContractTest 契約，不改）
		$amount = (float) $amount;
		if ( $amount <= 0 ) {
			return false;
		}

		// 有金額但超出訂單總額 → 正規化 VALIDATION（不打 API；判定邊界不變，只換回傳值建構方式）
		if ( $amount > (float) $order->get_total() ) {
			return NormalizedError::from(
				ErrorCode::VALIDATION,
				\__( '退款金額不合法（超出可退餘額）', 'power_checkout' ),
				[ 'provider' => $this->id ]
			);
		}

		// 無付款明細（callback 漏接）→ 不允許
		$detail = ( new PayuniMetaKeys( $order ) )->get_payment_detail();
		if ( ! $detail ) {
			return false;
		}

		// 信用卡才允許 API 退款；其餘擋下（依 PAYUNi PaymentType，非前端）
		if ( self::is_credit_order( $order ) ) {
			return true;
		}

		return NormalizedError::from(
			ErrorCode::UNSUPPORTED,
			\__( '此付款方式不支援 API 退款，請至 PAYUNi 商家後台人工處理', 'power_checkout' ),
			[ 'provider' => $this->id ]
		);
	}

	/**
	 * 退款 API 發送（退款創建 woocommerce_order_refunded 時觸發）
	 *
	 * 信用卡 → Close CloseType=2；非信用卡 process_refund 已擋下故不會走 API（此處雙重防禦）。
	 * wpdb 交易；失敗收尾走 fail_gateway_refund（刪 refund + 全額退款狀態回滾 + 失敗原因透出給 REST 層）。
	 * 金額一律來自 WC refund 物件，非前端。
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

		// 雙重防禦：非信用卡不發 API（process_refund 理應已擋）
		if ( ! self::is_credit_order( $order ) ) {
			$order->add_order_note( '⚠️ 此付款方式不支援 API 退款，請至 PAYUNi 商家後台人工處理' );
			return;
		}

		global $wpdb;
		try {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore

			$amount   = (float) $refund->get_amount(); // 金額一律來自 WC refund 物件，非前端
			$reason   = (string) $refund->get_reason();
			$client   = new DoActionClient( $order );
			$trade_no = (string) ( ( new PayuniMetaKeys( $order ) )->get_payment_detail()['TradeNo'] ?? '' );

			// 依請款狀態分流：PAYUNi 只有「已請款」的交易能退款（CloseType=2），未請款要改成取消授權
			$close_status = self::get_close_status( $order );
			if ( self::CLOSE_STATUS_NOT_CAPTURED === $close_status ) {
				if ( $order->get_remaining_refund_amount() > 0 ) {
					throw new \Exception( 'PAYUNi 這筆刷卡還沒請款，只能全額退款（會改成取消授權）。要部分退款，請先在訂單動作執行「PAYUNi 信用卡請款」，請款完成後再退' );
				}
				$client->cancel_auth( $trade_no );
				( new PayuniMetaKeys( $order ) )->update_capture_status( 'voided' );
				$order->add_order_note(
					\sprintf(
						'✅ PAYUNi 信用卡尚未請款，已改為取消授權（全額 %1$d 元），TradeNo：%2$s。退款原因：%3$s',
						(int) \ceil( $amount ),
						$trade_no,
						$reason
					)
				);
				$wpdb->query( 'COMMIT' ); // phpcs:ignore
				return;
			}
			if ( \in_array( $close_status, self::CLOSE_STATUS_IN_PROGRESS, true ) ) {
				throw new \Exception( 'PAYUNi 正在處理這筆刷卡的請款，請款完成後（通常隔一個工作天）再退款' );
			}

			$response = $client->refund( $order, $amount );
			$trade_no = (string) ( $response['TradeNo'] ?? $trade_no );

			$order->add_order_note(
				\sprintf(
					'✅ PAYUNi 信用卡退款成功，金額 %1$d 元，TradeNo：%2$s。退款原因：%3$s',
					(int) \ceil( $amount ),
					$trade_no,
					$reason
				)
			);

			$wpdb->query( 'COMMIT' ); // phpcs:ignore

			// 退款成功後寫入 capture_status='refunded'（僅供對帳 / 顯示；
			// WC refund 物件仍是退款冪等的真實來源，不以此 meta 做冪等判斷）。
			( new PayuniMetaKeys( $order ) )->update_capture_status( 'refunded' );
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore

			// raw_code：DoActionClient 訊息含 PAYUNi 外層「Status=XXXX」時抽出
			$raw_code = \preg_match( '/Status=([A-Za-z0-9_]+)/', $e->getMessage(), $m ) ? $m[1] : null;
			self::fail_gateway_refund( $order, $refund, 'PAYUNi', $e->getMessage(), $raw_code );
		}
	}

	// endregion

	// region 後台訂單操作（查詢補單 / 請款 / 取消授權）override Abstract 安全預設

	/** @var string 後台訂單操作 — 查詢補單（/api/trade/query） */
	public const ACTION_QUERY = 'pc_payuni_query_trade';

	/** @var string 後台訂單操作 — 請款（Close CloseType=1） */
	public const ACTION_CAPTURE = 'pc_payuni_capture';

	/** @var string 後台訂單操作 — 取消授權（Cancel） */
	public const ACTION_CANCEL_AUTH = 'pc_payuni_cancel_auth';

	/**
	 * 查詢交易（override Abstract 安全預設）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed> 查詢結果內層
	 */
	public function query_trade( \WC_Order $order ): array {
		return ( new QueryTradeClient( $order ) )->query();
	}

	/**
	 * 請款（capture，override Abstract no-op 安全預設）
	 *
	 * 信用卡 → Close CloseType=1 + 寫 capture_status='captured' + order note；
	 * 非信用卡 → no-op + note 提示不支援（依 PAYUNi PaymentType，非前端）。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function capture( \WC_Order $order ): void {
		self::do_capture_or_void( $order, 'capture' );
	}

	/**
	 * 取消授權（void authorization，override Abstract no-op 安全預設）
	 *
	 * 信用卡且未請款 → Cancel + 寫 capture_status='voided' + order note；
	 * 非信用卡 → no-op + note 提示不支援。
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function void_auth( \WC_Order $order ): void {
		self::do_capture_or_void( $order, 'cancel_auth' );
	}

	/**
	 * 後台訂單操作清單注入查詢補單 / 請款 / 取消授權（woocommerce_order_actions filter）
	 *
	 * - 查詢補單：所有 PAYUNi 訂單皆可（對帳補單）。
	 * - 請款 / 取消授權：僅信用卡 PAYUNi 訂單（Close / Cancel 僅信用卡，依 PAYUNi PaymentType，非前端）。
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

		// 查詢補單：所有 PAYUNi 訂單皆顯示
		$actions[ self::ACTION_QUERY ] = \__( 'PAYUNi 重新查詢付款狀態（補單）', 'power_checkout' );

		// 請款 / 取消授權：僅信用卡顯示
		if ( self::is_credit_order( $order ) ) {
			$actions[ self::ACTION_CAPTURE ]     = \__( 'PAYUNi 信用卡請款（關帳）', 'power_checkout' );
			$actions[ self::ACTION_CANCEL_AUTH ] = \__( 'PAYUNi 信用卡取消授權', 'power_checkout' );
		}

		return $actions;
	}

	/**
	 * 後台「查詢補單」訂單操作 handler
	 *
	 * 呼叫 /api/trade/query → 若已付款（TradeStatus=1 且 DataSource=A）且訂單尚未 processing，
	 * 以查詢結果交 StatusManager 補單（含金額防竄改 / MerID 比對）。
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
					'PAYUNi 交易查詢結果：TradeStatus=%s，PaymentType=%s，TradeNo=%s，DataSource=%s',
					(string) ( $result['TradeStatus'] ?? '' ),
					(string) ( $result['PaymentType'] ?? '' ),
					(string) ( $result['TradeNo'] ?? '' ),
					(string) ( $result['DataSource'] ?? '' )
				)
			);

			$is_paid      = QueryTradeClient::is_paid( (string) ( $result['TradeStatus'] ?? '' ) );
			$is_complete  = 'A' === (string) ( $result['DataSource'] ?? '' ); // DataSource=B 為處理中，不補單
			$not_finished = ! $order->has_status( OrderStatus::PROCESSING->value );

			// 已付款 + 完整資料 + 尚未 processing → 交 StatusManager 補單（沿用金額防竄改 / MerID 防線）
			if ( $is_paid && $is_complete && $not_finished ) {
				( new StatusManager( $result, $order ) )->update_order_status();
			}
		} catch ( \Throwable $e ) {
			Plugin::logger(
				"PAYUNi 交易查詢失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
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
	 * 非信用卡一律擋下，不呼叫任何 API（依 PAYUNi PaymentType，非前端）。
	 * 狀態機前置守衛：已取消授權（voided）不可再請款、已請款（captured）不可再取消授權，
	 * 避免本地 capture_status 被覆寫而與 PAYUNi 實際狀態脫節（資安完整性）。
	 * 任何 \Throwable 捕捉並記錄失敗 order note，不外露內部細節。
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

		// 非信用卡一律擋下（Close / Cancel 僅信用卡）
		if ( ! self::is_credit_order( $order ) ) {
			$order->add_order_note( "⚠️ 非信用卡付款方式不支援 PAYUNi API {$action_zh}，請至 PAYUNi 商家後台人工處理" );
			return;
		}

		// 狀態機前置守衛（讀現有 _pc_payuni_capture_status，避免覆寫與 PAYUNi 脫節）：
		// 已取消授權（voided）→ 不可請款；已請款（captured）→ 不可取消授權。
		$current_status = ( new PayuniMetaKeys( $order ) )->get_capture_status();
		if ( 'capture' === $method && 'voided' === $current_status ) {
			$order->add_order_note( '⚠️ 此訂單已取消授權，無法請款' );
			return;
		}
		if ( 'cancel_auth' === $method && 'captured' === $current_status ) {
			$order->add_order_note( '⚠️ 此訂單已請款，無法取消授權' );
			return;
		}

		try {
			$detail   = ( new PayuniMetaKeys( $order ) )->get_payment_detail();
			$trade_no = (string) ( $detail['TradeNo'] ?? '' );
			$client   = new DoActionClient( $order );

			if ( 'capture' === $method ) {
				$client->capture( $trade_no, (float) $order->get_total() ); // 金額來自訂單，非前端
			} else {
				$client->cancel_auth( $trade_no );
			}

			( new PayuniMetaKeys( $order ) )->update_capture_status( $status_value );

			$order->add_order_note(
				\sprintf(
					'✅ PAYUNi 信用卡%1$s成功，TradeNo：%2$s',
					$action_zh,
					$trade_no
				)
			);
		} catch ( \Throwable $e ) {
			$order->add_order_note( "❌ PAYUNi 信用卡{$action_zh}失敗：{$e->getMessage()}" );
			Plugin::logger(
				"PAYUNi {$action_zh}失敗 #{$order->get_id()}",
				'error',
				[ 'error' => $e->getMessage() ]
			);
		}
	}

	/**
	 * 判定訂單是否為信用卡付款（依 PAYUNi 回傳並存於 payment_detail 的 PaymentType=1）
	 *
	 * 資安：判定依據為 PAYUNi 回傳資料（NotifyURL / 查詢），非前端輸入。
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	private static function is_credit_order( \WC_Order $order ): bool {
		$detail = ( new PayuniMetaKeys( $order ) )->get_payment_detail();
		return 1 === (int) ( $detail['PaymentType'] ?? 0 );
	}

	// endregion

	// region 設定

	/**
	 * @param bool $with_default 是否含預設值，還是只拿 DB 值
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		if ( ! $with_default ) {
			$default_array = ( new PayuniSettingsDTO() )->to_array();
			$unset_keys    = [ 'merchant_id', 'hash_key', 'hash_iv', 'api_url' ];
			foreach ( $unset_keys as $key ) {
				unset( $default_array[ $key ] );
			}

			$option = ProviderUtils::get_option( self::ID );
			return \wp_parse_args( \is_array( $option ) ? $option : [], $default_array );
		}
		return PayuniSettingsDTO::instance()->to_array();
	}

	/**
	 * [後台] 自訂欄位驗證邏輯（min/max 金額）
	 *
	 * @return bool was anything saved?
	 * @see WC_Settings_API::process_admin_options
	 */
	public function process_admin_options(): bool {
		$min_amount_name = $this->get_field_key( 'min_amount' );
		$max_amount_name = $this->get_field_key( 'max_amount' );

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

	/** 初始化：註冊 NotifyURL 幕後通知 callback + blocks 結帳支援 + 後台訂單操作 */
	public static function init(): void {
		// 註冊 PAYUNi UPP NotifyURL 幕後通知 callback（power-checkout/payuni/upp/notify）
		PayuniCallback::instance();

		// 整合區塊結帳
		\add_action( 'woocommerce_blocks_loaded', [ __CLASS__, 'register_checkout_blocks' ] );

		// thank-you 頁：取號繳費資訊 / 待付款「前往付款」
		\add_action( 'woocommerce_thankyou_' . self::ID, [ __CLASS__, 'render_thankyou_payment_info' ] );

		// 後台訂單操作：查詢補單（對帳）/ 信用卡請款（Close CloseType=1）/ 信用卡取消授權（Cancel）
		\add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_order_actions' ], 10, 2 );
		\add_action( 'woocommerce_order_action_' . self::ACTION_QUERY, [ __CLASS__, 'handle_query_action' ] );
		\add_action( 'woocommerce_order_action_' . self::ACTION_CAPTURE, [ __CLASS__, 'handle_capture_action' ] );
		\add_action( 'woocommerce_order_action_' . self::ACTION_CANCEL_AUTH, [ __CLASS__, 'handle_cancel_auth_action' ] );
	}

	/** 註冊區塊結帳支援（仿藍新 MPG / 綠界 AIO） */
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

	/**
	 * Provider meta（能力為方法層級宣告，見 PaymentCapabilitiesDTO）
	 *
	 * PAYUNi UPP：auto-submit 表單導轉（form-redirect）；覆寫 query_trade / capture / void_auth（同時提供 admin action）。
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
				capture: true,
				void: true,
				query: true,
				recurring: false,
			),
		);
	}
}
