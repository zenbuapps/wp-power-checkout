<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Tcat\DTOs;

use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Enums\TcatSpec;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Enums\TcatThermosphere;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Exceptions\TcatLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatFieldRules;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatLogisticsDates;
use J7\PowerCheckout\Domains\Logistics\Tcat\Shared\Helpers\TcatLogisticsMetaKeys;

/**
 * PrintOBT（宅配）單筆訂單參數；所有欄位在送出前於本地顯式驗證（R16），失敗拋 VALIDATION
 *
 * 由訂單組裝：
 *  - 收件人：運送地址優先、否則帳單地址；姓名「姓 + 名」；電話 09 開頭 10 碼視為手機，其餘為市話（擇一必填，E027）
 *  - 寄件人：設定頁寄件人資料 + 六碼郵號（擇一必填，E028）
 *  - 溫層：結帳選用的運送方式（_pc_tcat_logistics_thermosphere）優先，否則設定預設；低溫不可 150cm（E020）
 *  - 代收：付款方式為 cod 才代收，金額 1~100000（E035）；> 20000 時報值 = 代收（E041）
 *  - 商品名稱：第一個商品名（含 19 個禁用字串或空白時改用設定的替代名）；上限 20 字
 *  - 出貨日今天、配達日隔天（遇週日順延；配達日 > 出貨日，E090）
 *
 * @see .claude/skills/tcat-logistics/references/api-reference.md §2.1、§14
 */
final class PrintObtParams {

	/** @var int 宅配代收上限 */
	public const COLLECTION_MAX = 100000;

	/** @var int 報值門檻（代收 > 此值須報值） */
	public const DECLARE_THRESHOLD = 20000;

	/**
	 * Constructor
	 *
	 * @param string $order_id          OrderId
	 * @param string $thermosphere      溫層
	 * @param string $spec              規格
	 * @param string $recipient_name    收件人姓名
	 * @param string $recipient_tel     收件人電話
	 * @param string $recipient_mobile  收件人手機
	 * @param string $recipient_address 收件人地址
	 * @param string $sender_name       寄件人姓名
	 * @param string $sender_tel        寄件人電話
	 * @param string $sender_mobile     寄件人手機
	 * @param string $sender_zip_code   寄件人六碼郵號
	 * @param string $sender_address    寄件人地址
	 * @param string $shipment_date     出貨日 yyyyMMdd
	 * @param string $delivery_date     希望配達日 yyyyMMdd
	 * @param string $delivery_time     希望配達時段
	 * @param int    $collection_amount 代收金額（0 = 不代收）
	 * @param string $product_type_id   商品類別
	 * @param string $product_name      商品名稱
	 */
	private function __construct(
		public readonly string $order_id,
		public readonly string $thermosphere,
		public readonly string $spec,
		public readonly string $recipient_name,
		public readonly string $recipient_tel,
		public readonly string $recipient_mobile,
		public readonly string $recipient_address,
		public readonly string $sender_name,
		public readonly string $sender_tel,
		public readonly string $sender_mobile,
		public readonly string $sender_zip_code,
		public readonly string $sender_address,
		public readonly string $shipment_date,
		public readonly string $delivery_date,
		public readonly string $delivery_time,
		public readonly int $collection_amount,
		public readonly string $product_type_id,
		public readonly string $product_name,
	) {
	}

