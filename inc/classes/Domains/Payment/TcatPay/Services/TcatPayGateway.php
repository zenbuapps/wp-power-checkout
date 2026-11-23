<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\Services;

use Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry;
use J7\PowerCheckout\Domains\Payment\Shared\Abstracts\AbstractPaymentGateway;
use J7\PowerCheckout\Domains\Payment\Shared\DTOs\PaymentCapabilitiesDTO;
use J7\PowerCheckout\Domains\Payment\Shared\DTOs\ProviderMetaDTO;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderCreationStrategy;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\PaymentMode;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\WebhookOutcome;
use J7\PowerCheckout\Domains\Payment\Shared\Interfaces\IGateway;
use J7\PowerCheckout\Domains\Payment\Shared\Utils\GatewayUtils;
use J7\PowerCheckout\Domains\Payment\TcatPay\DTOs\CocsOrderAppendParams;
use J7\PowerCheckout\Domains\Payment\TcatPay\DTOs\CvsOrderAppendParams;
use J7\PowerCheckout\Domains\Payment\TcatPay\DTOs\TcatPaySettingsDTO;
use J7\PowerCheckout\Domains\Payment\TcatPay\Http\TcatPayApiClient;
use J7\PowerCheckout\Domains\Payment\TcatPay\Http\TcatPayCallback;
use J7\PowerCheckout\Domains\Payment\TcatPay\Managers\StatusManager;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Enums\TcatPayApnStatus;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Enums\TcatPayMethod;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Exceptions\TcatPayApiException;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Exceptions\TcatPayRetryLaterException;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayAmountLimits;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayBlocksIntegration;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayChecksum;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayMetaKeys;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\TcatPayTradeNo;
use J7\PowerCheckout\Plugin;
use J7\PowerCheckout\Shared\Errors\ErrorCode;
use J7\PowerCheckout\Shared\Errors\NormalizedError;
use J7\WpUtils\Classes\WP;

/**
 * 黑貓PAY（統一客樂得 Kolet）付款閘道 `tcat_pay`
 *
 * 子方式（結帳欄位 pc_tcat_pay_method）：
 *  - credit：CocsOrderAppend → 平台回刷卡短網址 → 直接 redirect（api-redirect）
 *  - cvs_ibon / atm：CvsOrderAppend → 取號寫 payment_info → order-received 顯示代碼，訂單保持 pending
 *
 * 付款結果三條路，全部以「平台查詢」為唯一事實來源（R1）：
 *  1. APN（背景，POST JSON）：checksum 無金鑰可偽造 → 只當觸發，驗 checksum 後 CocsOrderQuery / CvsOrderQuery 確認；
 *     10 秒節流；平台查詢失敗 / 節流中 / 已收款觸發但平台尚未確認 → 回 503 RETRY 讓平台 15 分鐘後重送（R2）
 *  2. COCS 導轉回報（前景，GET + chk）：驗 chk（hash_base）+ 編號屬於本訂單 → 平台查詢確認
 *  3. 後台「查詢補單」order action
 *
 * 不做續期收款（ASM-5）。退款依平台 process_code 分流：已請款 CocsOrderRefund（可部分）、
 * 未請款 CocsOrderCancel（僅全額）；CVS / ATM 無退款 API。
 *
 * @see .claude/skills/tcat-pay/SKILL.md
 * @see zenbu payments/kolet/kolet.service.ts
 */
final class TcatPayGateway extends AbstractPaymentGateway implements IGateway {

	/** @var string 付款方式 ID */
	public const ID = 'tcat_pay';

	/** @var string 結帳子方式欄位名（classic POST key / block paymentMethodData key） */
	public const FIELD_NAME = 'pc_tcat_pay_method';

	/** @var string 後台訂單操作：查詢補單 */
	public const ACTION_QUERY = 'pc_tcat_pay_query_trade';

	/** @var string APN 節流 transient 前綴 */
	public const APN_THROTTLE_PREFIX = 'pc_tcat_pay_apn_';

	/** @var int APN 平台查詢節流秒數 */
	private const APN_THROTTLE_SECONDS = 10;

	/** @var string 付款方式 ID */
	public $id = self::ID;

	/** @var string 後台顯示標題 */
	public $method_title = '黑貓PAY（統一客樂得）';

	/** @var string 後台顯示描述 */
	public $method_description = '黑貓PAY 線上刷卡（導向刷卡頁）、ibon 超商代碼與 ATM 虛擬帳號繳費';

