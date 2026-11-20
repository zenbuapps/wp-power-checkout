<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\DTOs;

use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Enums\TcatPayMethod;
use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\ItemName;

/**
 * CvsOrderAppend（ibon 超商代碼 / ATM 虛擬帳號取號）請求參數
 *
 * - payment_type / payment_acquirerType 由子方式固定（ibon=0+安源2、ATM=1+統一金流3）。
 * - payer_mobile 為所有 CVS 必填；統一金流（ATM）另需 payer_name / payer_postcode / payer_address / payer_email。
 *   缺漏在送平台前擋下（顯式驗證，R16），避免平台回看不懂的錯誤。
 * - order_detail 以 150 字保守截斷（PDF 綜合繳款單上限）。
 *
 * @see .claude/skills/tcat-pay/references/api-reference.md §2.1
 */
final class CvsOrderAppendParams {

	/**
	 * Constructor
	 *
	 * @param TcatPayMethod $method         子方式（cvs_ibon / atm）
	 * @param string        $cust_order_no  契客訂單編號
	 * @param int           $order_amount   金額（元）
	 * @param string        $expire_date    繳費到期日 Y-m-d
	 * @param string        $order_detail   品名
	 * @param string        $payer_name     繳款人姓名
	 * @param string        $payer_postcode 郵遞區號
	 * @param string        $payer_address  地址
	 * @param string        $payer_mobile   手機
	 * @param string        $payer_email    Email
	 * @param string        $apn_url        APN 網址（空 = 不送）
	 * @throws \InvalidArgumentException 必填缺漏
	 */
	public function __construct(
		public readonly TcatPayMethod $method,
		public readonly string $cust_order_no,
		public readonly int $order_amount,
		public readonly string $expire_date,
		public readonly string $order_detail,
		public readonly string $payer_name,
		public readonly string $payer_postcode,
		public readonly string $payer_address,
		public readonly string $payer_mobile,
		public readonly string $payer_email,
		public readonly string $apn_url = '',
	) {
		if ( ! $method->is_cvs() ) {
			throw new \InvalidArgumentException( 'CvsOrderAppendParams：子方式必須為 ibon 或 ATM' );
		}
		if ( '' === $cust_order_no || '' === $order_detail || 1 !== \preg_match( '/^\d{4}-\d{2}-\d{2}$/', $expire_date ) || $order_amount <= 0 ) {
			throw new \InvalidArgumentException( 'CvsOrderAppendParams：缺少必填欄位' );
		}
		if ( '' === $payer_mobile ) {
			throw new \InvalidArgumentException( \__( '超商代碼 / ATM 繳費需要繳款人手機號碼', 'power_checkout' ) );
		}
		if ( TcatPayMethod::ATM === $method && ( '' === $payer_name || '' === $payer_postcode || '' === $payer_address || '' === $payer_email ) ) {
			throw new \InvalidArgumentException( \__( 'ATM 虛擬帳號需要繳款人姓名、郵遞區號、地址與 Email', 'power_checkout' ) );
		}
	}

	/**
	 * 由訂單建立（帳單資料）
	 *
	 * @param \WC_Order          $order    訂單
	 * @param TcatPaySettingsDTO $settings 設定
	 * @param TcatPayMethod      $method   子方式
	 * @param string             $trade_no 交易編號
	 * @param int                $amount   金額
	 * @param string             $apn_url  APN 網址
	 * @return self
	 */
	public static function from_order( \WC_Order $order, TcatPaySettingsDTO $settings, TcatPayMethod $method, string $trade_no, int $amount, string $apn_url ): self {
		$address = \implode(
			'',
			\array_filter(
				[
					$order->get_billing_state(),
					$order->get_billing_city(),
					$order->get_billing_address_1(),
					$order->get_billing_address_2(),
				]
			)
		);

		return new self(
			method: $method,
			cust_order_no: $trade_no,
			order_amount: $amount,
			expire_date: \wp_date( 'Y-m-d', \time() + $settings->expire_days * 86400 ) ?: \gmdate( 'Y-m-d' ),
			order_detail: ItemName::build( $order, ItemName::CVS_MAX ),
			payer_name: \trim( $order->get_billing_last_name() . $order->get_billing_first_name() ),
			payer_postcode: \trim( $order->get_billing_postcode() ),
			payer_address: \trim( $address ),
			payer_mobile: \preg_replace( '/[\s\-]/', '', $order->get_billing_phone() ) ?? '',
			payer_email: \trim( $order->get_billing_email() ),
			apn_url: \strlen( $apn_url ) <= 250 ? $apn_url : '',
		);
	}

	/** @return array<string, string|int> API body（不含 cmd / cust_id） */
	public function to_array(): array {
		return \array_filter(
			[
				'cust_order_no'        => $this->cust_order_no,
				'order_amount'         => $this->order_amount,
				'expire_date'          => $this->expire_date,
				'payer_name'           => $this->payer_name,
				'payer_postcode'       => $this->payer_postcode,
				'payer_address'        => $this->payer_address,
				'payer_mobile'         => $this->payer_mobile,
				'payer_email'          => $this->payer_email,
				'payment_type'         => $this->method->payment_type(),
				'payment_acquirerType' => $this->method->payment_acquirer_type(),
				'order_detail'         => $this->order_detail,
				'apn_url'              => $this->apn_url,
			],
			static fn( string|int $value ): bool => '' !== $value
		);
	}
}
