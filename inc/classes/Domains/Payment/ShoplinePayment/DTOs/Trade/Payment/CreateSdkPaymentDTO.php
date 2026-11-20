<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Trade\Payment;

use J7\PowerCheckout\Domains\Payment\ShoplinePayment\DTOs\Components;

/**
 * SHOPLINE Payments 內嵌式建立付款交易 request（POST /api/v1/trade/payment/create）
 *
 * 規格：.claude/skills/shopline-payments-v1/references/api-reference.md §3
 *  - acquirerType 固定 SDK；paySession 為 SDK createPayment() 產物，**原樣**帶入（不可解析）
 *  - amount 一律由後端依訂單金額計算（round(total * 100)），忽略前端傳入值
 *  - referenceOrderId = WC 訂單 ID（OrderResolver / 導回同步以數字型 referenceOrderId 對單）
 *  - confirm：第一版只開信用卡（含分期，分期期數由 SDK 初始化 installmentCounts 決定，body 不帶；ASM-4）
 *  - order / billing / customer / client 與導轉式 CreateSessionDTO 共用同一組 Components
 *
 * 不繼承 wp-utils DTO（R16）：create() 內顯式驗證並拋 \InvalidArgumentException。
 */
final class CreateSdkPaymentDTO {

	/** @var string 串接模式，固定 SDK */
	public const ACQUIRER_TYPE = 'SDK';

	/** @var string 付款方式（第一版只開信用卡，與 SDK 初始化 paymentMethod 一致） */
	public const PAYMENT_METHOD = 'CreditCard';

	/** @var string 付款場景：一般付款 */
	public const PAYMENT_BEHAVIOR = 'Regular';

	/**
	 * Constructor
	 *
	 * @param string               $reference_order_id 特店訂單號（WC 訂單 ID）
	 * @param int                  $amount_cents       金額（台幣 ×100）
	 * @param string               $language           zh-TW|en
	 * @param string               $return_url         付款完成導回頁
	 * @param string               $pay_session        SDK paySession（原樣字串）
	 * @param array<string, mixed> $order              Components\Order\Order
	 * @param array<string, mixed> $billing            Components\Billing
	 * @param array<string, mixed> $customer           Components\Customer
	 * @param array<string, mixed> $client             Components\Client
	 */
	private function __construct(
		public readonly string $reference_order_id,
		public readonly int $amount_cents,
		public readonly string $language,
		public readonly string $return_url,
		public readonly string $pay_session,
		public readonly array $order,
		public readonly array $billing,
		public readonly array $customer,
		public readonly array $client,
	) {
	}

	/**
	 * 由訂單建立
	 *
	 * @param \WC_Order $order       訂單
	 * @param string    $pay_session SDK paySession（原樣字串）
	 * @param string    $return_url  付款完成導回頁（order-received）
	 * @return self
	 * @throws \InvalidArgumentException PaySession 空白、訂單金額 ≤ 0 或 returnUrl 空白
	 */
	public static function create( \WC_Order $order, string $pay_session, string $return_url ): self {
		if ( '' === \trim( $pay_session ) ) {
			throw new \InvalidArgumentException( 'CreateSdkPaymentDTO：paySession 不可為空' );
		}

		$amount_cents = self::to_cents( (float) $order->get_total() );
		if ( $amount_cents <= 0 ) {
			throw new \InvalidArgumentException( "CreateSdkPaymentDTO：訂單 #{$order->get_id()} 金額必須大於 0" );
		}

		if ( '' === $return_url ) {
			throw new \InvalidArgumentException( 'CreateSdkPaymentDTO：returnUrl 不可為空' );
		}

		return new self(
			reference_order_id: (string) $order->get_id(),
			amount_cents: $amount_cents,
			language: 'zh_TW' === \get_locale() ? 'zh-TW' : 'en',
			return_url: $return_url,
			pay_session: $pay_session,
			order: Components\Order\Order::create( $order )->to_array(),
			billing: Components\Billing::create( $order )->to_array(),
			customer: Components\Customer::create( $order )->to_array(),
			client: Components\Client::create( $order )->to_array(),
		);
	}

	/**
	 * 台幣金額轉 SLP 金額單位（×100，四捨五入避免 19.99 * 100 = 1998.999… 被截斷）
	 *
	 * @param float $amount 台幣金額
	 * @return int
	 */
	public static function to_cents( float $amount ): int {
		return (int) \round( $amount * 100 );
	}

	/** @return array<string, mixed> request body */
	public function to_array(): array {
		return [
			'acquirerType'     => self::ACQUIRER_TYPE,
			'referenceOrderId' => $this->reference_order_id,
			'amount'           => [
				'value'    => $this->amount_cents,
				'currency' => 'TWD',
			],
			'language'         => $this->language,
			'returnUrl'        => $this->return_url,
			'paySession'       => $this->pay_session,
			'order'            => $this->order,
			'billing'          => $this->billing,
			'confirm'          => [
				'paymentMethod'   => self::PAYMENT_METHOD,
				'paymentBehavior' => self::PAYMENT_BEHAVIOR,
			],
			'customer'         => $this->customer,
			'client'           => $this->client,
		];
	}
}