	/** @var bool 結帳有子方式欄位 */
	public $has_fields = true;

	// region 設定 / 可用性

	/**
	 * @param bool $with_default 是否含預設值（本 DTO 一律合併預設）
	 * @return array<string, mixed> 取得設定
	 */
	public static function get_settings( bool $with_default = true ): array {
		return TcatPaySettingsDTO::instance()->to_array();
	}

	/**
	 * 是否可用：父類（啟用 + 最小 / 最大金額）+ TWD + 憑證齊全 + 至少一種子方式在金額內
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		if ( ! parent::is_available() ) {
			return false;
		}
		if ( \is_admin() ) {
			return true;
		}
		return $this->is_available_for_total( (float) $this->get_order_total() );
	}

	/**
	 * 指定金額下是否可用（不含 WC 啟用狀態）
	 *
	 * @param float $total 金額
	 * @return bool
	 */
	public function is_available_for_total( float $total ): bool {
		$settings = TcatPaySettingsDTO::instance();
		return 'TWD' === \get_woocommerce_currency()
		&& $settings->has_credentials()
		&& [] !== TcatPayAmountLimits::available_methods( $settings, $total );
	}

	/**
	 * @return array<int|string, mixed> 啟用的子方式
	 */
	public function get_supported_payment_methods(): array {
		return TcatPaySettingsDTO::instance()->enabled_methods;
	}

	// endregion

	// region 結帳子方式欄位

