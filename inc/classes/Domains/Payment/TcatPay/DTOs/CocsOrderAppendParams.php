<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Payment\TcatPay\DTOs;

use J7\PowerCheckout\Domains\Payment\TcatPay\Shared\Helpers\ItemName;

/**
 * CocsOrderAppend（線上刷卡，回刷卡短網址）請求參數
 *
 * 用不加值通知的 CocsOrderAppend（非 CocsOrderAppend2）：我方自有訂單信與導轉，不需平台代發簡訊 / EMAIL。
 * 必填（cust_order_no / order_amount / order_detail / acquirer_type / send_time）於建構時顯式驗證（R16）。
 * success_url 平台上限 250 字且平台會再串上自己的 query string；超過時不送（改用平台後台預設）。
 *
 * @see .claude/skills/tcat-pay/references/api-reference.md §3.1
 */
final class CocsOrderAppendParams {

	/** @var int success_url / apn_url 上限 */
	private const URL_MAX = 250;

	/**
	 * Constructor
	 *
	 * @param string $cust_order_no    契客訂單編號
	 * @param int    $order_amount     金額（元，整數）
	 * @param string $order_detail     品名
	 * @param string $acquirer_type    收單銀行
	 * @param string $send_time        傳送時間（當下）
	 * @param string $limit_product_id 限定產品別（空 = 不送）
	 * @param string $success_url      授權成功導回（空 = 不送）
	 * @param string $apn_url          APN 網址（空 = 不送）
	 * @throws \InvalidArgumentException 必填缺漏
	 */
	public function __construct(
		public readonly string $cust_order_no,
		public readonly int $order_amount,
		public readonly string $order_detail,
		public readonly string $acquirer_type,
		public readonly string $send_time,
		public readonly string $limit_product_id = '',
		public readonly string $success_url = '',
		public readonly string $apn_url = '',
	) {
		if ( '' === $cust_order_no || '' === $order_detail || '' === $acquirer_type || '' === $send_time ) {
			throw new \InvalidArgumentException( 'CocsOrderAppendParams：缺少必填欄位' );
		}
		if ( $order_amount <= 0 ) {
			throw new \InvalidArgumentException( 'CocsOrderAppendParams：order_amount 必須大於 0' );
		}
	}

	/**
	 * 由訂單建立
	 *
	 * @param \WC_Order          $order       訂單
	 * @param TcatPaySettingsDTO $settings    設定
	 * @param string             $trade_no    交易編號
	 * @param int                $amount      金額
	 * @param string             $success_url 授權成功導回
	 * @param string             $apn_url     APN 網址
	 * @return self
	 */
	public static function from_order( \WC_Order $order, TcatPaySettingsDTO $settings, string $trade_no, int $amount, string $success_url, string $apn_url ): self {
		return new self(
			cust_order_no: $trade_no,
			order_amount: $amount,
			order_detail: ItemName::build( $order, ItemName::COCS_MAX ),
			acquirer_type: $settings->acquirer_type,
			send_time: \wp_date( 'Y-m-d H:i:s' ) ?: \gmdate( 'Y-m-d H:i:s' ),
			limit_product_id: $settings->limit_product_id,
			success_url: \strlen( $success_url ) <= self::URL_MAX ? $success_url : '',
			apn_url: \strlen( $apn_url ) <= self::URL_MAX ? $apn_url : '',
		);
	}

	/** @return array<string, string|int> API body（不含 cmd / cust_id） */
	public function to_array(): array {
		return \array_filter(
			[
				'cust_order_no'    => $this->cust_order_no,
				'order_amount'     => $this->order_amount,
				'order_detail'     => $this->order_detail,
				'acquirer_type'    => $this->acquirer_type,
				'limit_product_id' => $this->limit_product_id,
				'send_time'        => $this->send_time,
				'success_url'      => $this->success_url,
				'apn_url'          => $this->apn_url,
			],
			static fn( string|int $value ): bool => '' !== $value
		);
	}
}
