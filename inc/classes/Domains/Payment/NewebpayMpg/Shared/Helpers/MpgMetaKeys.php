<?php
/**
 * 藍新 NewebPay MPG 專用 Order Meta Key 存取（HPOS 相容）
 *
 * 設計比照 EcpayAIO\Shared\Helpers\EcpayMetaKeys，但藍新有專屬冪等鍵（MerchantOrderNo，存 _pc_newebpay_order_no）
 * 與藍新交易編號（TradeNo，存 _pc_newebpay_trade_no），語意與綠界 / SLP 不同，故獨立 helper。
 *
 * ⚠️ 全程 $order->get_meta() / update_meta_data()（HPOS 相容），禁用 get_post_meta()。
 *
 * Meta keys（對齊 CLAUDE.md Order Meta Keys 規劃）：
 *  - _pc_newebpay_order_no       MerchantOrderNo（最近一次送出的編號，付款成功後改寫為實際付款的編號；反查訂單主鍵）
 *  - _pc_newebpay_order_no_history 送出過的全部 MerchantOrderNo（重試付款換新編號，舊編號 callback 仍可反查）
 *  - _pc_newebpay_redirect_pending 顧客已發起付款、待 order-received 頁導轉藍新（'yes'）
 *  - _pc_newebpay_trade_no       藍新 TradeNo（callback / 退款用，藍新回傳）
 *  - _pc_newebpay_payment_detail 付款結果明細（NotifyURL / ReturnURL 解密後的 Result）
 *  - _pc_newebpay_payment_info   offline 取號繳費資訊（CodeNo/BankCode/Barcode_1~3/ExpireDate）
 *  - _pc_newebpay_credit_variant 信用卡變體（''｜'installment'｜'period'）
 *  - _pc_newebpay_installment    信用卡分期期數（如 '6'）
 *  - _pc_newebpay_capture_status 請款 / 授權狀態（''｜'captured'｜'voided'）
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers;

/** 藍新 MPG Order Meta Key 存取 Helper */
final class MpgMetaKeys {

	/** @var string MerchantOrderNo（最近一次送出的編號；付款成功後改寫為實際付款成功的編號，QueryTradeInfo 用） */
	private const ORDER_NO_KEY = '_pc_newebpay_order_no';

	/**
	 * @var string 送出過的全部 MerchantOrderNo（每個編號一筆 meta row，非序列化陣列）
	 *
	 * 藍新同一 MerchantOrderNo 不可重送，顧客重試付款時每次都換新編號；舊編號的取號 / 繳費通知仍可能晚到
	 * （例：先拿 ATM 帳號、改刷卡未付、再用 ATM 帳號繳費），故全部保留供 callback 反查。
	 */
	private const ORDER_NO_HISTORY_KEY = '_pc_newebpay_order_no_history';

	/** @var string 顧客已發起付款、待 order-received 頁導轉藍新（process_payment 寫入，導轉時清除） */
	private const REDIRECT_PENDING_KEY = '_pc_newebpay_redirect_pending';

	/** @var string 藍新 TradeNo（藍新交易編號） */
	private const TRADE_NO_KEY = '_pc_newebpay_trade_no';

	/** @var string 付款結果明細 */
	private const PAYMENT_DETAIL_KEY = '_pc_newebpay_payment_detail';

	/** @var string offline 取號繳費資訊 */
	private const PAYMENT_INFO_KEY = '_pc_newebpay_payment_info';

	/** @var string 信用卡付款變體（''｜'installment'｜'period'） */
	private const CREDIT_VARIANT_KEY = '_pc_newebpay_credit_variant';

	/** @var string 信用卡分期期數 */
	private const INSTALLMENT_KEY = '_pc_newebpay_installment';