	/** Classic checkout 付款欄位：描述 + 子方式 radio @return void */
	public function payment_fields(): void {
		parent::payment_fields();
		echo self::render_method_field( TcatPaySettingsDTO::instance(), (float) $this->get_order_total() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 內部已逐項 escape
	}

	/**
	 * 子方式 radio HTML（只列啟用且金額內的子方式）
	 *
	 * @param TcatPaySettingsDTO $settings 設定
	 * @param float              $total    金額
	 * @return string
	 */
	public static function render_method_field( TcatPaySettingsDTO $settings, float $total ): string {
		$methods = TcatPayAmountLimits::available_methods( $settings, $total );
		if ( [] === $methods ) {
			return '';
		}

		$field   = \esc_attr( self::FIELD_NAME );
		$options = '';
		foreach ( $methods as $index => $method ) {
			$value    = \esc_attr( $method->value );
			$label    = \esc_html( $method->label() );
			$checked  = 0 === $index ? ' checked="checked"' : '';
			$options .= <<<HTML
			<li><label><input type="radio" class="input-radio" name="{$field}" value="{$value}"{$checked} /> {$label}</label></li>
			HTML;
		}

		return <<<HTML
		<ul class="pc-tcat-pay-methods" style="list-style:none;margin:8px 0 0;padding:0;">{$options}</ul>
		HTML;
	}

	/**
	 * Block checkout 子方式資料
	 *
	 * @return array{field: string, options: array<int, array{value: string, label: string, max_amount: int}>}
	 */
	public static function get_block_method_data(): array {
		$settings = TcatPaySettingsDTO::instance();
		$options  = [];
		foreach ( TcatPayMethod::cases() as $method ) {
			if ( \in_array( $method->value, $settings->enabled_methods, true ) ) {
				$options[] = [
					'value'      => $method->value,
					'label'      => $method->label(),
					'max_amount' => TcatPayAmountLimits::max_for( $method, $settings ),
				];
			}
		}
		return [
			'field'   => self::FIELD_NAME,
			'options' => $options,
		];
	}

	/**
	 * 結帳欄位驗證（classic / Store API / order-pay 皆在 process_payment 前呼叫）
	 *
	 * @return bool
	 */
	public function validate_fields(): bool {
		try {
			self::resolve_posted_method( TcatPaySettingsDTO::instance() );
			return true;
		} catch ( \Throwable $e ) {
			\wc_add_notice( \__( '所選的黑貓PAY 付款方式無法使用，請重新選擇', 'power_checkout' ), 'error' );
			return false;
		}
	}

	/**
	 * 讀取並驗證送出的子方式（未送出 → 第一個啟用的子方式）
	 *
	 * @param TcatPaySettingsDTO $settings 設定
	 * @return TcatPayMethod
	 * @throws \InvalidArgumentException 不合法或未啟用
	 */
	private static function resolve_posted_method( TcatPaySettingsDTO $settings ): TcatPayMethod {
		// nonce 已由 WC checkout / Store API / order-pay 驗證；只讀單一欄位並以白名單比對
		$raw = $_POST[ self::FIELD_NAME ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( null === $raw || '' === $raw ) {
			$first = $settings->enabled_methods[0] ?? '';
			return TcatPayMethod::from( $first );
		}
		if ( ! \is_string( $raw ) ) {
			throw new \InvalidArgumentException( '黑貓PAY 子方式格式錯誤' );
		}

		$method = TcatPayMethod::tryFrom( \sanitize_text_field( \wp_unslash( $raw ) ) );
		if ( null === $method || ! \in_array( $method->value, $settings->enabled_methods, true ) ) {
			throw new \InvalidArgumentException( '黑貓PAY 子方式未啟用' );
		}
		return $method;
	}

	// endregion

	// region 付款

	/**
	 * Before process payment：建立平台訂單：credit 回刷卡短網址；cvs_ibon / atm 取號後回 order-received
	 *
	 * @param \WC_Order $order 訂單
	 * @return string redirect URL
	 * @throws \InvalidArgumentException 子方式不合法 / 超過上限（由 process_payment 統一處理）
	 * @throws TcatPayApiException 平台失敗（由 process_payment 統一處理）
	 */
	protected function before_process_payment( \WC_Order $order ): string {
		$settings = TcatPaySettingsDTO::instance();
		$method   = self::resolve_posted_method( $settings );
		$amount   = (int) \round( (float) $order->get_total() );

		if ( ! TcatPayAmountLimits::is_within( $method, $amount, $settings ) ) {
			throw new \InvalidArgumentException(
				\sprintf(
					/* translators: 1: 子方式 2: 上限 */
					\__( '%1$s 單筆上限為 %2$d 元', 'power_checkout' ),
					$method->label(),
					TcatPayAmountLimits::max_for( $method, $settings )
				)
			);
		}

		$trade_no = TcatPayTradeNo::generate( $order->get_id() );
		$meta     = new TcatPayMetaKeys( $order );
		$meta->update_method( $method->value );
		$meta->start_attempt( $trade_no, $amount );

		$client = new TcatPayApiClient( $settings );

		if ( TcatPayMethod::CREDIT === $method ) {
			$params = CocsOrderAppendParams::from_order( $order, $settings, $trade_no, $amount, $this->get_return_url( $order ), TcatPayCallback::get_apn_url() );
			$res    = $client->collect( TcatPayApiClient::CMD_COCS_ORDER_APPEND, $params->to_array() );
			$url    = \is_string( $res['url'] ?? null ) ? \trim( $res['url'] ) : '';
			if ( '' === $url ) {
				throw new TcatPayApiException( '黑貓PAY 未回傳刷卡網址' );
			}
			$meta->update_payment_url( $url );
			$order->add_order_note( "黑貓PAY 已建立刷卡訂單，交易編號 {$trade_no}" );
			return $url;
		}

		$params = CvsOrderAppendParams::from_order( $order, $settings, $method, $trade_no, $amount, TcatPayCallback::get_apn_url() );
		$res    = $client->collect( TcatPayApiClient::CMD_CVS_ORDER_APPEND, $params->to_array() );
		$meta->update_payment_info( self::extract_payment_info( $res ) );
		$order->add_order_note( "黑貓PAY 已取得{$method->label()}繳費資訊，交易編號 {$trade_no}" );

		return $this->get_return_url( $order );
	}

	/**
	 * 取號回應 → 要保存 / 顯示的欄位
	 *
	 * @param array<string, mixed> $res CvsOrderAppend 回應
	 * @return array<string, mixed>
	 */
	private static function extract_payment_info( array $res ): array {
		$keys = [ 'cust_order_no', 'order_amount', 'bill_amount', 'cs_fee', 'expire_date', 'ibon_code', 'ibon_shopid', 'virtual_account', 'bank_id', 'st_barcode1', 'st_barcode2', 'st_barcode3', 'short_url' ];
		$info = [];
		foreach ( $keys as $key ) {
			if ( isset( $res[ $key ] ) && \is_scalar( $res[ $key ] ) && '' !== (string) $res[ $key ] ) {
				$info[ $key ] = $res[ $key ];
			}
		}
		return $info;
	}

	/**
	 * Order-received 頁（WC 已驗 order key + needs_payment）：處理 COCS 導轉回報
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	protected function before_order_received( \WC_Order $order ): void {
		$query = [];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 第三方導轉回報，以 chk 驗章 + 平台查詢確認
		foreach ( $_GET as $key => $value ) {
			if ( \is_string( $key ) && \is_string( $value ) ) {
				$query[ $key ] = \sanitize_text_field( \wp_unslash( $value ) );
			}
		}
		$this->handle_return( $order, $query );
	}

	/**
	 * COCS 導轉回報：驗 chk → 編號屬於本訂單 → 平台查詢確認 → StatusManager（never-throw）
	 *
	 * @param \WC_Order            $order 訂單
	 * @param array<string, mixed> $query 導轉 query
	 * @return void
	 */
	public function handle_return( \WC_Order $order, array $query ): void {
		if ( ! isset( $query['chk'], $query['ret'], $query['cust_order_no'] ) ) {
			return;
		}

		try {
			$trade_no = \is_scalar( $query['cust_order_no'] ) ? (string) $query['cust_order_no'] : '';
			if ( ! ( new TcatPayMetaKeys( $order ) )->owns_trade_no( $trade_no ) ) {
				Plugin::logger( '黑貓PAY 導轉回報：交易編號不屬於此訂單，略過', 'warning', [ 'order_id' => $order->get_id() ] );
				return;
			}
			if ( ! TcatPayChecksum::verify_return( TcatPaySettingsDTO::instance()->hash_base, $query ) ) {
				Plugin::logger( '黑貓PAY 導轉回報 chk 驗章失敗，略過', 'warning', [ 'order_id' => $order->get_id() ] );
				return;
			}

			if ( 'OK' !== $query['ret'] ) {
				$order->add_order_note( "黑貓PAY 導轉回報：信用卡授權失敗（交易編號 {$trade_no}），顧客可重新付款" );
				return;
			}

			$this->settle_from_platform( $order, $trade_no, "{$trade_no}:RETURN", null, \__( '導轉回報', 'power_checkout' ) );
		} catch ( \Throwable $e ) {
			Plugin::logger(
				'黑貓PAY 導轉回報處理失敗',
				'error',
				[
					'order_id' => $order->get_id(),
					'error'    => $e->getMessage(),
				]
				);
		}
	}

	/**
	 * 向平台查詢並在確認已收款時認列
	 *
	 * @param \WC_Order $order           訂單
	 * @param string    $trade_no        交易編號
	 * @param string    $processed_key   冪等鍵
	 * @param mixed     $notified_amount 通知金額
	 * @param string    $source          來源
	 * @return bool 平台是否確認已收款
	 * @throws TcatPayApiException 平台查詢失敗
	 */
	private function settle_from_platform( \WC_Order $order, string $trade_no, string $processed_key, mixed $notified_amount, string $source ): bool {
		$is_cvs = self::is_cvs_order( $order );
		$query  = $this->query_by_trade_no( $trade_no, $is_cvs );

		if ( ! StatusManager::is_platform_paid( $query, $is_cvs ) ) {
			Plugin::logger(
				"黑貓PAY {$source}：平台查詢未確認已付款，不入帳",
				'warning',
				[
					'order_id'     => $order->get_id(),
					'trade_no'     => $trade_no,
					'process_code' => $query['process_code'] ?? null,
				]
			);
			return false;
		}

		( new StatusManager( $order ) )->settle_paid( $query, $trade_no, $processed_key, $notified_amount, $source );
		return true;
	}

	/**
	 * Thank-you 頁：CVS / ATM 顯示繳費資訊；刷卡未完成顯示重新付款連結
	 *
	 * @param int $order_id 訂單 ID
	 * @return void
	 */
	public static function render_thankyou( int $order_id ): void {
		$order = \wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || self::ID !== $order->get_payment_method() || $order->is_paid() ) {
			return;
		}

		$meta = new TcatPayMetaKeys( $order );
		if ( ! self::is_cvs_order( $order ) ) {
			$url   = \esc_url( $order->get_checkout_payment_url() );
			$title = \esc_html__( '尚未完成信用卡付款', 'power_checkout' );
			$link  = \esc_html__( '前往付款', 'power_checkout' );
			$html = <<<HTML
			<section class="pc-tcat-pay-pending"><p>{$title}</p><p><a class="button" href="{$url}">{$link}</a></p></section>
			HTML;
			echo \wp_kses_post( $html );
			return;
		}

		$info   = $meta->get_payment_info();
		$labels = [
			'ibon_code'       => \__( 'ibon 繳費代碼', 'power_checkout' ),
			'virtual_account' => \__( 'ATM 虛擬帳號', 'power_checkout' ),
			'bank_id'         => \__( '銀行代碼', 'power_checkout' ),
			'bill_amount'     => \__( '繳費金額', 'power_checkout' ),
			'expire_date'     => \__( '繳費期限', 'power_checkout' ),
		];
		$rows   = '';
		foreach ( $labels as $key => $label ) {
			if ( isset( $info[ $key ] ) && \is_scalar( $info[ $key ] ) && '' !== (string) $info[ $key ] ) {
				$label_html = \esc_html( $label );
				$value_html = \esc_html( (string) $info[ $key ] );
				$rows      .= "<tr><th>{$label_html}</th><td><strong>{$value_html}</strong></td></tr>";
			}
		}
		if ( '' === $rows ) {
			return;
		}

		$title = \esc_html__( '黑貓PAY 繳費資訊', 'power_checkout' );
		$html = <<<HTML
		<section class="pc-tcat-pay-payment-info"><h2>{$title}</h2><table class="shop_table">{$rows}</table></section>
		HTML;
		echo \wp_kses_post( $html );
	}

	/**
	 * [Admin] 訂單頁顯示繳費資訊 / 付款明細
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function render_after_billing_address( \WC_Order $order ): void {
		if ( self::ID !== $order->get_payment_method() ) {
			return;
		}
		$meta = new TcatPayMetaKeys( $order );
		if ( $meta->get_payment_info() ) {
			echo WP::array_to_html( $meta->get_payment_info(), [ 'title' => '黑貓PAY 繳費資訊' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		if ( $meta->get_payment_detail() ) {
			echo WP::array_to_html( $meta->get_payment_detail(), [ 'title' => '黑貓PAY 付款明細' ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	// endregion

	// region APN（統一 webhook 外框掛鉤）

	/**
	 * APN checksum 驗章（無金鑰，只證明欄位自洽；入帳另以平台查詢確認）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return bool
	 */
	public function verify_callback( \WP_REST_Request $request ): bool {
		$payload = self::decode_payload( $request );
		return null !== $payload && TcatPayChecksum::verify_apn( $payload );
	}

	/**
	 * 以 order_no（cust_order_no，含歷史）找單
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return \WC_Order|null
	 */
	protected function resolve_callback_order( \WP_REST_Request $request ): ?\WC_Order {
		$payload  = self::decode_payload( $request ) ?? [];
		$trade_no = \is_scalar( $payload['order_no'] ?? null ) ? (string) $payload['order_no'] : '';
		return TcatPayMetaKeys::get_order_by_trade_no( $trade_no );
	}

	/**
	 * 外框冪等鍵停用（回空字串）
	 *
	 * 冪等改由 StatusManager 的 `_pc_tcat_pay_processed_status` 只在「確實處理」時寫入：
	 * 若用外框鍵，checksum 可偽造的 APN 先到（平台查詢未付）就會被記為已處理，真通知之後反被當成重送丟棄。
	 *
	 * @param \WP_REST_Request $request 請求
	 * @param \WC_Order        $order   訂單
	 * @return string
	 */
	protected function get_callback_idempotency_key( \WP_REST_Request $request, \WC_Order $order ): string {
		return '';
	}

	/**
	 * APN 業務處理
	 *
	 * @param \WP_REST_Request $request 請求
	 * @param \WC_Order        $order   訂單
	 * @return void
	 * @throws TcatPayRetryLaterException 節流中 / 已收款觸發但平台尚未確認，要求平台重送（平台查詢失敗的 TcatPayApiException 亦同樣向上拋）
	 */
	protected function process_callback( \WP_REST_Request $request, \WC_Order $order ): void {
		$payload  = self::decode_payload( $request ) ?? [];
		$trade_no = (string) $payload['order_no'];
		$status   = (string) $payload['status'];
		$is_cvs   = self::is_cvs_order( $order );
		$key      = "{$trade_no}:{$status}";
		$meta     = new TcatPayMetaKeys( $order );

		if ( $meta->is_processed( $key ) ) {
			return;
		}

		$enum = TcatPayApnStatus::tryFrom( $status );
		if ( null === $enum || ! $enum->is_paid_trigger( $is_cvs ) ) {
			( new StatusManager( $order ) )->record_status_note( $trade_no, $status, $is_cvs );
			return;
		}

		if ( $order->is_paid() ) {
			$meta->add_processed_status( $key );
			return;
		}

		$throttle = self::APN_THROTTLE_PREFIX . $order->get_id();
		if ( false !== \get_transient( $throttle ) ) {
			throw new TcatPayRetryLaterException( '黑貓PAY APN 平台查詢節流中' );
		}
		\set_transient( $throttle, 1, self::APN_THROTTLE_SECONDS );

		$detail   = \is_array( $payload['payment_detail'] ?? null ) ? $payload['payment_detail'] : [];
		$notified = $is_cvs ? ( $payload['pay_amount'] ?? null ) : ( $detail['pay_amount'] ?? null );
		if ( ! $this->settle_from_platform( $order, $trade_no, $key, $notified, \__( 'APN 主動通知', 'power_checkout' ) ) ) {
			// 已收款觸發但平台尚未確認：回 RETRY 讓平台約 15 分鐘後重送（同狀態只重送約 3 次，回 OK 就不會再來）。
			// 冪等鍵不寫（偽造 APN 只會換來一次 RETRY，無法預先污染）；之後仍有 TcatPayReconcileJob 主動補單。
			throw new TcatPayRetryLaterException( '黑貓PAY APN：平台尚未確認已收款，要求平台稍後重送' );
		}
	}

	/**
	 * Ack：純文字 OK（平台停止重送）；處理失敗（平台查詢逾時 / 節流）回 503 RETRY 讓平台重送（R2）
	 *
	 * @param \WP_REST_Request $request 請求
	 * @param WebhookOutcome   $outcome 結果
	 * @return \WP_REST_Response
	 */
	protected function callback_ack_response( \WP_REST_Request $request, WebhookOutcome $outcome ): \WP_REST_Response {
		$retry    = WebhookOutcome::HANDLER_ERROR === $outcome;
		$response = new \WP_REST_Response( $retry ? 'RETRY' : 'OK', $retry ? 503 : 200 );
		$response->header( 'Content-Type', 'text/plain; charset=utf-8' );
		return $response;
	}

	/**
	 * 解析 APN JSON body
	 *
	 * @param \WP_REST_Request $request 請求
	 * @return array<string, mixed>|null
	 */
	private static function decode_payload( \WP_REST_Request $request ): ?array {
		$payload = \json_decode( (string) $request->get_body(), true );
		/** @var array<string, mixed>|null $result */
		$result = \is_array( $payload ) ? $payload : null;
		return $result;
	}

	// endregion

	// region 退款 / 請款 / 取消授權 / 查詢

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
		if ( self::is_cvs_order( $order ) ) {
			return NormalizedError::from(
				ErrorCode::UNSUPPORTED,
				\__( '黑貓PAY 超商代碼 / ATM 繳費沒有線上退款 API，請至黑貓PAY 後台或以匯款人工處理', 'power_checkout' ),
				[ 'provider' => $this->id ]
			);
		}
		return true;
	}

