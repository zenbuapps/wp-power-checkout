<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\NewebpayMpg\Managers;

use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgPaymentMethod;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Enums\MpgStatus;
use J7\PowerCheckout\Domains\Payment\NewebpayMpg\Shared\Helpers\MpgMetaKeys;
use J7\PowerCheckout\Domains\Payment\Shared\Enums\OrderStatus;
use J7\WpUtils\Classes\WP;

/**
 * 藍新 NewebPay MPG StatusManager
 *
 * 依解密後的通知（含 Status + Result）轉換訂單狀態：
 *  - Status='SUCCESS' 且 Result.RespondCode='00'（付款成功）→ 處理中（先做金額防竄改比對）。
 *  - Status='SUCCESS' 但為 offline 取號（VACC/CVS/BARCODE，無 RespondCode='00'）→ 寫繳費資訊，不改狀態。
 *  - 其他（付款失敗）→ 維持等待付款，記錄 Status / Message / RespondCode。
 *    但訂單已付款或已取號時只加 note，不覆寫付款明細 / 取號資訊、不改狀態（例：MPG03008 重送被拒）。
 *
 * 金額防竄改：轉 processing 前比對 Result.Amt 與訂單應收（ceil 整數）。
 * 不符 → 維持 pending + order note 告警，絕不自動完成付款。
 *
 * 比照 EcpayAIO\Managers\StatusManager。
 *
 * @see .claude/skills/newebpay-mpg/references/api-reference.md §Callback Response Parameters
 */
final class StatusManager {

	/** @var array<string, mixed> 解密後的通知 Result 物件 */
	private readonly array $result;

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $_decoded 解密後的完整通知（{ Status, Message, Result }，已驗章）
	 * @param \WC_Order            $order    訂單
	 */
	public function __construct(
		private readonly array $_decoded,
		private readonly \WC_Order $order,
	) {
		$result       = $this->_decoded['Result'] ?? [];
		$this->result = \is_array( $result ) ? $result : [];
	}

	/**
	 * 依 Status + RespondCode 更新訂單狀態
	 *
	 * @return void
	 */
	public function update_order_status(): void {
		$status       = (string) ( $this->_decoded['Status'] ?? '' );
		$message      = (string) ( $this->_decoded['Message'] ?? '' );
		$respond_code = (string) ( $this->result['RespondCode'] ?? '' );
		$payment_type = (string) ( $this->result['PaymentType'] ?? '' );

		$meta_keys = new MpgMetaKeys( $this->order );

		// 失敗通知（例：重送已用過的編號被拒 MPG03008）不得覆寫既有成果：
		// 已付款訂單的付款明細 / TradeNo 是退款依據；已取號訂單的帳號仍有效、顧客可能正要去繳費（issue #25）。
		$is_failure = ! $this->is_offline_get_code() && ! $this->is_paid();
		if ( $is_failure && ( ! $this->order->needs_payment() || $meta_keys->get_payment_info() ) ) {
			$this->order->add_order_note(
				\sprintf(
					'藍新付款失敗通知（已忽略，不影響既有付款 / 取號資訊），Status：%s，Message：%s，MerchantOrderNo：%s',
					$status,
					$message,
					(string) ( $this->result['MerchantOrderNo'] ?? '' )
				)
			);
			return;
		}

		// 寫入付款明細（整個 Result，供後台顯示與退款分流）
		$meta_keys->update_payment_detail( $this->result );

		// 寫入藍新 TradeNo（退款 / 請款 / 取消授權用）
		$trade_no = (string) ( $this->result['TradeNo'] ?? '' );
		if ( '' !== $trade_no ) {
			$meta_keys->update_trade_no( $trade_no );
		}

		// order note 記錄通知明細
		$this->order->add_order_note(
			WP::array_to_html( $this->result, [ 'title' => '藍新金流付款結果通知' ] )
		);

		// offline 取號（Status=SUCCESS 但非付款成功）→ 寫繳費資訊，不改狀態
		if ( $this->is_offline_get_code() ) {
			$this->handle_offline_get_code( $meta_keys, $payment_type );
			return;
		}

		// 付款成功判定（見 is_paid）
		if ( $this->is_paid() ) {
			// 金額防竄改：不符則維持 pending + 告警
			if ( ! $this->is_amount_matched() ) {
				$this->order->add_order_note(
					\sprintf(
						'藍新付款通知金額不符，Result.Amt：%s，訂單應收：%d，維持等待付款（疑似竄改）',
						(string) ( $this->result['Amt'] ?? '' ),
						$this->get_order_amount()
					)
				);
				$this->order->update_status( OrderStatus::PENDING->value );
				return;
			}

			// 目前編號改寫為實際付款的 MerchantOrderNo（重試付款後可能是舊編號的 ATM 繳費），QueryTradeInfo 才查得到
			$meta_keys->settle_order_no( (string) ( $this->result['MerchantOrderNo'] ?? '' ) );

			$this->order->payment_complete( $trade_no );
			$this->order->update_status( OrderStatus::PROCESSING->value );
			return;
		}

		// 其他（付款失敗）→ 維持等待付款（記錄 Status / Message / 銀行碼 RespondCode / 收單行 AuthBank）
		$auth_bank = (string) ( $this->result['AuthBank'] ?? '' );
		$this->order->add_order_note(
			\sprintf(
				'藍新付款失敗，Status：%s，Message：%s，RespondCode（銀行碼）：%s，AuthBank：%s',
				$status,
				$message,
				$respond_code,
				$auth_bank
			)
		);
		$this->order->update_status( OrderStatus::PENDING->value );
	}

