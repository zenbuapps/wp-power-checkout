<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Hct\DTOs;

use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Enums\HctProductType;
use J7\PowerCheckout\Domains\Logistics\Hct\Shared\Exceptions\HctLogisticsException;

/**
 * 新竹物流 TransData / R_TransData 單筆欄位（由訂單組裝；必填顯式驗證）
 *
 * - 正物流必填：epino / ercsig / ertel1 / eraddr / ejamt / eqamt（api-reference §1）
 * - 傳票類別 eprdct：貨到付款（payment_method = cod）→ 41 代收 + eqmny；其餘 11 月結
 * - eqmny 上限 99,999,999（_Json 版 Char(8)；超長新竹會靜默截斷金額 → 必須自行擋，zenbu 對 DEV 實打）
 * - 欄位內容去除新竹禁用字元 & " < > '
 * - 出貨日 esdate 顯式帶台北當日（不讓新竹預設）
 *
 * @see .claude/skills/hct-logistics/references/api-reference.md §1、§5
 */
final class TransDataParams {

	/** @var int 代收貨款上限 */
	public const EQMNY_MAX = 99999999;

	/** @var int 重量上限（String(5)） */
	private const WEIGHT_MAX = 99999;

	/**
	 * Constructor
	 *
	 * @param array<string, string> $fields API 欄位
	 */
	private function __construct( private readonly array $fields ) {
	}

	/**
	 * 正物流（TransData）欄位
	 *
	 * @param \WC_Order               $order        訂單
	 * @param HctLogisticsSettingsDTO $settings     設定
	 * @param string                  $esdate       出貨日 yyyyMMdd
	 * @param string                  $edelno       我方配號（空 = 新竹系統配號）
	 * @return self
	 * @throws HctLogisticsException VALIDATION 必填缺漏 / 代收金額超限
	 */
	public static function from_order( \WC_Order $order, HctLogisticsSettingsDTO $settings, string $esdate, string $edelno = '' ): self {
		$fields = [
			'epino'   => self::epino( $order ),
			'ercsig'  => self::recipient_name( $order ),
			'ertel1'  => self::recipient_phone( $order ),
			'eraddr'  => self::recipient_address( $order ),
			'ejamt'   => '1',
			'eqamt'   => (string) self::weight( $order, $settings ),
			'esdate'  => $esdate,
			'eprdct'  => '11',
			'eprdcl2' => self::product_type( $order, $settings ),
		];
		self::assert_required( $fields );

		if ('cod' === $order->get_payment_method()) {
			$amount = (int) \round( (float) $order->get_total() );
			if ($amount > self::EQMNY_MAX) {
				throw HctLogisticsException::validation( \sprintf( '代收金額 %1$d 超過新竹物流上限 %2$d', $amount, self::EQMNY_MAX ) );
			}
			$fields['eprdct'] = '41';
			$fields['eqmny']  = (string) $amount;
		}
		if ('' !== $edelno) {
			$fields['edelno'] = $edelno;
		}

		return new self( \array_merge( $fields, self::sender_fields( $settings ) ) );
	}

	/**
	 * 逆物流（R_TransData）欄位：退貨人 = 顧客、收貨人 = 商店（寄件人設定，空 = 帳號預設）
	 *
	 * 訂單編號 `R{訂單編號}`（3 個月內不可重複，String(20)）；不帶 edelno（退貨貨號由新竹依退貨區間配發，2 開頭）。
	 *
	 * @param \WC_Order               $order           訂單
	 * @param HctLogisticsSettingsDTO $settings        設定
	 * @param string                  $original_edelno 原正物流貨號（edelno2）
	 * @param string                  $esdate          出貨日 yyyyMMdd
	 * @return self
	 * @throws HctLogisticsException VALIDATION 必填缺漏
	 */
	public static function return_from( \WC_Order $order, HctLogisticsSettingsDTO $settings, string $original_edelno, string $esdate ): self {
		$fields = [
			'epino'   => \substr( 'R' . self::epino( $order ), 0, 20 ),
			'ercsig'  => self::recipient_name( $order ),
			'ertel1'  => self::recipient_phone( $order ),
			'eraddr'  => self::recipient_address( $order ),
			'ejamt'   => '1',
			'esdate'  => $esdate,
			'eprdcl2' => self::product_type( $order, $settings ),
			'edelno2' => $original_edelno,
		];
		self::assert_required( $fields );

		return new self( \array_merge( $fields, self::sender_fields( $settings ) ) );
	}

	/** @return array<string, string> API 欄位 */
	public function to_api_row(): array {
		return $this->fields;
	}