	/**
	 * 退款 API（woocommerce_order_refunded）：依平台 process_code 分流取消交易 / 取消授權
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

		if ( self::is_cvs_order( $order ) ) {
			$order->add_order_note( '⚠️ 黑貓PAY 超商代碼 / ATM 繳費不支援 API 退款，請人工處理' );
			return;
		}

		try {
			$amount = (int) \round( (float) $refund->get_amount() );
			$result = $this->refund_cocs( $order, $amount );
			$order->add_order_note( "✅ 黑貓PAY {$result}已受理，金額 {$amount} 元。原因：{$refund->get_reason()}" );
		} catch ( TcatPayApiException $e ) {
			self::fail_gateway_refund( $order, $refund, '黑貓PAY', $e->getMessage(), null, $e->error_code );
		} catch ( \Throwable $e ) {
			self::fail_gateway_refund( $order, $refund, '黑貓PAY', $e->getMessage() );
		}
	}

	/**
	 * COCS 退款分流（「請款了沒」是平台端事實，必須先查）
	 *
	 * @param \WC_Order $order  訂單
	 * @param int       $amount 退款金額
	 * @return string 執行的動作名稱
	 * @throws TcatPayApiException 平台失敗或狀態不允許
	 */
	private function refund_cocs( \WC_Order $order, int $amount ): string {
		$settings     = TcatPaySettingsDTO::instance();
		$client       = new TcatPayApiClient( $settings );
		$meta         = new TcatPayMetaKeys( $order );
		$trade_no     = $meta->get_trade_no();
		$order_amount = $meta->get_charge_amount();

		$query = $client->collect( TcatPayApiClient::CMD_COCS_ORDER_QUERY, [ 'cust_order_no' => $trade_no ] );
		$code  = \is_numeric( $query['process_code'] ?? null ) ? (int) $query['process_code'] : -1;

		if ( $code >= 20 ) {
			$client->collect(
				TcatPayApiClient::CMD_COCS_ORDER_REFUND,
				[
					'cust_order_no' => $trade_no,
					'order_amount'  => $order_amount,
					'refund_amount' => $amount,
					'acquirer_type' => $settings->acquirer_type,
					'send_time'     => self::now(),
				]
			);
			return '取消交易（退款）';
		}

		if ( 15 !== $code ) {
			throw new TcatPayApiException( "黑貓PAY 訂單目前狀態（process_code={$code}）不允許線上退款，請人工處理", ErrorCode::UNSUPPORTED );
		}
		if ( $amount < $order_amount ) {
			throw new TcatPayApiException( '黑貓PAY 尚未請款的交易僅支援全額取消授權，部分退款請待請款完成後再退或人工處理', ErrorCode::UNSUPPORTED );
		}

		$client->collect(
			TcatPayApiClient::CMD_COCS_ORDER_CANCEL,
			[
				'cust_order_no' => $trade_no,
				'order_amount'  => $order_amount,
				'acquirer_type' => $settings->acquirer_type,
				'send_time'     => self::now(),
			]
		);
		return '取消授權';
	}

