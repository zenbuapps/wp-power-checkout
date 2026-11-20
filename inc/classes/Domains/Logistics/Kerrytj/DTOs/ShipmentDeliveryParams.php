<?php

declare(strict_types=1);

namespace J7\PowerCheckout\Domains\Logistics\Kerrytj\DTOs;

use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjBlnKind;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Enums\KerrytjTemperature;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Exceptions\KerrytjLogisticsException;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjBln;
use J7\PowerCheckout\Domains\Logistics\Kerrytj\Shared\Helpers\KerrytjFields;

/**
 * 嘉里大榮 ShipmentDelivery / UpdateShipmentDelivery 單筆 34 欄（由訂單組裝；必填與合法值顯式驗證）
 *
 * - 欄位一律字串、選填送 ""（照官方範例，不省略鍵）；順序 = 規格書 §3
 * - 姓名截 12 字（伺服器上限實測 30、標籤約可印 15 字；zenbu 測試機實測），全名移到備註；電話 > 15 / 地址 > 60 → 拒絕（截斷會送錯）
 * - 郵遞區號 3 碼：訂單郵遞區號優先，否則以縣市 + 鄉鎮市區查表；查不到 → 拒絕（送錯郵區 = 送錯到著站）
 * - 溫層須與單號格式一致（常溫 11 碼 / 低溫 10 碼）；CON 出貨單號常溫 ≤ 12、低溫 ≤ 10，放不下 → 留空並寫備註（不截斷成對不回的編號）
 * - 代收（AC）/ 報值 / 付款別（PaymentType）一律空：不替站主決定（COD 由 provider 前置擋下）
 * - 標籤 16 欄 / 託運明細 26 欄一律由建單時這一份 34 欄逐字取（label_fields / detail_fields）
 *
 * @see .claude/skills/kerrytj-logistics/references/api-reference.md §3、§7
 */
final class ShipmentDeliveryParams {

	/** @var int 姓名上限 */
	public const NAME_MAX = 12;

	/** @var int 電話上限 */
	private const PHONE_MAX = 15;

	/** @var int 地址上限 */
	private const ADDRESS_MAX = 60;

	/** @var int 備註上限 */
	private const REMARK_MAX = 60;

	/** @var array<int, string> 標籤 16 欄（§7.1） */
	private const LABEL_KEYS = [ 'ShipDate', 'CustomerNo', 'Consignee', 'ConsigneePost', 'ConsigneeAdd', 'ConsigneePhone', 'BLN', 'Shipper', 'ShipperPost', 'ShipperAdd', 'ShipperTel', 'Piece', 'CON', 'AC', 'Remark', 'Temperature' ];

	/** @var array<int, string> 託運明細額外 10 欄（§7.2） */
	private const DETAIL_EXTRA_KEYS = [ 'Weight', 'Volume', 'ETA', 'DeclaredValue', 'SpecialService', 'StationEnable', 'Receipt', 'HolidayId', 'PaymentType', 'ETP' ];

	/**
	 * Constructor
	 *
	 * @param array<string, string> $fields 34 欄
	 */
	private function __construct( private readonly array $fields ) {
	}

	/**
	 * 由訂單組 34 欄
	 *
	 * @param \WC_Order                   $order       訂單
	 * @param KerrytjLogisticsSettingsDTO $settings    設定
	 * @param KerrytjTemperature          $temperature 溫層
	 * @param string                      $ship_date   出貨日 yyyyMMdd
	 * @param string                      $bln         託運單號
	 * @return self
	 * @throws KerrytjLogisticsException VALIDATION 欄位缺漏 / 超長 / 溫層與單號不符
	 */
	public static function from_order( \WC_Order $order, KerrytjLogisticsSettingsDTO $settings, KerrytjTemperature $temperature, string $ship_date, string $bln ): self {
		$sender = self::sender( $settings );

		$kind = KerrytjBln::kind_of( $bln );
		if (null === $kind) {
			throw KerrytjLogisticsException::validation( "嘉里大榮託運單號 {$bln} 格式不符" );
		}
		if ($kind !== $temperature->bln_kind()) {
			throw KerrytjLogisticsException::validation( \sprintf( '嘉里大榮託運單號 %1$s 屬於%2$s號段，與溫層（%3$s）不符', $bln, $kind->label(), $temperature->label() ) );
		}

		$recipient = Recipient::from_order( $order );
		$full_name = $recipient->name;
		if ('' === $full_name) {
			throw KerrytjLogisticsException::validation( '嘉里大榮建單缺少收件人姓名' );
		}
		$consignee = KerrytjFields::cut( $full_name, self::NAME_MAX );
		$phone     = self::require_within( '收件人電話', $recipient->phone, self::PHONE_MAX );
		$address   = self::require_within( '收件地址', $recipient->address, self::ADDRESS_MAX );
		if ('' === $recipient->zip3) {
			throw KerrytjLogisticsException::validation( '無法判斷收件地址的 3 碼郵遞區號，請確認郵遞區號或縣市與鄉鎮市區' );
		}

		$order_number = \trim( (string) $order->get_order_number() );
		$con_max      = KerrytjBlnKind::AMBIENT === $kind ? 12 : 10;
		$con          = KerrytjFields::length( $order_number ) <= $con_max ? $order_number : '';
		$remarks      = [];
		if ('' === $con && '' !== $order_number) {
			$remarks[] = "訂單 {$order_number}";
		}
		if ($consignee !== $full_name) {
			$remarks[] = "收件人 {$full_name}";
		}

		return new self(
			[
				'ShipDate'       => $ship_date,
				'CustomerNo'     => $settings->customer_no,
				'Consignee'      => $consignee,
				'ConsigneePost'  => $recipient->zip3,
				'ConsigneeAdd'   => $address,
				'ConsigneePhone' => $phone,
				'BLN'            => $bln,
				'Weight'         => '',
				'Volume'         => '',
				'Shipper'        => $sender['name'],
				'ShipperPost'    => $sender['zip3'],
				'ShipperAdd'     => $sender['address'],
				'ShipperTel'     => $sender['phone'],
				'CON'            => $con,
				'AC'             => '',
				'PickupTime'     => '',
				'ETA'            => '',
				'Remark'         => KerrytjFields::cut( \implode( '；', $remarks ), self::REMARK_MAX ),
				'Temperature'    => $temperature->value,
				'Piece'          => '1',
				'DeclaredValue'  => '',
				'SpecialService' => '',
				'StationEnable'  => '',
				'Receipt'        => '',
				'HolidayId'      => '',
				'MemberId'       => '',
				'BoxNumber'      => '',
				'IsValuable'     => '',
				'CityType'       => '',
				'Specification'  => '',
				'BoxSize'        => '',
				'DailyDelyId'    => '',
				'PaymentType'    => '',
				'ETP'            => '',
			]
		);
	}