	/**
	 * 是否為付款成功通知
	 *
	 *  - 信用卡 / 電子錢包等：Status=SUCCESS 且 RespondCode=00（銀行回應碼）。
	 *  - 取號型（VACC/CVS/BARCODE）繳費完成：Status=SUCCESS 且帶 PayTime（支付完成時間）。
	 *    RespondCode 屬信用卡回傳欄位，ATM / 超商繳費完成的 NotifyURL 不一定帶；
	 *    取號通知（CustomerURL）則沒有 PayTime——僅認 RespondCode 會讓顧客轉帳後訂單永遠停在待付款。
	 *
	 * @return bool
	 */
	public function is_paid(): bool {
		$status = (string) ( $this->_decoded['Status'] ?? '' );
		if ( MpgStatus::is_paid_success( $status, (string) ( $this->result['RespondCode'] ?? '' ) ) ) {
			return true;
		}

		return MpgStatus::is_status_success( $status )
		&& $this->is_offline_payment_type()
		&& '' !== (string) ( $this->result['PayTime'] ?? '' );
	}

	/**
	 * 是否為 offline 取號通知（Status=SUCCESS、PaymentType 為 VACC/CVS/BARCODE、尚未付款）
	 *
	 * 取號通知 Status 為 SUCCESS，但沒有 RespondCode='00' 也沒有 PayTime；此時應寫繳費資訊但不改狀態。
	 *
	 * @return bool
	 */
	private function is_offline_get_code(): bool {
		return MpgStatus::is_status_success( (string) ( $this->_decoded['Status'] ?? '' ) )
		&& $this->is_offline_payment_type()
		&& ! $this->is_paid();
	}

	/** @return bool Result.PaymentType 是否為取號型（VACC/CVS/BARCODE） */
	private function is_offline_payment_type(): bool {
		$method = MpgPaymentMethod::tryFrom( (string) ( $this->result['PaymentType'] ?? '' ) );
		return null !== $method && $method->is_offline();
	}

	/**
	 * 處理 offline 取號（寫繳費資訊，不改狀態）
	 *
	 * @param MpgMetaKeys $meta_keys    meta 存取
	 * @param string      $payment_type 付款方式
	 *
	 * @return void
	 */
	private function handle_offline_get_code( MpgMetaKeys $meta_keys, string $payment_type ): void {
		// 冪等：已寫入取號資訊則不重複
		if ( $meta_keys->get_payment_info() ) {
			return;
		}

		// 萃取繳費資訊欄位（依付款方式不同）
		$info_keys = [ 'PaymentType', 'BankCode', 'CodeNo', 'Barcode_1', 'Barcode_2', 'Barcode_3', 'ExpireDate', 'ExpireTime', 'StoreType', 'StoreCode' ];
		$info      = [];
		foreach ( $info_keys as $key ) {
			if ( isset( $this->result[ $key ] ) && '' !== $this->result[ $key ] ) {
				$info[ $key ] = $this->result[ $key ];
			}
		}

		$meta_keys->update_payment_info( $info );

		$this->order->add_order_note(
			WP::array_to_html(
				$info,
				[ 'title' => \sprintf( '藍新金流 %s 取號繳費資訊', $payment_type ) ]
			)
		);
		// 不改訂單狀態（維持等待付款）
	}

	/**
	 * 比對藍新回傳金額（Result.Amt）是否等於訂單應收總額
	 *
	 * 藍新金額為整數新台幣，訂單總額以 ceil 取整後比對。
	 *
	 * @return bool
	 */
	private function is_amount_matched(): bool {
		$amt = (int) ( $this->result['Amt'] ?? 0 );
		return $amt === $this->get_order_amount();
	}

	/**
	 * 取得訂單應收金額（整數新台幣）
	 *
	 * @return int
	 */
	private function get_order_amount(): int {
		return (int) \ceil( (float) $this->order->get_total() );
	}
}