	/**
	 * 請款（CocsCashRequest 全額）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function capture( \WC_Order $order ): void {
		$this->run_credit_action(
			$order,
			'請款',
			static fn( TcatPayApiClient $client, TcatPayMetaKeys $meta, TcatPaySettingsDTO $settings ): array => $client->collect(
				TcatPayApiClient::CMD_COCS_CASH_REQUEST,
				[
					'cust_order_no' => $meta->get_trade_no(),
					'order_amount'  => $meta->get_charge_amount(),
					'cr_amount'     => $meta->get_charge_amount(),
					'send_time'     => self::now(),
				]
			)
		);
	}

	/**
	 * 取消授權（CocsOrderCancel，請款前、僅全額）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public function void_auth( \WC_Order $order ): void {
		$this->run_credit_action(
			$order,
			'取消授權',
			static fn( TcatPayApiClient $client, TcatPayMetaKeys $meta, TcatPaySettingsDTO $settings ): array => $client->collect(
				TcatPayApiClient::CMD_COCS_ORDER_CANCEL,
				[
					'cust_order_no' => $meta->get_trade_no(),
					'order_amount'  => $meta->get_charge_amount(),
					'acquirer_type' => $settings->acquirer_type,
					'send_time'     => self::now(),
				]
			)
		);
	}

	/**
	 * 信用卡專屬操作共用流程（非信用卡留 note 不打 API；失敗留 note 不拋）
	 *
	 * @param \WC_Order $order  訂單
	 * @param string    $label  動作名稱
	 * @param \Closure  $action 動作
	 * @phpstan-param \Closure(TcatPayApiClient, TcatPayMetaKeys, TcatPaySettingsDTO): array<string, mixed> $action
	 * @return void
	 */
	private function run_credit_action( \WC_Order $order, string $label, \Closure $action ): void {
		if ( self::is_cvs_order( $order ) ) {
			$order->add_order_note( "⚠️ 非信用卡付款方式不支援黑貓PAY API {$label}" );
			return;
		}

		try {
			$settings = TcatPaySettingsDTO::instance();
			$meta     = new TcatPayMetaKeys( $order );
			$action( new TcatPayApiClient( $settings ), $meta, $settings );
			$order->add_order_note( "✅ 黑貓PAY 信用卡{$label}成功，交易編號 {$meta->get_trade_no()}" );
		} catch ( \Throwable $e ) {
			$order->add_order_note( "❌ 黑貓PAY 信用卡{$label}失敗：{$e->getMessage()}" );
		}
	}

