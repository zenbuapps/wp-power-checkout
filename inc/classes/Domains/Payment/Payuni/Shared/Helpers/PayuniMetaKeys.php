<?php
/**
 * PAYUNi UPP V2 專用 Order Meta Key 存取（HPOS 相容）
 *
 * 設計比照 NewebpayMpg\Shared\Helpers\MpgMetaKeys，但 PAYUNi 的冪等鍵為交易單號
 * MerTradeNo（存 _pc_payuni_trade_no），語意與綠界 / 藍新 / SLP 不同，故獨立 helper。
 *
 * ⚠️ 全程 $order->get_meta() / update_meta_data()（HPOS 相容），禁用 get_post_meta()。
 *
 * Meta keys（對齊 CLAUDE.md §Order Meta Keys 規劃）：
 *  - _pc_payuni_trade_no       目前（或已付款成功）的 MerTradeNo（查詢 / 請退款用）
 *  - _pc_payuni_trade_no_history 送出過的全部 MerTradeNo（多筆 meta row；Notify / ReturnURL 反查訂單用）
 *  - _pc_payuni_redirect_pending 顧客剛發起付款、待 order-received 頁導轉 PAYUNi 一次（'yes'）
 *  - _pc_payuni_payment_detail 付款結果明細（NotifyURL / ReturnURL 解密後的回傳）
 *  - _pc_payuni_payment_info   offline 取號繳費資訊（ATM 虛擬帳號 / CVS 繳費代碼 / ExpireDate）
 *  - _pc_payuni_capture_status 請退款 / 取消授權狀態機（''｜'captured'｜'voided'｜'refunded'）
 *
 * @see .claude/skills/payuni-upp-v2/SKILL.md
 */

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\Payuni\Shared\Helpers;

/** PAYUNi UPP V2 Order Meta Key 存取 Helper */
final class PayuniMetaKeys {

	/** @var string MerTradeNo（冪等鍵） */
	private const TRADE_NO_KEY = '_pc_payuni_trade_no';

	/**
	 * @var string 送出過的全部 MerTradeNo
	 *
	 * 每次付款嘗試都換新編號（PAYUNi 10 分鐘內同編號重送回 UPP01007；取號後重送也會變成第二筆交易），
	 * 舊編號的取號 / 繳費通知仍須能反查本訂單，故全部保留。
	 */
	private const TRADE_NO_HISTORY_KEY = '_pc_payuni_trade_no_history';

	/** @var string 顧客已發起付款、待 order-received 頁導轉 PAYUNi（process_payment 寫入，導轉時清除） */
	private const REDIRECT_PENDING_KEY = '_pc_payuni_redirect_pending';

	/** @var string 付款結果明細 */
	private const PAYMENT_DETAIL_KEY = '_pc_payuni_payment_detail';

	/** @var string offline 取號繳費資訊 */
	private const PAYMENT_INFO_KEY = '_pc_payuni_payment_info';

	/** @var string 請退款 / 取消授權狀態機（''｜'captured'｜'voided'｜'refunded'） */
	private const CAPTURE_STATUS_KEY = '_pc_payuni_capture_status';

	/** Constructor */
	public function __construct(
		private readonly \WC_Order $_order,
	) {}

	/** @return string 取得 MerTradeNo（未設定回空字串） */
	public function get_trade_no(): string {
		return (string) ( $this->_order->get_meta( self::TRADE_NO_KEY ) ?: '' );
	}

