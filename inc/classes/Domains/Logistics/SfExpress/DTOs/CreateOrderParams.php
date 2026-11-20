<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\SfExpress\DTOs;

use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Exceptions\SfExpressException;
use J7\PowerCheckout\Domains\Logistics\SfExpress\Shared\Helpers\SfRegion;

/**
 * `IUOP_CREATE_ORDER` 明文 body（台灣寄件 → 台灣收件宅配）
 *
 * 欄位決策（對齊 zenbu `sf-order-payload.ts` 與 skill `iuop-create-order.md` / `taiwan-rules.md`）：
 *  - customerOrderNo = 訂單編號，**不加亂數**：逾時重送時順豐才會回 123052 而不是配出第二張運單
 *  - erpCode / erpName 固定 `0000`（自建系統；不填平台品牌）
 *  - declaredCurrency `NTD`（順豐幣種表沒有 TWD）；申報價值 = 商品小計（不含運費），單一包裹單一品項
 *  - paymentInfo：payMethod 1 寄方付 + 月結卡號、taxPayMethod 2（島內件無稅金）
 *  - pickupType 0（自寄 / 自行聯繫順豐，不做 API 預約上門）
 *  - senderInfo.cargoType 台灣始發必填（缺 → 123057）；公司件有統編才帶 certType 009
 *  - 電話：phoneAreaCode `886` + 0 開頭號碼（`+886` / `886` 國碼開頭換回 0）
 *  - 區劃：SfRegion 換算不出 → VALIDATION（不送猜測值）
 *  - 商品有重量才送 parcelTotalWeight（kg）；沒有就交順豐秤重（送 0 會被當成真的 0 公斤）
 *  - 貨到付款不支援（順豐 COD 需月結開通權限且金額以到件地幣種）→ VALIDATION
 *
 * 驗證失敗一律在打 API 前以 SfExpressException::validation 擋下，一次列出所有缺漏。
 */
final class CreateOrderParams {

	/** @var string 台灣國家碼 */
	private const COUNTRY = 'TW';

	/** @var string 台灣電話國碼（純數字） */
	private const PHONE_AREA_CODE = '886';

	/** @var string 新台幣（順豐幣種代碼） */
	private const CURRENCY = 'NTD';

	/** @var string 自建系統 ERP 代碼 */
	private const ERP_CODE = '0000';

	/** @var string 證件類型：統編號（適用台灣） */
	private const CERT_TYPE_TAX_ID = '009';

	/**
	 * Constructor
	 *
	 * @param array<string, mixed> $body           明文 body
	 * @param string               $receiver_phone 收件手機（正規化後；123052 反查比對用）
	 */
	private function __construct(
		public readonly array $body,
		public readonly string $receiver_phone,
	) {
	}