	/** @var string 信用卡請款 / 授權狀態（''｜'captured'｜'voided'） */
	private const CAPTURE_STATUS_KEY = '_pc_newebpay_capture_status';

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $_order,
	) {}

	/** @return string 取得 MerchantOrderNo */
	public function get_order_no(): string {
		return (string) ( $this->_order->get_meta( self::ORDER_NO_KEY ) ?: '' );
	}

	/**
	 * 儲存 MerchantOrderNo（冪等鍵）
	 *
	 * @param string $value MerchantOrderNo
	 * @return void
	 */
	public function update_order_no( string $value ): void {
		$this->_order->update_meta_data( self::ORDER_NO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return array<int, string> 送出過的全部 MerchantOrderNo（依送出順序，不含升級前未入歷史的舊編號） */
	public function get_order_no_history(): array {
		$rows = $this->_order->get_meta( self::ORDER_NO_HISTORY_KEY, false );
		if ( ! \is_array( $rows ) ) {
			return [];
		}

		$values = [];
		foreach ( $rows as $row ) {
			$value = $row instanceof \WC_Meta_Data ? ( $row->get_data()['value'] ?? null ) : null;
			if ( \is_string( $value ) && '' !== $value && ! \in_array( $value, $values, true ) ) {
				$values[] = $value;
			}
		}

		return $values;
	}

	/** @return array<int, string> 本訂單用過的全部 MerchantOrderNo（目前編號 + 歷史），產生新編號時須避開 */
	public function get_used_order_nos(): array {
		$used    = $this->get_order_no_history();
		$current = $this->get_order_no();
		if ( '' !== $current && ! \in_array( $current, $used, true ) ) {
			$used[] = $current;
		}

		return $used;
	}

	/**
	 * 開始一次新的付款嘗試：寫入新的 MerchantOrderNo 為目前編號、加入歷史、清除待導轉旗標（一次 save）
	 *
	 * 升級前寫入、尚未入歷史的目前編號會先補進歷史，確保其 callback 仍能反查到本訂單。
	 *
	 * @param string $order_no 本次送往藍新的 MerchantOrderNo
	 * @return void
	 */
	public function start_payment_attempt( string $order_no ): void {
		$this->add_to_history( [ $this->get_order_no(), $order_no ] );
		$this->_order->update_meta_data( self::ORDER_NO_KEY, $order_no );
		$this->_order->delete_meta_data( self::REDIRECT_PENDING_KEY );
		$this->_order->save_meta_data();
	}

	/**
	 * 把目前編號改寫為實際付款成功的 MerchantOrderNo（原目前編號保留於歷史，callback 仍可反查）
	 *
	 * @param string $order_no 付款成功的 MerchantOrderNo（空字串略過）
	 * @return void
	 */
	public function settle_order_no( string $order_no ): void {
		if ( '' === $order_no || $order_no === $this->get_order_no() ) {
			return;
		}
		$this->add_to_history( [ $this->get_order_no(), $order_no ] );
		$this->_order->update_meta_data( self::ORDER_NO_KEY, $order_no );
		$this->_order->save_meta_data();
	}

	/**
	 * 將尚未入歷史的編號加入歷史（每個編號一筆 meta row；不 save，由呼叫端統一 save）
	 *
	 * @param array<int, string> $order_nos MerchantOrderNo 清單（空字串略過）
	 * @return void
	 */
	private function add_to_history( array $order_nos ): void {
		$history = $this->get_order_no_history();
		foreach ( $order_nos as $value ) {
			if ( '' !== $value && ! \in_array( $value, $history, true ) ) {
				$this->_order->add_meta_data( self::ORDER_NO_HISTORY_KEY, $value, false );
				$history[] = $value;
			}
		}
	}

	/** @return bool 顧客是否已發起付款、待 order-received 頁導轉藍新 */
	public function is_redirect_pending(): bool {
		return 'yes' === $this->_order->get_meta( self::REDIRECT_PENDING_KEY );
	}

	/**
	 * 標記「顧客已發起付款，待 order-received 頁導轉藍新」（process_payment 時寫入）
	 *
	 * @return void
	 */
	public function mark_redirect_pending(): void {
		$this->_order->update_meta_data( self::REDIRECT_PENDING_KEY, 'yes' );
		$this->_order->save_meta_data();
	}

	/** @return string 取得藍新 TradeNo */
	public function get_trade_no(): string {
		return (string) ( $this->_order->get_meta( self::TRADE_NO_KEY ) ?: '' );
	}

	/**
	 * 儲存藍新 TradeNo
	 *
	 * @param string $value 藍新 TradeNo
	 * @return void
	 */
	public function update_trade_no( string $value ): void {
		$this->_order->update_meta_data( self::TRADE_NO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return array<string, mixed> 取得付款結果明細 */
	public function get_payment_detail(): array {
		$value = $this->_order->get_meta( self::PAYMENT_DETAIL_KEY ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存付款結果明細
	 *
	 * @param array<string, mixed> $value 付款結果明細（藍新 Result）
	 * @return void
	 */
	public function update_payment_detail( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_DETAIL_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return array<string, mixed> 取得 offline 取號繳費資訊 */
	public function get_payment_info(): array {
		$value = $this->_order->get_meta( self::PAYMENT_INFO_KEY ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存 offline 取號繳費資訊
	 *
	 * @param array<string, mixed> $value 取號繳費資訊（CodeNo/BankCode/Barcode_1~3/ExpireDate 等）
	 * @return void
	 */
	public function update_payment_info( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_INFO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return string 取得信用卡付款變體（''｜'installment'｜'period'） */
	public function get_credit_variant(): string {
		return (string) ( $this->_order->get_meta( self::CREDIT_VARIANT_KEY ) ?: '' );
	}

	/**
	 * 儲存信用卡付款變體
	 *
	 * @param string $value ''｜'installment'｜'period'
	 * @return void
	 */
	public function update_credit_variant( string $value ): void {
		$this->_order->update_meta_data( self::CREDIT_VARIANT_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return string 取得信用卡分期期數（如 '6'；無則空字串） */
	public function get_installment(): string {
		return (string) ( $this->_order->get_meta( self::INSTALLMENT_KEY ) ?: '' );
	}

	/**
	 * 儲存信用卡分期期數
	 *
	 * @param string $value 期數（如 '6'）
	 * @return void
	 */
	public function update_installment( string $value ): void {
		$this->_order->update_meta_data( self::INSTALLMENT_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return string 取得信用卡請款 / 授權狀態（''｜'captured'｜'voided'） */
	public function get_capture_status(): string {
		return (string) ( $this->_order->get_meta( self::CAPTURE_STATUS_KEY ) ?: '' );
	}

	/**
	 * 儲存信用卡請款 / 授權狀態
	 *
	 * @param string $value ''｜'captured' 已請款｜'voided' 已取消授權
	 * @return void
	 */
	public function update_capture_status( string $value ): void {
		$this->_order->update_meta_data( self::CAPTURE_STATUS_KEY, $value );
		$this->_order->save_meta_data();
	}

	/**
	 * 以 MerchantOrderNo 反查訂單
	 *
	 * 先比對目前編號，找不到再比對送出過的歷史編號，
	 * 讓顧客重試付款後，舊編號的取號 / 繳費通知仍能對應到同一張訂單。
	 *
	 * @param string $order_no MerchantOrderNo
	 * @return \WC_Order|null
	 */
	public static function get_order_by_order_no( string $order_no ): \WC_Order|null {
		if ( '' === $order_no ) {
			return null;
		}

		foreach ( [ self::ORDER_NO_KEY, self::ORDER_NO_HISTORY_KEY ] as $meta_key ) {
			$orders = \wc_get_orders(
				[
					'limit'      => 1,
					'meta_key'   => $meta_key, // phpcs:ignore
					'meta_value' => $order_no, // phpcs:ignore
				]
			);
			$order  = \is_array( $orders ) ? \reset( $orders ) : false;
			if ( $order instanceof \WC_Order ) {
				return $order;
			}
		}

		return null;
	}
}