	/**
	 * 查詢交易（依子方式 CocsOrderQuery / CvsOrderQuery）
	 *
	 * @param \WC_Order $order 訂單
	 * @return array<string, mixed>
	 * @throws TcatPayApiException 查詢失敗
	 */
	public function query_trade( \WC_Order $order ): array {
		return $this->query_by_trade_no( ( new TcatPayMetaKeys( $order ) )->get_trade_no(), self::is_cvs_order( $order ) );
	}

	/**
	 * 以交易編號查詢平台
	 *
	 * @param string $trade_no 交易編號
	 * @param bool   $is_cvs   是否 CVS
	 * @return array<string, mixed>
	 * @throws TcatPayApiException 查詢失敗
	 */
	private function query_by_trade_no( string $trade_no, bool $is_cvs ): array {
		return ( new TcatPayApiClient( TcatPaySettingsDTO::instance() ) )->collect(
			$is_cvs ? TcatPayApiClient::CMD_CVS_ORDER_QUERY : TcatPayApiClient::CMD_COCS_ORDER_QUERY,
			[ 'cust_order_no' => $trade_no ]
		);
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
			$actions[ self::ACTION_QUERY ] = \__( '黑貓PAY 重新查詢付款狀態（補單）', 'power_checkout' );
		}
		return $actions;
	}

	/**
	 * 「查詢補單」handler（APN 漏接時以平台查詢認列）
	 *
	 * @param \WC_Order $order 訂單
	 * @return void
	 */
	public static function handle_query_action( \WC_Order $order ): void {
		if ( self::ID !== $order->get_payment_method() ) {
			return;
		}
		try {
			$gateway  = new self();
			$trade_no = ( new TcatPayMetaKeys( $order ) )->get_trade_no();
			$query    = $gateway->query_trade( $order );
			$order->add_order_note( '黑貓PAY 查詢結果：process_code=' . ( \is_scalar( $query['process_code'] ?? null ) ? (string) $query['process_code'] : '-' ) );
			if ( ! $order->is_paid() && StatusManager::is_platform_paid( $query, self::is_cvs_order( $order ) ) ) {
				( new StatusManager( $order ) )->settle_paid( $query, $trade_no, "{$trade_no}:QUERY", null, \__( '查詢補單', 'power_checkout' ) );
			}
		} catch ( \Throwable $e ) {
			$order->add_order_note( "❌ 黑貓PAY 查詢失敗：{$e->getMessage()}" );
		}
	}

	// endregion

	// region 共用

	/**
	 * Provider meta：api-redirect；COCS 支援退款 / 部分退款 / 請款 / 取消授權 / 查詢；不做定期定額（ASM-5）
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
				capture: true,
				void: true,
				query: true,
				recurring: false,
			),
		);
	}

	/**
	 * 訂單是否為 CVS / ATM（依我方寫入的子方式 meta，不信任外部輸入）
	 *
	 * @param \WC_Order $order 訂單
	 * @return bool
	 */
	private static function is_cvs_order( \WC_Order $order ): bool {
		$method = TcatPayMethod::tryFrom( ( new TcatPayMetaKeys( $order ) )->get_method() );
		return null !== $method && $method->is_cvs();
	}

	/** @return string 平台 send_time 格式的當下時間（站台時區） */
	private static function now(): string {
		return \wp_date( 'Y-m-d H:i:s' ) ?: \gmdate( 'Y-m-d H:i:s' );
	}

	/** 初始化（gateway 啟用時）：thank-you 顯示、後台查詢補單 @return void */
	public static function init(): void {
		\add_action( 'woocommerce_thankyou_' . self::ID, [ __CLASS__, 'render_thankyou' ] );
		\add_filter( 'woocommerce_order_actions', [ __CLASS__, 'add_order_actions' ], 10, 2 );
		\add_action( 'woocommerce_order_action_' . self::ACTION_QUERY, [ __CLASS__, 'handle_query_action' ] );
	}

	/** 註冊區塊結帳整合（含子方式選項） @return void */
	public static function register_checkout_blocks(): void {
		\add_action(
			'woocommerce_blocks_payment_method_type_registration',
			static function ( PaymentMethodRegistry $registry ): void {
				if ( ! \class_exists( '\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
					return;
				}
				$gateway = GatewayUtils::get_gateway( self::ID );
				if ( $gateway instanceof AbstractPaymentGateway ) {
					$registry->register( new TcatPayBlocksIntegration( $gateway ) );
				}
			}
		);
	}

	// endregion
}