	/**
	 * 由訂單組裝並驗證
	 *
	 * @param \WC_Order                $order           訂單
	 * @param TcatLogisticsSettingsDTO $settings        設定
	 * @param string                   $sender_zip_code 寄件人六碼郵號
	 * @param \DateTimeImmutable       $now             現在
	 * @return self
	 * @throws TcatLogisticsException 欄位不合法（VALIDATION）
	 */
	public static function from_order( \WC_Order $order, TcatLogisticsSettingsDTO $settings, string $sender_zip_code, \DateTimeImmutable $now ): self {
		$recipient = self::customer_of( $order );
		self::assert_sender( $settings );

		$thermosphere = self::thermosphere_of( $order, $settings );
		if (TcatThermosphere::from( $thermosphere )->is_cold() && TcatSpec::CM150->value === $settings->default_spec) {
			throw TcatLogisticsException::validation( '低溫（冷藏 / 冷凍）包裹不可為 150cm，請調整黑貓宅急便預設規格' );
		}
		if (6 !== \strlen( $sender_zip_code )) {
			throw TcatLogisticsException::validation( '寄件人郵號須為六碼' );
		}

		$order_id = TcatFieldRules::order_id( (string) $order->get_order_number() );
		if ('' === $order_id) {
			throw TcatLogisticsException::validation( '訂單編號不可為空白' );
		}

		$collection = 0;
		if ('cod' === $order->get_payment_method()) {
			$collection = (int) \round( (float) $order->get_total() );
			if ($collection < 1 || $collection > self::COLLECTION_MAX) {
				throw TcatLogisticsException::validation( \sprintf( '黑貓宅配代收金額須為 1～%d 元', self::COLLECTION_MAX ) );
			}
		}

		$shipment_date = TcatLogisticsDates::shipment_date( $now );

		return new self(
			order_id: $order_id,
			thermosphere: $thermosphere,
			spec: $settings->default_spec,
			recipient_name: $recipient['name'],
			recipient_tel: $recipient['tel'],
			recipient_mobile: $recipient['mobile'],
			recipient_address: $recipient['address'],
			sender_name: $settings->sender_name,
			sender_tel: $settings->sender_tel,
			sender_mobile: $settings->sender_mobile,
			sender_zip_code: $sender_zip_code,
			sender_address: $settings->sender_address,
			shipment_date: $shipment_date,
			delivery_date: TcatLogisticsDates::delivery_date( $shipment_date ),
			delivery_time: $settings->delivery_time,
			collection_amount: $collection,
			product_type_id: $settings->product_type_id,
			product_name: self::product_name_of( $order, $settings ),
		);
	}

	/**
	 * 逆物流（ReserveAdd）Order 物件：收件人 = 商店（寄件人設定）、寄件人 = 顧客
	 *
	 * @param \WC_Order                $order     訂單
	 * @param TcatLogisticsSettingsDTO $settings  設定
	 * @param string                   $take_date 取件日 yyyyMMdd（已驗證）
	 * @return array<string, string>
	 * @throws TcatLogisticsException 欄位不合法（VALIDATION）
	 */
	public static function reserve_order_from( \WC_Order $order, TcatLogisticsSettingsDTO $settings, string $take_date ): array {
		$customer = self::customer_of( $order );
		self::assert_sender( $settings );

		$reserve = [
			'OBTNumber'        => '',
			'OrderId'          => TcatFieldRules::order_id( 'R' . $order->get_order_number() ),
			'Thermosphere'     => self::thermosphere_of( $order, $settings ),
			'Spec'             => $settings->default_spec,
			'RecipientName'    => $settings->sender_name,
			'RecipientTel'     => $settings->sender_tel,
			'RecipientMobile'  => $settings->sender_mobile,
			'RecipientAddress' => $settings->sender_address,
			'SenderName'       => $customer['name'],
			'SenderTel'        => $customer['tel'],
			'SenderMobile'     => $customer['mobile'],
			'SenderAddress'    => $customer['address'],
			'TakeDate'         => $take_date,
			'DeliveryTime'     => $settings->delivery_time,
			'IsFreight'        => 'N',
			'ProductName'      => self::product_name_of( $order, $settings ),
			'Memo'             => '',
		];
		if ('' !== $settings->sender_zip_code) {
			$reserve['RecipientZipCode'] = $settings->sender_zip_code;
		}
		return $reserve;
	}

	/**
	 * PrintOBT Orders[] 元素（金額欄位為 Int）
	 *
	 * @return array<string, string|int>
	 */
	public function to_api_order(): array {
		$is_collection = $this->collection_amount > 0;
		$is_declare    = $this->collection_amount > self::DECLARE_THRESHOLD;

		return [
			'OBTNumber'        => '',
			'OrderId'          => $this->order_id,
			'Thermosphere'     => $this->thermosphere,
			'Spec'             => $this->spec,
			'ReceiptLocation'  => '01',
			'ReceiptStationNo' => '',
			'RecipientName'    => $this->recipient_name,
			'RecipientTel'     => $this->recipient_tel,
			'RecipientMobile'  => $this->recipient_mobile,
			'RecipientAddress' => $this->recipient_address,
			'SenderName'       => $this->sender_name,
			'SenderTel'        => $this->sender_tel,
			'SenderMobile'     => $this->sender_mobile,
			'SenderZipCode'    => $this->sender_zip_code,
			'SenderAddress'    => $this->sender_address,
			'ShipmentDate'     => $this->shipment_date,
			'DeliveryDate'     => $this->delivery_date,
			'DeliveryTime'     => $this->delivery_time,
			'IsFreight'        => 'N',
			'IsCollection'     => $is_collection ? 'Y' : 'N',
			'CollectionAmount' => $this->collection_amount,
			'IsSwipe'          => 'N',
			'IsMobilePay'      => 'N',
			'IsDeclare'        => $is_declare ? 'Y' : 'N',
			'DeclareAmount'    => $is_declare ? $this->collection_amount : 0,
			'ProductTypeId'    => $this->product_type_id,
			'ProductName'      => $this->product_name,
			'Memo'             => '',
		];
	}