	/**
	 * 必填檢查
	 *
	 * @param array<string, string> $fields 欄位
	 * @return void
	 * @throws HctLogisticsException VALIDATION
	 */
	private static function assert_required( array $fields ): void {
		$labels = [
			'epino'  => '訂單編號',
			'ercsig' => '收件人姓名',
			'ertel1' => '收件人電話',
			'eraddr' => '收件地址',
		];
		foreach ( $labels as $key => $label ) {
			if ('' === ( $fields[ $key ] ?? '' )) {
				throw HctLogisticsException::validation( "新竹物流建單缺少{$label}" );
			}
		}
	}

	/**
	 * 寄件人 / 客代（有設定才帶，空 = 新竹帳號預設值）
	 *
	 * @param HctLogisticsSettingsDTO $settings 設定
	 * @return array<string, string>
	 */
	private static function sender_fields( HctLogisticsSettingsDTO $settings ): array {
		return \array_filter(
			[
				'escsno' => $settings->customer_code,
				'etcsig' => self::clean( $settings->sender_name, 40 ),
				'ettel1' => self::clean( $settings->sender_tel, 15 ),
				'etaddr' => self::clean( $settings->sender_address, 100 ),
			],
			static fn( string $value ): bool => '' !== $value
		);
	}

	/**
	 * 訂單編號（同一出貨日內不可重複；不可含逗號）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private static function epino( \WC_Order $order ): string {
		return self::clean( \str_replace( ',', '', (string) $order->get_order_number() ), 30 );
	}

	/**
	 * 收件人姓名（台灣慣例：姓 + 名；宅配地址優先，否則帳單）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private static function recipient_name( \WC_Order $order ): string {
		$name = \trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() );
		if ('' === $name) {
			$name = \trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		}
		return self::clean( $name, 40 );
	}

	/**
	 * 收件人電話（只保留數字與 IVR 隱碼的 #）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private static function recipient_phone( \WC_Order $order ): string {
		$phone = (string) $order->get_shipping_phone();
		if ('' === \trim( $phone )) {
			$phone = (string) $order->get_billing_phone();
		}
		return \substr( (string) \preg_replace( '/[^\d#]/', '', $phone ), 0, 20 );
	}

	/**
	 * 收件地址（縣市 + 區 + 地址；宅配地址優先）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private static function recipient_address( \WC_Order $order ): string {
		$parts = '' !== \trim( $order->get_shipping_address_1() )
		? [ $order->get_shipping_state(), $order->get_shipping_city(), $order->get_shipping_address_1(), $order->get_shipping_address_2() ]
		: [ $order->get_billing_state(), $order->get_billing_city(), $order->get_billing_address_1(), $order->get_billing_address_2() ];

		$states = \WC()->countries->get_states( 'TW' );
		if (\is_array( $states ) && isset( $states[ $parts[0] ] )) {
			$parts[0] = (string) $states[ $parts[0] ];
		}

		return self::clean( \implode( '', $parts ), 100 );
	}

	/**
	 * 重量（kg，各商品重量 × 數量加總後無條件進位；無重量資料 → 設定預設值）
	 *
	 * @param \WC_Order               $order    訂單
	 * @param HctLogisticsSettingsDTO $settings 設定
	 * @return int
	 */
	private static function weight( \WC_Order $order, HctLogisticsSettingsDTO $settings ): int {
		$total = 0.0;
		foreach ( $order->get_items() as $item ) {
			if (!$item instanceof \WC_Order_Item_Product) {
				continue;
			}
			$product = $item->get_product();
			if (!$product instanceof \WC_Product || !\is_numeric( $product->get_weight() )) {
				continue;
			}
			$total += (float) \wc_get_weight( (float) $product->get_weight(), 'kg' ) * $item->get_quantity();
		}

		$weight = (int) \ceil( $total );
		return $weight > 0 ? \min( $weight, self::WEIGHT_MAX ) : (int) $settings->default_weight;
	}

	/**
	 * 商品種類（結帳選用 → 設定預設）
	 *
	 * @param \WC_Order               $order    訂單
	 * @param HctLogisticsSettingsDTO $settings 設定
	 * @return string
	 */
	private static function product_type( \WC_Order $order, HctLogisticsSettingsDTO $settings ): string {
		$chosen = (string) $order->get_meta( '_pc_hct_logistics_product_type' );
		return null !== HctProductType::tryFrom( $chosen ) ? $chosen : $settings->default_product_type;
	}

	/**
	 * 去除新竹禁用字元 & " < > '、空白正規化並截長度
	 *
	 * @param string $value 原值
	 * @param int    $max   最大字數
	 * @return string
	 */
	private static function clean( string $value, int $max ): string {
		$value = \str_replace( [ '&', '"', '<', '>', "'" ], '', $value );
		$value = \trim( (string) \preg_replace( '/\s+/u', ' ', $value ) );
		return \mb_substr( $value, 0, $max );
	}
}