	/** @return array<string, string> 34 欄 */
	public function to_api_row(): array {
		return $this->fields;
	}

	/**
	 * 34 欄 → 標籤 16 欄（GetLabelPdf / GetLabelPdfA4）
	 *
	 * @param array<string, string> $row 34 欄
	 * @return array<string, string>
	 */
	public static function label_fields( array $row ): array {
		return self::pick( $row, self::LABEL_KEYS );
	}

	/**
	 * 34 欄 → 託運明細 26 欄（GetDetailPdf）
	 *
	 * @param array<string, string> $row 34 欄
	 * @return array<string, string>
	 */
	public static function detail_fields( array $row ): array {
		return self::pick( $row, \array_merge( self::LABEL_KEYS, self::DETAIL_EXTRA_KEYS ) );
	}

	/**
	 * 寄件人（商店）設定 → 欄位；缺漏列出欄位名（不回值）
	 *
	 * @param KerrytjLogisticsSettingsDTO $settings 設定
	 * @return array{name: string, phone: string, zip3: string, address: string}
	 * @throws KerrytjLogisticsException VALIDATION 寄件人未設定 / 超長
	 */
	public static function sender( KerrytjLogisticsSettingsDTO $settings ): array {
		$missing = $settings->missing_sender_fields();
		$zip3    = KerrytjFields::zip3( $settings->sender_postcode );
		if ('' === $zip3 && !\in_array( 'sender_postcode', $missing, true )) {
			$missing[] = 'sender_postcode';
		}
		if ([] !== $missing) {
			throw KerrytjLogisticsException::validation( '嘉里大榮寄件人資料未設定：' . \implode( '、', $missing ) );
		}
		return [
			'name'    => KerrytjFields::cut( $settings->sender_name, self::NAME_MAX ),
			'phone'   => self::require_within( '寄件人電話', KerrytjFields::phone( $settings->sender_phone ), self::PHONE_MAX ),
			'zip3'    => $zip3,
			'address' => self::require_within( '寄件人地址', \trim( $settings->sender_address ), self::ADDRESS_MAX ),
		];
	}

	/**
	 * 必填且不超長（超長拒絕不截斷）
	 *
	 * @param string $label 欄位中文
	 * @param string $value 值
	 * @param int    $max   上限
	 * @return string
	 * @throws KerrytjLogisticsException VALIDATION
	 */
	public static function require_within( string $label, string $value, int $max ): string {
		if ('' === $value) {
			throw KerrytjLogisticsException::validation( "嘉里大榮{$label}為必填" );
		}
		if (KerrytjFields::length( $value ) > $max) {
			throw KerrytjLogisticsException::validation( "嘉里大榮{$label}超過上限 {$max} 字" );
		}
		return $value;
	}

	/**
	 * 依鍵挑出欄位（缺鍵補空字串）
	 *
	 * @param array<string, string> $row  欄位
	 * @param array<int, string>    $keys 鍵
	 * @return array<string, string>
	 */
	private static function pick( array $row, array $keys ): array {
		$out = [];
		foreach ( $keys as $key ) {
			$out[ $key ] = (string) ( $row[ $key ] ?? '' );
		}
		return $out;
	}
}