	/**
	 * 顧客（收件人）姓名 / 電話 / 地址：運送地址優先，否則帳單地址
	 *
	 * @param \WC_Order $order 訂單
	 * @return array{name: string, tel: string, mobile: string, address: string}
	 * @throws TcatLogisticsException 欄位缺漏（VALIDATION）
	 */
	private static function customer_of( \WC_Order $order ): array {
		$use_shipping = '' !== \trim( $order->get_shipping_address_1() );

		$name = $use_shipping
		? $order->get_shipping_last_name() . $order->get_shipping_first_name()
		: $order->get_billing_last_name() . $order->get_billing_first_name();
		$name = \mb_substr( \trim( $name ), 0, 30 );
		if ('' === $name) {
			throw TcatLogisticsException::validation( '收件人姓名必填' );
		}

		$phone = \trim( $order->get_shipping_phone() );
		if ('' === $phone) {
			$phone = \trim( $order->get_billing_phone() );
		}
		$split = TcatFieldRules::split_phone( $phone );
		if ('' === $split['tel'] && '' === $split['mobile']) {
			throw TcatLogisticsException::validation( '收件人電話或手機必填（黑貓須擇一）' );
		}

		$address = $use_shipping
		? self::state_name( $order->get_shipping_country(), $order->get_shipping_state() ) . $order->get_shipping_city() . $order->get_shipping_address_1() . $order->get_shipping_address_2()
		: self::state_name( $order->get_billing_country(), $order->get_billing_state() ) . $order->get_billing_city() . $order->get_billing_address_1() . $order->get_billing_address_2();
		$address = \mb_substr( \trim( $address ), 0, 120 );
		if ('' === $address) {
			throw TcatLogisticsException::validation( '收件人地址必填' );
		}

		return [
			'name'    => $name,
			'tel'     => $split['tel'],
			'mobile'  => $split['mobile'],
			'address' => $address,
		];
	}

	/**
	 * 寄件人設定齊全檢查
	 *
	 * @param TcatLogisticsSettingsDTO $settings 設定
	 * @return void
	 * @throws TcatLogisticsException 缺漏（VALIDATION）
	 */
	private static function assert_sender( TcatLogisticsSettingsDTO $settings ): void {
		if ('' === $settings->sender_name || '' === $settings->sender_address) {
			throw TcatLogisticsException::validation( '請先於黑貓宅急便設定頁填寫寄件人姓名與地址' );
		}
		if ('' === $settings->sender_tel && '' === $settings->sender_mobile) {
			throw TcatLogisticsException::validation( '寄件人電話或手機必填（黑貓須擇一）' );
		}
	}

	/**
	 * 溫層：訂單 meta（結帳選用）優先，否則設定預設
	 *
	 * @param \WC_Order                $order    訂單
	 * @param TcatLogisticsSettingsDTO $settings 設定
	 * @return string
	 */
	private static function thermosphere_of( \WC_Order $order, TcatLogisticsSettingsDTO $settings ): string {
		$chosen = ( new TcatLogisticsMetaKeys( $order ) )->get_thermosphere();
		return null !== TcatThermosphere::tryFrom( $chosen ) ? $chosen : $settings->default_thermosphere;
	}

	/**
	 * 商品名稱：第一個商品名，含禁用字串或空白時改用設定的替代名；上限 20 字
	 *
	 * @param \WC_Order                $order    訂單
	 * @param TcatLogisticsSettingsDTO $settings 設定
	 * @return string
	 */
	private static function product_name_of( \WC_Order $order, TcatLogisticsSettingsDTO $settings ): string {
		$name = '';
		foreach ( $order->get_items() as $item ) {
			$name = \trim( \wp_strip_all_tags( $item->get_name() ) );
			break;
		}
		if ('' === $name || TcatFieldRules::has_forbidden_product_word( $name )) {
			$name = $settings->product_name;
		}
		return \mb_substr( $name, 0, TcatFieldRules::PRODUCT_NAME_MAX );
	}

	/**
	 * 州 / 縣市代碼 → 名稱（WC states；查不到回原值）
	 *
	 * @param string $country 國家
	 * @param string $state   州代碼
	 * @return string
	 */
	private static function state_name( string $country, string $state ): string {
		if ('' === $state) {
			return '';
		}
		$states = \WC()->countries->get_states( $country );
		return \is_array( $states ) && isset( $states[ $state ] ) ? (string) $states[ $state ] : $state;
	}
}