	/**
	 * 由訂單 + 設定組裝（不打 API）
	 *
	 * @param \WC_Order            $order    訂單
	 * @param SfExpressSettingsDTO $settings 設定
	 * @return self
	 * @throws SfExpressException VALIDATION 欄位缺漏 / 區劃換算失敗 / 貨到付款
	 */
	public static function from_order( \WC_Order $order, SfExpressSettingsDTO $settings ): self {
		$missing = \array_merge( $settings->missing_api_credentials(), $settings->missing_shipment_settings(), $settings->missing_sender() );
		if ([] !== $missing) {
			throw SfExpressException::validation( '請先於順豐設定頁填寫：' . \implode( '、', $missing ) );
		}
		if ('cod' === $order->get_payment_method()) {
			throw SfExpressException::validation( '順豐速運不支援貨到付款，請改用其他物流或改為不代收出貨' );
		}

		$sender_region = SfRegion::resolve( $settings->sender_postcode, '', '', $settings->sender_address );
		if (null === $sender_region) {
			throw SfExpressException::validation( '無法將寄件地址換算為順豐區劃，請確認寄件郵遞區號與寄件地址開頭的縣市區' );
		}

		$name   = \trim( $order->get_shipping_last_name() . $order->get_shipping_first_name() );
		$name   = '' !== $name ? $name : \trim( $order->get_billing_last_name() . $order->get_billing_first_name() );
		$phone  = self::normalize_phone( '' !== $order->get_shipping_phone() ? $order->get_shipping_phone() : $order->get_billing_phone() );
		$state  = self::state_name( $order );
		$city   = $order->get_shipping_city();
		$street = \trim( $order->get_shipping_address_1() . $order->get_shipping_address_2() );
		$errors = [];
		if ('' === $name) {
			$errors[] = '收件人姓名';
		}
		if ('' === $phone) {
			$errors[] = '收件人電話';
		}
		if ('' === $street) {
			$errors[] = '收件地址';
		}
		if ([] !== $errors) {
			throw SfExpressException::validation( '訂單缺少：' . \implode( '、', $errors ) );
		}

		$receiver_region = SfRegion::resolve( $order->get_shipping_postcode(), $state, $city, $street );
		if (null === $receiver_region) {
			throw SfExpressException::validation( \sprintf( '無法將收件地址「%1$s%2$s」換算為順豐區劃，請確認縣市與鄉鎮市區', $state, $city ) );
		}

		$postcode = \preg_replace( '/\D/', '', $order->get_shipping_postcode() );
		$address  = self::compose_address( $receiver_region['city'], $receiver_region['district'], $state . $city . $street );

		$sender = [
			'contact'       => $settings->sender_name,
			'country'       => self::COUNTRY,
			'postCode'      => $settings->sender_postcode,
			'regionFirst'   => $sender_region['region_first'],
			'regionSecond'  => $sender_region['region_second'],
			'address'       => self::compose_address( $sender_region['city'], $sender_region['district'], $settings->sender_address ),
			'phoneAreaCode' => self::PHONE_AREA_CODE,
			'phoneNo'       => self::normalize_phone( $settings->sender_mobile ),
			'cargoType'     => $settings->sender_cargo_type,
		];
		if ('2' === $settings->sender_cargo_type && '' !== $settings->sender_tax_id) {
			$sender['certType']   = self::CERT_TYPE_TAX_ID;
			$sender['certCardNo'] = $settings->sender_tax_id;
		}
		if ('' !== $settings->sender_company) {
			$sender['company'] = $settings->sender_company;
		}
		$tel = self::normalize_phone( $settings->sender_tel );
		if ('' !== $tel) {
			$sender['telAreaCode'] = self::PHONE_AREA_CODE;
			$sender['telNo']       = $tel;
		}

		$body = [
			'customerCode'     => $settings->customer_code,
			'customerOrderNo'  => (string) $order->get_order_number(),
			'orderOperateType' => '1',
			'erpCode'          => self::ERP_CODE,
			'erpName'          => self::ERP_CODE,
			'interProductCode' => $settings->product_code,
			'parcelQuantity'   => 1,
			'declaredCurrency' => self::CURRENCY,
			'pickupType'       => '0',
			'paymentInfo'      => [
				'payMethod'    => '1',
				'payMonthCard' => $settings->month_card,
				'taxPayMethod' => '2',
			],
			'senderInfo'       => $sender,
			'receiverInfo'     => [
				'contact'       => $name,
				'country'       => self::COUNTRY,
				'postCode'      => '' !== $postcode ? $postcode : $receiver_region['zip3'],
				'regionFirst'   => $receiver_region['region_first'],
				'regionSecond'  => $receiver_region['region_second'],
				'address'       => $address,
				'phoneAreaCode' => self::PHONE_AREA_CODE,
				'phoneNo'       => $phone,
				'cargoType'     => '1',
			],
			'parcelInfoList'   => [
				[
					// 官方錯誤碼 123036「品名不能包含 _」
					'name'          => \str_replace( '_', ' ', $settings->parcel_name ),
					'unit'          => '件',
					'amount'        => (int) \round( (float) $order->get_subtotal() ),
					'quantity'      => 1,
					'originCountry' => self::COUNTRY,
				],
			],
		];

		$weight = self::total_weight_kg( $order );
		if (null !== $weight) {
			$body['parcelTotalWeight'] = $weight;
			$body['parcelWeightUnit']  = 'KG';
		}

		return new self( $body, $phone );
	}

	/**
	 * 台灣電話正規化：只留數字；`886` 國碼開頭（長度 11 / 12）換回 0 開頭
	 *
	 * @param string $raw 原始電話
	 * @return string
	 */
	public static function normalize_phone( string $raw ): string {
		$digits = (string) \preg_replace( '/\D/', '', $raw );
		if (\str_starts_with( $digits, '886' ) && \in_array( \strlen( $digits ), [ 11, 12 ], true )) {
			return '0' . \substr( $digits, 3 );
		}
		return $digits;
	}

	/**
	 * 組地址：已以縣市區開頭就原樣，否則補上（印在面單上的是查表命中的縣市區）
	 *
	 * @param string $city     縣市（臺）
	 * @param string $district 鄉鎮市區
	 * @param string $address  原地址
	 * @return string
	 */
	private static function compose_address( string $city, string $district, string $address ): string {
		$address = \str_replace( '台', '臺', (string) \preg_replace( '/\s+/u', '', $address ) );
		$address = (string) \preg_replace( '/^\d{3,6}/', '', $address );
		return \str_starts_with( $address, $city . $district ) ? $address : $city . $district . $address;
	}

	/**
	 * WC shipping_state 轉中文縣市名（state 可能是代碼）
	 *
	 * @param \WC_Order $order 訂單
	 * @return string
	 */
	private static function state_name( \WC_Order $order ): string {
		$state = $order->get_shipping_state();
		if ('' === $state) {
			return '';
		}
		$states = \WC()->countries->get_states( self::COUNTRY );
		return \is_array( $states ) && isset( $states[ $state ] ) && \is_string( $states[ $state ] ) ? $states[ $state ] : $state;
	}

	/**
	 * 商品總重（kg）；任一商品缺重量 → null（交順豐秤重）
	 *
	 * @param \WC_Order $order 訂單
	 * @return float|null
	 */
	private static function total_weight_kg( \WC_Order $order ): ?float {
		$total = 0.0;
		$items = $order->get_items();
		if ([] === $items) {
			return null;
		}
		foreach ( $items as $item ) {
			if (!$item instanceof \WC_Order_Item_Product) {
				continue;
			}
			$product = $item->get_product();
			$weight  = $product instanceof \WC_Product ? (float) $product->get_weight() : 0.0;
			if ($weight <= 0) {
				return null;
			}
			$total += (float) \wc_get_weight( $weight, 'kg' ) * $item->get_quantity();
		}
		return $total > 0 ? \round( $total, 2 ) : null;
	}
}