	/**
	 * 儲存 MerTradeNo（冪等鍵）
	 *
	 * @param string $value MerTradeNo
	 * @return void
	 */
	public function update_trade_no( string $value ): void {
		$this->_order->update_meta_data( self::TRADE_NO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return array<int, string> 送出過的全部 MerTradeNo（依送出順序） */
	public function get_trade_no_history(): array {
		$rows = $this->_order->get_meta( self::TRADE_NO_HISTORY_KEY, false );
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

	/** @return array<int, string> 本訂單用過的全部 MerTradeNo（目前編號 + 歷史），產生新編號時須避開 */
	public function get_used_trade_nos(): array {
		$used    = $this->get_trade_no_history();
		$current = $this->get_trade_no();
		if ( '' !== $current && ! \in_array( $current, $used, true ) ) {
			$used[] = $current;
		}

		return $used;
	}

	/**
	 * 開始一次新的付款嘗試：新編號設為目前編號、加入歷史、清除待導轉旗標（一次 save）
	 *
	 * 升級前寫入、尚未入歷史的目前編號（舊格式 PCU{order_id}）會先補進歷史，確保其通知仍能反查本訂單。
	 *
	 * @param string $trade_no 本次送往 PAYUNi 的 MerTradeNo
	 * @return void
	 */
	public function start_payment_attempt( string $trade_no ): void {
		$this->add_to_history( [ $this->get_trade_no(), $trade_no ] );
		$this->_order->update_meta_data( self::TRADE_NO_KEY, $trade_no );
		$this->_order->delete_meta_data( self::REDIRECT_PENDING_KEY );
		$this->_order->save_meta_data();
	}

	/**
	 * 把目前編號改寫為實際付款成功的 MerTradeNo（查詢 / 請退款要用付款成功的那筆）
	 *
	 * @param string $trade_no 付款成功的 MerTradeNo（空字串略過）
	 * @return void
	 */
	public function settle_trade_no( string $trade_no ): void {
		if ( '' === $trade_no || $trade_no === $this->get_trade_no() ) {
			return;
		}
		$this->add_to_history( [ $this->get_trade_no(), $trade_no ] );
		$this->_order->update_meta_data( self::TRADE_NO_KEY, $trade_no );
		$this->_order->save_meta_data();
	}

	/**
	 * MerTradeNo 是否為本訂單送出過的編號（目前編號或歷史）
	 *
	 * @param string $trade_no MerTradeNo
	 * @return bool
	 */
	public function owns_trade_no( string $trade_no ): bool {
		return '' !== $trade_no && \in_array( $trade_no, $this->get_used_trade_nos(), true );
	}

	/** @return bool 顧客是否已發起付款、待 order-received 頁導轉 PAYUNi */
	public function is_redirect_pending(): bool {
		return 'yes' === $this->_order->get_meta( self::REDIRECT_PENDING_KEY );
	}

	/**
	 * 標記「顧客已發起付款，待 order-received 頁導轉 PAYUNi」（process_payment 時寫入）
	 *
	 * @return void
	 */
	public function mark_redirect_pending(): void {
		$this->_order->update_meta_data( self::REDIRECT_PENDING_KEY, 'yes' );
		$this->_order->save_meta_data();
	}

	/**
	 * 將尚未入歷史的編號加入歷史（每個編號一筆 meta row；不 save，由呼叫端統一 save）
	 *
	 * @param array<int, string> $trade_nos MerTradeNo 清單（空字串略過）
	 * @return void
	 */
	private function add_to_history( array $trade_nos ): void {
		$history = $this->get_trade_no_history();
		foreach ( $trade_nos as $value ) {
			if ( '' !== $value && ! \in_array( $value, $history, true ) ) {
				$this->_order->add_meta_data( self::TRADE_NO_HISTORY_KEY, $value, false );
				$history[] = $value;
			}
		}
	}

	/** @return array<string, mixed> 取得付款結果明細（未設定回空陣列） */
	public function get_payment_detail(): array {
		$value = $this->_order->get_meta( self::PAYMENT_DETAIL_KEY ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存付款結果明細
	 *
	 * @param array<string, mixed> $value 付款結果明細（PAYUNi 回傳）
	 * @return void
	 */
	public function update_payment_detail( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_DETAIL_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return array<string, mixed> 取得 offline 取號繳費資訊（未設定回空陣列） */
	public function get_payment_info(): array {
		$value = $this->_order->get_meta( self::PAYMENT_INFO_KEY ) ?: [];
		return \is_array( $value ) ? $value : [];
	}

	/**
	 * 儲存 offline 取號繳費資訊
	 *
	 * @param array<string, mixed> $value 取號繳費資訊（BankCode / VAccount / ExpireDate 等）
	 * @return void
	 */
	public function update_payment_info( array $value ): void {
		$this->_order->update_meta_data( self::PAYMENT_INFO_KEY, $value );
		$this->_order->save_meta_data();
	}

	/** @return string 取得請退款 / 取消授權狀態（未設定回空字串） */
	public function get_capture_status(): string {
		return (string) ( $this->_order->get_meta( self::CAPTURE_STATUS_KEY ) ?: '' );
	}

	/**
	 * 儲存請退款 / 取消授權狀態機
	 *
	 * 值集：''（未請款）｜'captured' 已請款｜'voided' 已取消授權｜'refunded' 已退款。
	 *
	 * @param string $value 狀態機值（''｜'captured'｜'voided'｜'refunded'）
	 * @return void
	 */
	public function update_capture_status( string $value ): void {
		$this->_order->update_meta_data( self::CAPTURE_STATUS_KEY, $value );
		$this->_order->save_meta_data();
	}

	/**
	 * 以 MerTradeNo 反查訂單
	 *
	 * @param string $trade_no MerTradeNo
	 * @return \WC_Order|null 找不到回 null
	 */
	public static function get_order_by_trade_no( string $trade_no ): \WC_Order|null {
		if ( '' === $trade_no ) {
			return null;
		}

		// 先找目前編號，再找歷史（舊付款嘗試的取號 / 繳費通知）
		foreach ( [ self::TRADE_NO_KEY, self::TRADE_NO_HISTORY_KEY ] as $meta_key ) {
			$orders = \wc_get_orders(
				[
					'limit'      => 1,
					'meta_key'   => $meta_key, // phpcs:ignore
					'meta_value' => $trade_no, // phpcs:ignore
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
